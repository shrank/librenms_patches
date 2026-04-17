<?php

/**
 * InfluxDB.php
 *
 * -Description-
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
 * @copyright  2020 Tony Murray
 * @copyright  2014 Neil Lathwood <https://github.com/laf/ http://www.lathwood.co.uk/fa>
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Data\Store;

use Illuminate\Support\Facades\Cache;
use App\Facades\LibrenmsConfig;
use App\Polling\Measure\Measurement;
use InfluxDB\Client;
use InfluxDB\Database;
use InfluxDB\Driver\UDP;
use Log;

class InfluxDB extends BaseDatastore
{
    private $batchPoints = []; // Store points before writing
    private $batchSize = 0; // Number of points to write at once
    private $measurements = []; // List of measurements to write

    private $extraTags = [];

    private $add_group_tags = False;

    public function __construct(private readonly Database $connection)
    {
        parent::__construct();
        $this->batchSize = LibrenmsConfig::get('influxdb.batch_size', 0);
        $this->measurements = LibrenmsConfig::get('influxdb.measurements', []);
        $this->extraTags = $this->parseExtraTags(LibrenmsConfig::get('influxdb.extra_tags', []));
        $this->add_group_tags = LibrenmsConfig::get('influxdb.add_group_tags', False);
 
        // if the database doesn't exist, create it.
        // When using UDP transport, the call to exists() fails
        // since the transport doesn't support querying.  That said
        // the database will be created automatically upon data
        // reception.
        if (LibrenmsConfig::get('influxdb.transport', 'http') !== 'udp') {
            try {
                if (! $this->connection->exists()) {
                    $this->connection->create();
                }
            } catch (\Exception) {
                Log::warning('InfluxDB: Could not create database');
            }
        }
    }

    private function parseExtraTags(array $extraTagsConfig): array
    {
        $parsed = [];
        foreach ($extraTagsConfig as $tagString) {
            if (empty($tagString)) {
                continue;
            }
            $pairs = explode(',', $tagString);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if (str_contains($pair, '=')) {
                    [$key, $value] = array_map('trim', explode('=', $pair, 2));
                    $parsed[$key] = $value;
                }
            }
        }

        return $parsed;
    }

    public function terminate(): void
    {
        // Ensure any remaining points are written before the script ends
        $this->flushBatch();
    }

    public function getName(): string
    {
        return 'InfluxDB';
    }

    public static function isEnabled(): bool
    {
        return LibrenmsConfig::get('influxdb.enable', false);
    }

    private function getMaintenance($device) {
      $key=str($device->device_id) . "_maintenance";
      return Cache::remember($key, 60, function () use ($device, $key) {
          return $device->isUnderMaintenance() ? '1' : '0';;
      });
    }

    /**
     * @inheritDoc
     */
    public function write(string $measurement, array $fields, array $tags = [], array $meta = []): void
    {
        // Check if this measurement is enabled
        if (! empty($this->measurements) && ! in_array($measurement, $this->measurements)) {
            return;
        }

        $stat = Measurement::start('write');
        $device = $this->getDevice($meta);
        $tmp_fields = [];
        $tmp_tags['hostname'] = $device->hostname;

        // Add dynamic device tags
        $tmp_tags['device_ip'] = $device->ip ?? '';
        $tmp_tags['device_serial'] = $device->serial ?? '';
        $tmp_tags['device_model'] = $device->hardware ?? '';

        // Add maintenance status tag
        $tmp_tags['maintenance'] = $this->getMaintenance($device);

        if($this->add_group_tags) {
            // Add device groups as tags
            $groups = $device->groups;
            if ($groups->isNotEmpty()) {
                foreach ($groups->pluck('name') as $group_name) {
                    // Sanitize group name to be a valid InfluxDB tag key
                    // Replace invalid characters with underscore
                    $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $group_name);
                    // Ensure it doesn't start with a number
                    $tmp_tags['group_' . $sanitized] = $group_name;
                }
            }
        }

        // Add module name (measurement name)
        $tmp_tags['module'] = $measurement;

        // Add extra tags from configuration
        foreach ($this->extraTags as $key => $value) {
            $tmp_tags[$key] = $value;
        }

        foreach ($tags as $k => $v) {
            if (empty($v)) {
                $v = '_blank_';
            }
            $tmp_tags[$k] = $v;
        }
        foreach ($fields as $k => $v) {
            if ($k == 'time') {
                $k = 'rtime';
            }

            if (($value = $this->forceType($v)) !== null) {
                $tmp_fields[$k] = $value;
            }
        }

        if (empty($tmp_fields)) {
            Log::warning('All fields empty, skipping update', ['orig_fields' => $fields]);

            return;
        }

        if (LibrenmsConfig::get('influxdb.debug', false) === true) {
            Log::debug('InfluxDB data: ', [
                'measurement' => $measurement,
                'tags' => $tmp_tags,
                'fields' => $tmp_fields,
            ]);
        }

        try {
            // Add timestamp to points as current time in seconds
            // This is important for batch writes to ensure data is ordered and aggregated correctly
            $timestamp = (int) floor(microtime(true));

            $this->batchPoints[] = new \InfluxDB\Point(
                $measurement,
                null, // the measurement value
                $tmp_tags,
                $tmp_fields, // optional additional fields,
                $timestamp
            );

            // Flush batch if size limit is reached
            if (count($this->batchPoints) >= $this->batchSize) {
                $this->flushBatch();
            }
            $this->recordStatistic($stat->end());
        } catch (\InfluxDB\Exception $e) {
            Log::error('InfluxDB exception: ' . $e->getMessage());
            Log::debug($e->getTraceAsString());
        }
    }

    /**
     * Flush the batch to InfluxDB
     */
    public function flushBatch()
    {
        if (empty($this->batchPoints)) {
            // No points to write, nothing to do
            return;
        }
        if (LibrenmsConfig::get('influxdb.debug', false) === true) {
            Log::debug('Flushing InfluxDB batch of ' . count($this->batchPoints) . ' points');
        }
        try {
            $this->connection->writePoints($this->batchPoints, \InfluxDB\Database::PRECISION_SECONDS); // Added timestamps are in seconds
        } catch (\InfluxDB\Exception $e) {
            Log::error('InfluxDB batch write failed: ' . $e->getMessage());
        }
        $this->batchPoints = []; // Clear the batch after writing
    }

    /**
     * Create a new client and select the database
     *
     * @return Database
     */
    public static function createFromConfig()
    {
        $host = LibrenmsConfig::get('influxdb.host', 'localhost');
        $transport = LibrenmsConfig::get('influxdb.transport', 'http');
        $port = LibrenmsConfig::get('influxdb.port', 8086);
        $db = LibrenmsConfig::get('influxdb.db', 'librenms');
        $username = LibrenmsConfig::get('influxdb.username', '');
        $password = LibrenmsConfig::get('influxdb.password', '');
        $timeout = LibrenmsConfig::get('influxdb.timeout', 0);
        $verify_ssl = LibrenmsConfig::get('influxdb.verifySSL', false);

        $client = new Client($host, $port, $username, $password, $transport == 'https', $verify_ssl, $timeout, $timeout);

        if ($transport == 'udp') {
            $client->setDriver(new UDP($host, $port));
        }

        // Suppress InfluxDB\Database::create(): Implicitly marking parameter $retentionPolicy as nullable is deprecated
        return @$client->selectDB($db);
    }

    private function forceType($data)
    {
        /*
         * It is not trivial to detect if something is a float or an integer, and
         * therefore may cause breakages on inserts.
         * Just setting every number to a float gets around this, but may introduce
         * inefficiencies.
         */

        if (is_numeric($data)) {
            return floatval($data);
        }

        return $data === 'U' ? null : $data;
    }
}
