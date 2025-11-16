<?php

/**
 * AlertUtil.php
 *
 * Extending the built in logging to add an event logger function
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2019 KanREN, Inc.
 * @author     Heath Barnhart <hbarnhart@kanren.net>
 */

namespace LibreNMS\Alert;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\User;
use App\Models\Eventlog;
use DeviceCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use LibreNMS\Alerting\QueryBuilderParser;
use LibreNMS\Enum\MaintenanceStatus;
use PHPMailer\PHPMailer\PHPMailer;

class AlertUtil
{
    /**
     * Get the rule_id for a specific alert
     *
     * @param  int  $alert_id
     * @return mixed|null
     */
    private static function getRuleId($alert_id)
    {
        $query = 'SELECT `rule_id` FROM `alerts` WHERE `id`=?';

        return dbFetchCell($query, [$alert_id]);
    }

    /**
     * Get the transport for a given alert_id
     *
     * @param  int  $alert_id
     * @return array
     */
    public static function getAlertTransports($alert_id)
    {
        $query = "SELECT b.transport_id, b.transport_type, b.transport_name FROM alert_transport_map AS a LEFT JOIN alert_transports AS b ON b.transport_id=a.transport_or_group_id WHERE a.target_type='single' AND a.rule_id=? UNION DISTINCT SELECT d.transport_id, d.transport_type, d.transport_name FROM alert_transport_map AS a LEFT JOIN alert_transport_groups AS b ON a.transport_or_group_id=b.transport_group_id LEFT JOIN transport_group_transport AS c ON b.transport_group_id=c.transport_group_id LEFT JOIN alert_transports AS d ON c.transport_id=d.transport_id WHERE a.target_type='group' AND a.rule_id=?";
        $rule_id = self::getRuleId($alert_id);

        return dbFetchRows($query, [$rule_id, $rule_id]);
    }

    /**
     * Returns the default transports
     *
     * @return array
     */
    public static function getDefaultAlertTransports()
    {
        $query = 'SELECT transport_id, transport_type, transport_name FROM alert_transports WHERE is_default=true';

        return dbFetchRows($query);
    }

    /**
     * Find contacts for alert
     *
     * @param  array  $results  Rule-Result
     * @return array
     */
    public static function getContacts($results)
    {
        if (empty($results)) {
            return [];
        }

        if (LibrenmsConfig::get('alert.default_only') === true || LibrenmsConfig::get('alerts.email.default_only') === true) {
            $email = LibrenmsConfig::get('alert.default_mail', LibrenmsConfig::get('alerts.email.default'));

            return $email ? [$email => ''] : [];
        }

        $contacts = [];

        if (LibrenmsConfig::get('alert.syscontact')) {
            $contacts = array_merge($contacts, self::findContactsSysContact($results));
        }

        if (LibrenmsConfig::get('alert.users')) {
            $contacts = array_merge($contacts, self::findContactsOwners($results));
        }

        $roles = LibrenmsConfig::get('alert.globals')
            ? ['admin', 'global-read']
            : (LibrenmsConfig::get('alert.admins') ? ['admin'] : []);
        if ($roles) {
            $contacts = array_merge($contacts, self::findContactsRoles($roles));
        }

        $tmp_contacts = [];
        foreach ($contacts as $email => $name) {
            if (strstr($email, ',')) {
                $split_contacts = preg_split('/[,\s]+/', $email);
                foreach ($split_contacts as $split_email) {
                    if (! empty($split_email)) {
                        $tmp_contacts[$split_email] = $name;
                    }
                }
            } else {
                $tmp_contacts[$email] = $name;
            }
        }

        if (! empty($tmp_contacts)) {
            // Validate contacts so we can fall back to default if configured.
            $mail = new PHPMailer();
            foreach ($tmp_contacts as $tmp_email => $tmp_name) {
                if ($mail->validateAddress($tmp_email) != true) {
                    unset($tmp_contacts[$tmp_email]);
                }
            }
        }

        // Copy all email alerts to default contact if configured.
        $default_mail = LibrenmsConfig::get('alert.default_mail');
        if (! isset($tmp_contacts[$default_mail]) && LibrenmsConfig::get('alert.default_copy')) {
            $tmp_contacts[$default_mail] = '';
        }
        // Send email to default contact if no other contact found
        if (empty($tmp_contacts) && LibrenmsConfig::get('alert.default_if_none') && $default_mail) {
            $tmp_contacts[$default_mail] = '';
        }

        return $tmp_contacts;
    }

    public static function findContactsRoles(array $roles): array
    {
        return User::role($roles)->whereNot('email', '')->pluck('realname', 'email')->toArray();
    }

    public static function findContactsSysContact(array $results): array
    {
        $contacts = [];

        foreach ($results as $result) {
            $device = DeviceCache::get($result['device_id']);
            $email = $device->getAttrib('override_sysContact_bool')
                ? $device->getAttrib('override_sysContact_string')
                : $device->sysContact;
            $contacts[$email] = '';
        }

        return $contacts;
    }

    public static function findContactsOwners(array $results): array
    {
        return User::whereNot('email', '')->where(function (Builder $query) use ($results) {
            if ($device_ids = array_filter(Arr::pluck($results, 'device_id'))) {
                $query->orWhereHas('devicesOwned', fn ($q) => $q->whereIn('devices_perms.device_id', $device_ids));
            }
            if ($port_ids = array_filter(Arr::pluck($results, 'port_id'))) {
                $query->orWhereHas('portsOwned', fn ($q) => $q->whereIn('ports_perms.port_id', $port_ids));
            }
            if ($bill_ids = array_filter(Arr::pluck($results, 'bill_id'))) {
                $query->orWhereHas('bills', fn ($q) => $q->whereIn('bill_perms.bill_id', $bill_ids));
            }
        })->pluck('realname', 'email')->all();
    }

    public static function getRules($device_id)
    {
        $query = 'SELECT DISTINCT a.* FROM alert_rules a
        LEFT JOIN alert_device_map d ON a.id=d.rule_id AND (a.invert_map = 0 OR a.invert_map = 1 AND d.device_id = ?)
        LEFT JOIN alert_group_map g ON a.id=g.rule_id AND (a.invert_map = 0 OR a.invert_map = 1 AND g.group_id IN (SELECT DISTINCT device_group_id FROM device_group_device WHERE device_id = ?))
        LEFT JOIN alert_location_map l ON a.id=l.rule_id AND (a.invert_map = 0 OR a.invert_map = 1 AND l.location_id IN (SELECT DISTINCT location_id FROM devices WHERE device_id = ?))
        LEFT JOIN devices ld ON l.location_id=ld.location_id AND ld.device_id = ?
        LEFT JOIN device_group_device dg ON g.group_id=dg.device_group_id AND dg.device_id = ?
        WHERE a.disabled = 0 AND (
            (d.device_id IS NULL AND g.group_id IS NULL AND l.location_id IS NULL)
            OR (a.invert_map = 0 AND (d.device_id=? OR dg.device_id=? OR ld.device_id=?))
            OR (a.invert_map = 1  AND (d.device_id != ? OR d.device_id IS NULL) AND (dg.device_id != ? OR dg.device_id IS NULL) AND (ld.device_id != ? OR ld.device_id IS NULL))
        )';

        $params = [$device_id, $device_id, $device_id, $device_id, $device_id, $device_id, $device_id, $device_id, $device_id, $device_id, $device_id];

        return dbFetchRows($query, $params);
    }

    /**
     * Check if device is under maintenance
     *
     * @param  int  $device_id  Device-ID
     * @return MaintenanceStatus
     */
    public static function getMaintenanceStatus($device_id): MaintenanceStatus
    {
        return DeviceCache::get($device_id)->getMaintenanceStatus();
    }

    /**
     * Check if device is set to ignore alerts
     *
     * @param  int  $device_id  Device-ID
     * @return bool
     */
    public static function hasDisableNotify($device_id)
    {
        $device = Device::find($device_id);

        return ! is_null($device) && $device->disable_notify;
    }

    /**
     * Process Macros
     *
     * @param  string  $rule  Rule to process
     * @param  int  $x  Recursion-Anchor
     * @return string|bool
     */
    public static function runMacros($rule, $x = 1)
    {
        $macros = LibrenmsConfig::get('alert.macros.rule', []);
        krsort($macros);
        foreach ($macros as $macro => $value) {
            if (! strstr($macro, ' ')) {
                $rule = str_replace('%macros.' . $macro, '(' . $value . ')', $rule);
            }
        }
        if (strstr($rule, '%macros.')) {
            if (++$x < 30) {
                $rule = self::runMacros($rule, $x);
            } else {
                return false;
            }
        }

        return $rule;
    }

    /**
     * Re-Validate Rule-Mappings
     *
     * @param  int  $device_id  Device-ID
     * @param  int  $rule  Rule-ID
     * @return bool
     */
    public static function isRuleValid($device_id, $rule)
    {
        global $rulescache;
        if (empty($rulescache[$device_id]) || ! isset($rulescache[$device_id])) {
            foreach (AlertUtil::getRules($device_id) as $chk) {
                $rulescache[$device_id][$chk['id']] = true;
            }
        }

        if ($rulescache[$device_id][$rule] === true) {
            return true;
        }

        return false;
    }

     /**
     * load alerts
     *
     * @param  string  $where   SQL WHERE Statement
     * @param  array  $vars     Varibales to pass to dbFetchRows
     * @return array
     */

    public static function loadAlerts($where, $vars)
    {
        $alerts = [];
        foreach (dbFetchRows("SELECT alerts.id, alerts.alerted, alerts.device_id, alerts.rule_id, alerts.state, alerts.note, alerts.info FROM alerts WHERE $where", $vars) as $alert_status) {
            $alert = dbFetchRow(
                'SELECT alert_log.id,alert_log.rule_id,alert_log.device_id,alert_log.state,alert_log.details,alert_log.time_logged,alert_rules.severity,alert_rules.extra,alert_rules.name,alert_rules.query,alert_rules.builder,alert_rules.proc FROM alert_log,alert_rules WHERE alert_log.rule_id = alert_rules.id && alert_log.device_id = ? && alert_log.rule_id = ? && alert_rules.disabled = 0 ORDER BY alert_log.id DESC LIMIT 1',
                [$alert_status['device_id'], $alert_status['rule_id']]
            );

            $alert['alert_id'] = $alert_status['id'];

            if (empty($alert['rule_id']) || ! AlertUtil::isRuleValid($alert_status['device_id'], $alert_status['rule_id'])) {
                echo 'Stale-Rule: #' . $alert_status['rule_id'] . '/' . $alert_status['device_id'] . "\r\n";
                // Alert-Rule does not exist anymore, let's remove the alert-state.
                dbDelete('alerts', 'rule_id = ? && device_id = ?', [$alert_status['rule_id'], $alert_status['device_id']]);
            } else {
                $alert['state'] = $alert_status['state'];
                $alert['alerted'] = $alert_status['alerted'];
                $alert['note'] = $alert_status['note'];
                if (! empty($alert['details'])) {
                    $alert['details'] = json_decode(gzuncompress($alert['details']), true);
                }
                $alert['info'] = json_decode((string) $alert_status['info'], true);
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * Update alert details
     *
     * @param  string  $rule  Rule to process
     * @param  int  $x  Recursion-Anchor
     * @return string|bool
     */
    public static function getRuleResult($rule, $device_id)
    {
        if (empty($rule['query'])) {
            $rule['query'] = QueryBuilderParser::fromJson($rule['builder'])->toSql();
        }
        try {
            $chk = dbFetchRows($rule['query'], [$device_id]);
        } catch (PDOException $e) {
            c_echo('%RError: %n' . $e->getMessage() . PHP_EOL);
            Eventlog::log("Error in alert rule {$rule['name']} ({$rule['id']}): " . $e->getMessage(), $device_id, 'alert', Severity::Error);
            return NULL; // skip this rule
        }
        //make sure we can json_encode all the datas later
        $current_alert_count = count($chk);
        for ($i = 0; $i < $current_alert_count; $i++) {
            if (isset($chk[$i]['ip'])) {
                $chk[$i]['ip'] = inet6_ntop($chk[$i]['ip']);
            }
        }
        return $chk;
    }

     /**
     * Update alert details
     *
     * @param  string  $rule  Rule to process
     * @param  int  $x  Recursion-Anchor
     * @return string|bool
     */
    public static function updateAlert($alert, $rule_result)
    {
        $extra = json_decode((string) $alert['extra'], true);
        $inv = false;
        if (isset($extra['invert'])) {
            $inv = (bool) $extra['invert'];
        }

        $current_alert_count = count($rule_result);


        $alert['details']['rule'] ??= []; // if details.rule is missing, set it to an empty array
        $ret = 'Alert #' . $alert['id'];
        $state = AlertState::CLEAR;

        // Get the added and resolved items
        [$added_diff, $resolved_diff] = AlertUtil::diffBetweenFaults($alert['details']['rule'], $rule_result);
        $previous_alert_count = count($alert['details']['rule']);

        if (! empty($added_diff) && ! empty($resolved_diff)) {
            $ret .= ' Changed';
            $state = AlertState::CHANGED;
            $alert['details']['diff'] = ['added' => $added_diff, 'resolved' => $resolved_diff];
        } elseif (! empty($added_diff)) {
            $ret .= ' Worse';
            $state = AlertState::WORSE;
            $alert['details']['diff'] = ['added' => $added_diff];
        } elseif (! empty($resolved_diff)) {
            $ret .= ' Better';
            $state = AlertState::BETTER;
            $alert['details']['diff'] = ['resolved' => $resolved_diff];
            // Failsafe if the diff didn't return any results
        } elseif ($current_alert_count > $previous_alert_count) {
            $ret .= ' Worse';
            $state = AlertState::WORSE;
            Eventlog::log('Alert got worse but the diff was not, ensure that a "id" or "_id" field is available for rule ' . $alert['name'], $alert['device_id'], 'alert', Severity::Warning);
            // Failsafe if the diff didn't return any results
        } elseif ($current_alert_count < $previous_alert_count) {
            $ret .= ' Better';
            $state = AlertState::BETTER;
            Eventlog::log('Alert got better but the diff was not, ensure that a "id" or "_id" field is available for rule ' . $alert['name'], $alert['device_id'], 'alert', Severity::Warning);
        }


        if ($current_alert_count == 0 && $inv === false) {
            $state = AlertState::CLEAR;
        } elseif ($current_alert_count > 0 && $inv === true) {
            $state = AlertState::CLEAR;
        } elseif ($current_alert_count == 0 && $inv === true) {
            $state = AlertState::ACTIVE;
        }


        if ($state > AlertState::CLEAR) {

            if ($alert['state'] == AlertState::ACKNOWLEDGED && ($alert['info']['until_clear'] === true)) {
                $state = $alert['state'];
            }

            $alert['details']['contacts'] = AlertUtil::getContacts($rule_result);
            $alert['details']['rule'] = $rule_result;
            if (dbInsert([
                'state' => $state,
                'device_id' => $alert['device_id'],
                'rule_id' => $alert['rule_id'],
                'details' => gzcompress(json_encode($alert['details']), 9),
            ], 'alert_log')) {
                dbUpdate(['state' => $state, 'open' => 1, 'alerted' => 1], 'alerts', 'rule_id = ? && device_id = ?', [$alert['rule_id'], $alert['device_id']]);
            }
            echo $ret . ' (' . $previous_alert_count . '/' . $current_alert_count . ")\r\n";
        } else {
            if ($alert["state"] == AlertState::CLEAR) {
                    Log::info('Status: %bNOCHG%n', ['color' => true]);
            } else {
                if (dbInsert(['state' => AlertState::CLEAR, 'device_id' => $device_id, 'rule_id' => $rule['id']], 'alert_log')) {
                    dbUpdate(['state' => AlertState::CLEAR, 'open' => 1, 'note' => '', 'timestamp' => Carbon::now()], 'alerts', 'device_id = ? && rule_id = ?', [$device_id, $rule['id']]);
                    
                    Log::info(PHP_EOL . 'Status: %gOK%n', ['color' => true]);
                }
            }
        }
    }

        /**
     * Extract the fields that are used to identify the elements in the array of a "fault"
     *
     * @param  array  $element
     * @return array
     */
    public static function extractIdFieldsForFault($element)
    {
        return array_filter(array_keys($element), fn ($key) =>
            // Exclude location_id as it is not relevant for the comparison
            ($key === 'id' || strpos((string) $key, '_id')) !== false && $key !== 'location_id');
    }

    /**
     * Generate a comparison key for an element based on the fields that identify it for a "fault"
     *
     * @param  array  $element
     * @param  array  $idFields
     * @return string
     */
    public static function generateComparisonKeyForFault($element, $idFields)
    {
        $keyParts = [];
        foreach ($idFields as $field) {
            $keyParts[] = $element[$field] ?? '';
        }

        return implode('|', $keyParts);
    }


    /**
     * Find new elements in the array for faults
     * PHP array_diff is not working well for it
     *
     * @param  array  $array1
     * @param  array  $array2
     * @return array [$added, $removed]
     */
    private static function diffBetweenFaults($array1, $array2)
    {
        $array1_keys = [];
        $added_elements = [];
        $removed_elements = [];

        // Create associative array for quick lookup of $array1 elements
        foreach ($array1 as $element1) {
            $element1_ids = AlertUtil::extractIdFieldsForFault($element1);
            $element1_key = AlertUtil::generateComparisonKeyForFault($element1, $element1_ids);
            $array1_keys[$element1_key] = $element1;
        }

        // Iterate through $array2 and determine added elements
        foreach ($array2 as $element2) {
            $element2_ids = AlertUtil::extractIdFieldsForFault($element2);
            $element2_key = AlertUtil::generateComparisonKeyForFault($element2, $element2_ids);

            if (! isset($array1_keys[$element2_key])) {
                $added_elements[] = $element2;
            } else {
                // Remove matched elements
                unset($array1_keys[$element2_key]);
            }
        }

        // Remaining elements in $array1_keys are the removed elements
        $removed_elements = array_values($array1_keys);

        return [$added_elements, $removed_elements];
    }

}
