<?php

/**
 * AlertRules.php
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
 * Original Code:
 *
 * @author Daniel Preussker <f0o@devilcode.org>
 * @copyright 2014 f0o, LibreNMS
 * @license GPL
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2019 KanREN, Inc.
 * @author     Heath Barnhart <hbarnhart@kanren.net>
 */

namespace LibreNMS\Alert;

use App\Models\Eventlog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\Alerting\QueryBuilderParser;
use LibreNMS\Enum\AlertState;
use LibreNMS\Enum\MaintenanceStatus;
use LibreNMS\Enum\Severity;
use PDO;
use PDOException;

class AlertRules
{
    public function runRules($device_id)
    {
        //Check to see if under maintenance
        if (AlertUtil::getMaintenanceStatus($device_id) === MaintenanceStatus::SKIP_ALERTS) {
            echo "Under Maintenance, skipping alert rules check.\r\n";

            return false;
        }
        //Check to see if disable alerting is set
        if (AlertUtil::hasDisableNotify($device_id)) {
            echo "Disable alerting is set, Clearing active alerts and skipping alert rules check\r\n";
            $device_alert['state'] = AlertState::CLEAR;
            $device_alert['alerted'] = 0;
            $device_alert['open'] = 0;
            dbUpdate($device_alert, 'alerts', '`device_id` = ?', [$device_id]);

            return false;
        }
        //Checks each rule.
        foreach (AlertUtil::getRules($device_id) as $rule) {
            Log::info('Rule %p#' . $rule['id'] . ' (' . $rule['name'] . '):%n ', ['color' => true]);
            $extra = json_decode($rule['extra'], true);
            if (isset($extra['invert'])) {
                $inv = (bool) $extra['invert'];
            } else {
                $inv = false;
            }

            $rule_result = AlertUtil::getRuleResult($rule, $device_id);

            if(is_null($rule_result)) continue;

            $s = count($rule_result);
            $doalert = true;
            if ($s == 0 && $inv === false) {
                $doalert = false;
            } elseif ($s > 0 && $inv === true) {
                $doalert = false;
            }

            $current_state = dbFetchCell('SELECT state FROM alerts WHERE rule_id = ? AND device_id = ? ORDER BY id DESC LIMIT 1', [$rule['id'], $device_id]);

            if(is_null($current_state) && !$doalert) continue;

            if(is_null($current_state)) {
                // create new alert
                $extra = gzcompress(json_encode(['contacts' => AlertUtil::getContacts($rule_result), 'rule' => $rule_result]), 9);
                if (dbInsert(['state' => AlertState::ACTIVE, 'device_id' => $device_id, 'rule_id' => $rule['id'], 'details' => $extra], 'alert_log')) {
                    dbInsert(['state' => AlertState::ACTIVE, 'device_id' => $device_id, 'rule_id' => $rule['id'], 'open' => 1, 'alerted' => 0], 'alerts');
                    Log::info(PHP_EOL . 'Status: %rALERT%n', ['color' => true]);
                }
            } else {
                // update existing alert
                Log::info('Status: %bNOCHG%n', ['color' => true]);
                $alert = AlertUtil::loadAlerts('device_id = ? AND rule_id = ?', [$device_id, $rule['id']]);
                // do we need to handle disabled rules here?
                AlertUtil::updateAlert($alert[0], $rule_result);

            }
        }
    }
}
