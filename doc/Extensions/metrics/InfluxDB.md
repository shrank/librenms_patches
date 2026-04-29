# Enabling support for InfluxDB

Before we get started it is important that you know and understand
that InfluxDB support is currently alpha at best. All it provides is
the sending of data to a InfluxDB install. Due to the current changes
that are constantly being made to InfluxDB itself then we cannot
guarantee that your data will be ok so enabling this support is at
your own risk!

## Requirements

- InfluxDB >= 0.94 < 2.0
- Grafana

The setup of the above is completely out of scope here and we aren't
really able to provide any help with this side of things.

## What you don't get

- Pretty graphs, this is why at present you need Grafana. You need to
  build your own graphs within Grafana.
- Support for InfluxDB or Grafana, we would highly recommend that you
  have some level of experience with these.

RRD will continue to function as normal so LibreNMS itself should
continue to function as normal.

## Configuration

!!! setting "poller/influxdb"
    ```bash
    lnms config:set influxdb.enable true
    lnms config:set influxdb.transport http
    lnms config:set influxdb.host '127.0.0.1'
    lnms config:set influxdb.port 8086
    lnms config:set influxdb.db 'librenms'
    lnms config:set influxdb.username 'admin'
    lnms config:set influxdb.password 'admin'
    lnms config:set influxdb.timeout 0
    lnms config:set influxdb.batch_size 0
    lnms config:set influxdb.measurements ''
    lnms config:set influxdb.verifySSL false
    lnms config:set influxdb.debug false
    lnms config:set influxdb.extra_tags []
    lnms config:set influxdb.add_group_tags false
    lnms config:set influxdb.add_maintenance_tag false
    ```

No credentials are needed if you don't use InfluxDB authentication.

The same data then stored within rrd will be sent to InfluxDB and
recorded. You can then create graphs within Grafana to display the
information you need.

## Extra Tags

You can configure additional tags that will be added to every metric
sent to InfluxDB. This is useful for adding contextual information
like site location or environment.

!!! setting "poller/influxdb"
    ```bash
    lnms config:set influxdb.extra_tags '["site=branch A", "tag2=value 2", "environment=production"]'
    ```

Each array element can contain multiple comma-separated key=value pairs.
These tags will be added to all measurements along with the following
dynamic tags:

- `device_ip` - The IP address of the device
- `device_serial` - The serial number of the device
- `device_model` - The hardware/model of the device
- `module` - The name of the module that collected the metric

Optional dynamic tags (require enabling the corresponding setting):

- `maintenance` - Whether the device is under maintenance (1 or 0).
  Requires `influxdb.add_maintenance_tag` to be enabled.
- `group_<sanitized_name>` - Device groups as separate tags.
  Requires `influxdb.add_group_tags` to be enabled.

## Group Tags

When `influxdb.add_group_tags` is enabled, each device group is added as
a separate tag. The group name is sanitized to create a valid InfluxDB tag key:
invalid characters are replaced with underscores and the prefix `group_`
is added.

!!! setting "poller/influxdb"
    ```bash
    lnms config:set influxdb.add_group_tags true
    ```

For example, a device in groups "Datacenter" and "Core-Switches" will
receive tags `group_Datacenter` and `group_Core_Switches`.

## Maintenance Tag

When `influxdb.add_maintenance_tag` is enabled, a `maintenance` tag is
added to each metric indicating whether the device is currently under
maintenance.

!!! setting "poller/influxdb"
    ```bash
    lnms config:set influxdb.add_maintenance_tag true
    ```
