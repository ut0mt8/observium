<?php

/**
 * Observium
 *
 *   This file is part of Observium.
 *
 * @package    observium
 * @subpackage poller
 * @copyright  (C) 2006-2013 Adam Armstrong, (C) 2013-2016 Observium Limited
 *
 */

// Parse output of ipmitool sensor
function parse_ipmitool_sensor($device, $results, $source = 'ipmi')
{
  global $valid, $config;

  $index = 0;

  foreach (explode("\n",$results) as $row)
  {
    $index++;

    # BB +1.1V IOH     | 1.089      | Volts      | ok    | na        | 1.027     | 1.054     | 1.146     | 1.177     | na
    list($desc,$current,$unit,$state,$low_nonrecoverable,$limit_low,$limit_low_warn,$limit_high_warn,$limit_high,$high_nonrecoverable) = explode('|',$row);

    if (trim($current) != "na" && trim($state) != "nr" && $config['ipmi_unit'][trim($unit)])
    {
      $limits = array('limit_high'      => trim($limit_high),
                      'limit_low'       => trim($limit_low),
                      'limit_high_warn' => trim($limit_high_warn),
                      'limit_low_warn'  => trim($limit_low_warn));
      discover_sensor($valid['sensor'], $config['ipmi_unit'][trim($unit)], $device, '', $index, $source, trim($desc), 1, trim($current), $limits, $source);

      $ipmi_sensors[$config['ipmi_unit'][trim($unit)]][$source][$index] = array('description' => $desc, 'current' => $current, 'index' => $index, 'unit' => $unit);
    }
  }

  return $ipmi_sensors;
}

// Poll a sensor
function poll_sensor($device, $class, $unit, &$oid_cache)
{
  global $config, $agent_sensors, $ipmi_sensors, $graphs, $table_rows;

  $sql  = "SELECT * FROM `sensors`";
  $sql .= " LEFT JOIN `sensors-state` USING(`sensor_id`)";
  $sql .= " WHERE `sensor_class` = ? AND `device_id` = ?";

  foreach (dbFetchRows($sql, array($class, $device['device_id'])) as $sensor_db)
  {
    $sensor_poll = array();

    //print_cli_heading("Sensor: ".$sensor_db['sensor_descr'], 3);

    if (OBS_DEBUG)
    {
      echo("Checking (" . $sensor_db['poller_type'] . ") $class " . $sensor_db['sensor_descr'] . " ");
      print_r($sensor_db);
    }

    if ($sensor_db['poller_type'] == "snmp")
    {
      # if ($class == "temperature" && $device['os'] == "papouch")
      // Why all temperature?
      if ($class == "temperature")
      {
        for ($i = 0; $i < 5; $i++) // Try 5 times to get a valid temp reading
        {
          // Take value from $oid_cache if we have it, else snmp_get it
          if (is_numeric($oid_cache[$sensor_db['sensor_oid']]))
          {
            print_debug("value taken from oid_cache");
            $sensor_poll['sensor_value'] = $oid_cache[$sensor_db['sensor_oid']];
          } else {
            $sensor_poll['sensor_value'] = snmp_fix_numeric(snmp_get($device, $sensor_db['sensor_oid'], "-OUqnv", "SNMPv2-MIB", mib_dirs()));
          }

          if (is_numeric($sensor_poll['sensor_value']) && $sensor_poll['sensor_value'] != 9999)
          {
            break;
          } // Papouch TME sometimes sends 999.9 when it is right in the middle of an update;
          sleep(1); // Give the TME some time to reset
        }
        // If we received 999.9 degrees still, reset to Unknown.
        if ($sensor_poll['sensor_value'] == 9999)
        {
          $sensor_poll['sensor_value'] = "U";
        }
      }
      else if ($class == "runtime")
      {
        if (isset($oid_cache[$sensor_db['sensor_oid']]))
        {
          print_debug("value taken from oid_cache");
          $sensor_poll['sensor_value'] = $oid_cache[$sensor_db['sensor_oid']];
        } else {
          $sensor_poll['sensor_value'] = snmp_get($device, $sensor_db['sensor_oid'], "-OUqnv", "SNMPv2-MIB", mib_dirs());
        }
        if (strpos($sensor_poll['sensor_value'], ':') !== FALSE)
        {
          // Use timetick conversion only when snmpdata is formatted as timetick 0:0:21:00.00
          $sensor_poll['sensor_value'] = timeticks_to_sec($sensor_poll['sensor_value']);
        }
      } else {
        // Take value from $oid_cache if we have it, else snmp_get it
        if (is_numeric($oid_cache[$sensor_db['sensor_oid']]))
        {
          print_debug("value taken from oid_cache");
          $sensor_poll['sensor_value'] = $oid_cache[$sensor_db['sensor_oid']];
        } else {
          $sensor_poll['sensor_value'] = snmp_fix_numeric(snmp_get($device, $sensor_db['sensor_oid'], "-OUqnv", "SNMPv2-MIB", mib_dirs()));
        }
      }
    }
    else if ($sensor_db['poller_type'] == "agent")
    {
      if (isset($agent_sensors))
      {
        $sensor_poll['sensor_value'] = $agent_sensors[$class][$sensor_db['sensor_type']][$sensor_db['sensor_index']]['current']; // FIXME pass unit?
      } else {
        print_warning("No agent sensor data available.");
        continue;
      }
    }
    else if ($sensor_db['poller_type'] == "ipmi")
    {
      if (isset($ipmi_sensors))
      {
        $sensor_poll['sensor_value'] = $ipmi_sensors[$class][$sensor_db['sensor_type']][$sensor_db['sensor_index']]['current'];
        $unit = $ipmi_sensors[$class][$sensor_db['sensor_type']][$sensor_db['sensor_index']]['unit'];
      } else {
        print_warning("No IPMI sensor data available.");
        continue;
      }
    } else {
      print_warning("Unknown sensor poller type.");
      continue;
    }

    $sensor_polled_time = time(); // Store polled time for current sensor

    if (OBS_DEBUG)
    {
      print_r($sensor_poll);
    }

    if ($sensor_poll['sensor_value'] == -32768)
    {
      print_debug("Invalid (-32768) ");
      $sensor_poll['sensor_value'] = 0;
    }

    if (isset($sensor_db['sensor_multiplier']) && $sensor_db['sensor_multiplier'] != 0)
    {
      $sensor_poll['sensor_value'] *= $sensor_db['sensor_multiplier'];
    }

    switch ($sensor_db['sensor_unit'])
    {
      case 'F':
        $sensor_poll['sensor_value'] = f2c($sensor_poll['sensor_value']);
        print_debug('TEMPERATURE sensor: Fahrenheit -> Celsius');
        break;
      case 'K':
        $sensor_poll['sensor_value'] -= 273.15;
        print_debug('TEMPERATURE sensor: Kelvin -> Celsius');
        break;
    }

    $rrd_file = get_sensor_rrd($device, $sensor_db);

    rrdtool_create($device, $rrd_file, "DS:sensor:GAUGE:600:-20000:U");

    //print_cli_data("Value", $sensor_poll['sensor_value'] . "$unit ", 3);

    // FIXME this block and the other block below it are kinda retarded. They should be merged and simplified.

    if ($sensor_poll['sensor_ignore'] || $sensor_poll['sensor_disable'])
    {
      $sensor_poll['sensor_event'] = 'ignore';
    } else {
      if (($sensor_db['sensor_limit_low'] != '' && $sensor_poll['sensor_value'] < $sensor_db['sensor_limit_low']) ||
          ($sensor_db['sensor_limit']     != '' && $sensor_poll['sensor_value'] > $sensor_db['sensor_limit']))
      {
        $sensor_poll['sensor_event'] = 'alert';
        $sensor_poll['sensor_status'] = 'Sensor critical thresholds exceeded.'; // FIXME - be more specific
      }
      else if (($sensor_db['sensor_limit_low_warn'] != '' && $sensor_poll['sensor_value'] < $sensor_db['sensor_limit_low_warn']) ||
               ($sensor_db['sensor_limit_warn']     != '' && $sensor_poll['sensor_value'] > $sensor_db['sensor_limit_warn']))
      {
        $sensor_poll['sensor_event'] = 'warning';
        $sensor_poll['sensor_status'] = 'Sensor warning thresholds exceeded.'; // FIXME - be more specific
      } else {
        $sensor_poll['sensor_event'] = 'ok';
        $sensor_poll['sensor_status'] = '';
        //if ($sensor_db['sensor_event'] != 'up' && $sensor_db['sensor_event'] != '')
        //{
        //  $sensor_poll['sensor_status'] = 'Sensor thresholds cleared.'; // FIXME - be more specific
        //}
      }
    }

    // If last change never set, use current time
    if (empty($sensor_db['sensor_last_change']))
    {
      $sensor_db['sensor_last_change'] = $sensor_polled_time;
    }

    if ($sensor_poll['sensor_event'] != $sensor_db['sensor_event'])
    {
      // Sensor event changed, log and set sensor_last_change
      $sensor_poll['status_last_change'] = $sensor_polled_time;

      if ($sensor_db['sensor_event'] == 'ignore')
      {
        print_message("[%ySensor Ignored%n]", 'color');
      }
      else if ($sensor_db['sensor_limit_low'] != "" && $sensor_db['sensor_value'] >= $sensor_db['sensor_limit_low'] && $sensor_poll['sensor_value'] < $sensor_db['sensor_limit_low'])
      {
        // If old value greater than low limit and new value less than low limit
        $msg = ucfirst($class) . " Alarm: " . $device['hostname'] . " " . $sensor_db['sensor_descr'] . " is under threshold: " . $sensor_poll['sensor_value'] . "$unit (< " . $sensor_db['sensor_limit_low'] . "$unit)";
        log_event(ucfirst($class) . ' ' . $sensor_db['sensor_descr'] . " under threshold: " . $sensor_poll['sensor_value'] . " $unit (< " . $sensor_db['sensor_limit_low'] . " $unit)", $device, 'sensor', $sensor_db['sensor_id'], 'warning');
      }
      else if ($sensor_db['sensor_limit'] != "" && $sensor_db['sensor_value'] <= $sensor_db['sensor_limit'] && $sensor_poll['sensor_value'] > $sensor_db['sensor_limit'])
      {
        // If old value less than high limit and new value greater than high limit
        $msg = ucfirst($class) . " Alarm: " . $device['hostname'] . " " . $sensor_db['sensor_descr'] . " is over threshold: " . $sensor_poll['sensor_value'] . "$unit (> " . $sensor_db['sensor_limit'] . "$unit)";
        log_event(ucfirst($class) . ' ' . $sensor_db['sensor_descr'] . " above threshold: " . $sensor_poll['sensor_value'] . " $unit (> " . $sensor_db['sensor_limit'] . " $unit)", $device, 'sensor', $sensor_db['sensor_id'], 'warning');
      }
    } else {
      // If sensor not changed, leave old last_change
      $sensor_poll['sensor_last_change'] = $sensor_db['sensor_last_change'];
    }

    // Send statistics array via AMQP/JSON if AMQP is enabled globally and for the ports module
    if ($config['amqp']['enable'] == TRUE && $config['amqp']['modules']['sensors'])
    {
      $json_data = array('value' => $sensor_poll['sensor_value']);
      messagebus_send(array('attribs' => array('t'      => time(), 'device' => $device['hostname'], 'device_id' => $device['device_id'],
                                               'e_type' => 'sensor', 'e_class' => $sensor_db['sensor_class'], 'e_type' => $sensor_db['sensor_type'], 'e_index' => $sensor_db['sensor_index']), 'data' => $json_data));
    }

    // Add table row

    $table_rows[] = array($sensor_db['sensor_descr'], $sensor_db['sensor_class'], $sensor_db['sensor_type'], $sensor_db['poller_type'],
                          $sensor_poll['sensor_value'] . $unit, $sensor_poll['sensor_event'], format_unixtime($sensor_poll['sensor_last_change']));

    // Update StatsD/Carbon
    if ($config['statsd']['enable'] == TRUE)
    {
      StatsD::gauge(str_replace(".", "_", $device['hostname']) . '.' . 'sensor' . '.' . $sensor_db['sensor_class'] . '.' . $sensor_db['sensor_type'] . '.' . $sensor_db['sensor_index'], $sensor_poll['sensor_value']);
    }

    // Update RRD
    rrdtool_update($device, $rrd_file, "N:" . $sensor_poll['sensor_value']);

    // Enable graph
    $graphs[$sensor_db['sensor_class']] = TRUE;

    // Check alerts
    $metrics = array();

    $metrics['sensor_value']  = $sensor_poll['sensor_value'];
    $metrics['sensor_event']  = $sensor_poll['sensor_event'];
    $metrics['sensor_event_uptime'] = $sensor_polled_time - $sensor_poll['sensor_last_change'];
    $metrics['sensor_status'] = $sensor_poll['sensor_status'];

    check_entity('sensor', $sensor_db, $metrics);

    // Update SQL State
    if (is_numeric($sensor_db['sensor_polled']))
    {
      dbUpdate(array('sensor_value'  => $sensor_poll['sensor_value'],
                     'sensor_event'  => $sensor_poll['sensor_event'],
                     'sensor_status' => $sensor_poll['sensor_status'],
                     'sensor_last_change' => $sensor_poll['sensor_last_change'],
                     'sensor_polled' => $sensor_polled_time),
               'sensors-state', '`sensor_id` = ?', array($sensor_db['sensor_id']));
    } else {
      dbInsert(array('sensor_id'     => $sensor_db['sensor_id'],
                     'sensor_value'  => $sensor_poll['sensor_value'],
                     'sensor_event'  => $sensor_poll['sensor_event'],
                     'sensor_status' => $sensor_poll['sensor_status'],
                     'sensor_last_change' => $sensor_poll['sensor_last_change'],
                     'sensor_polled' => $sensor_polled_time),
               'sensors-state');
    }
  }
}

function poll_status($device)
{
  global $config, $agent_status, $ipmi_status, $graphs, $oid_cache;

  $sql  = "SELECT * FROM `status`";
  $sql .= " LEFT JOIN `status-state` USING(`status_id`)";
  $sql .= " WHERE `device_id` = ?";

  foreach (dbFetchRows($sql, array($device['device_id'])) as $status_db)
  {
    //print_cli_heading("Status: ".$status_db['status_descr']. "(".$status_db['poller_type'].")", 3);

    print_debug("Checking (" . $status_db['poller_type'] . ") " . $status_db['status_descr'] . " ");

    // $status_poll = $status_db;    // Cache non-humanized status array for use as new status state

    if ($status_db['poller_type'] == "snmp")
    {
      // Check if a specific poller file exists for this status, else collect via SNMP.
      $file = $config['install_dir']."/includes/polling/status/".$status_db['status_type'].".inc.php";

      if (is_file($file))
      {
        include($file);
      } else {
        // Take value from $oid_cache if we have it, else snmp_get it
        if (is_numeric($oid_cache[$status_db['status_oid']]))
        {
          print_debug("value taken from oid_cache");
          $status_value = $oid_cache[$status_db['status_oid']];
        } else {
          $status_value = snmp_fix_numeric(snmp_get($device, $status_db['status_oid'], "-OUqnv", "SNMPv2-MIB", mib_dirs()));
        }
      }
    }
    else if ($status_db['poller_type'] == "agent")
    {
      if (isset($agent_status))
      {
        $status_value = $agent_status[$class][$status_db['status_type']][$status_db['status_index']]['current'];
        // FIXME pass unit?
      } else {
        print_warning("No agent status data available.");
        continue;
      }
    }
    else if ($status_db['poller_type'] == "ipmi")
    {
      if (isset($ipmi_status))
      {
        $status_value = $ipmi_status[$class][$status_db['status_type']][$status_db['status_index']]['current'];
        $unit = $ipmi_status[$class][$status_db['status_type']][$status_db['status_index']]['unit'];
      } else {
        print_warning("No IPMI status data available.");
        continue;
      }
    } else {
      print_warning("Unknown status poller type.");
      continue;
    }

    $status_polled_time = time(); // Store polled time for current status

    $rrd_file = get_status_rrd($device, $status_db);

    rrdtool_create($device, $rrd_file, "DS:status:GAUGE:600:-20000:U");

    // Write new value and humanize (for alert checks)
    $status_poll['status_value'] = $status_value;

    // Set status_event and status_name if they're not already set.
    if (isset($config['status_states'][$status_db['status_type']]) && !isset($status['status_event']))
    {
      $status_poll['status_value'] = (int)$status_poll['status_value'];
      $status_poll['status_name'] = $config['status_states'][$status_db['status_type']][$status_poll['status_value']]['name'];
      if ($status_poll['status_ignore'] || $status['status_disable'])
      {
        $status_poll['status_event'] = 'ignore';
      } else {
        $status_poll['status_event'] = $config['status_states'][$status_db['status_type']][$status_poll['status_value']]['event'];
      }
    }

    // If last change never set, use current time
    if (empty($status_db['status_last_change']))
    {
                           $status_db['status_last_change'] = $status_polled_time;
    }

    if ($status_poll['status_event'] != $status_db['status_event'])
    {
      // Status event changed, log and set status_last_change
      $status_poll['status_last_change'] = $status_polled_time;

      if ($status_poll['status_event'] == 'ignore')
      {
        print_message("[%ystatus Ignored%n]", 'color');
      }
      else if ($status_db['status_event'] != '')
      {
        // If old state not empty and new state not equals to new state
        $msg = 'Status ';
        switch ($status_poll['status_event'])
        {
          case 'alert':
            // New state alerted
            $msg .= "Alert: " . $device['hostname'] . " " . $status_db['status_descr'] . " entered ALERT state: " . $status_poll['status_name'] . " (previous: " . $status_db['status_name'] . ")";
            log_event($msg, $device, 'status', $status_db['status_id'], 'warning');
            break;
          case 'warning':
            // New state warned
            $msg .= "Warning: " . $device['hostname'] . " " . $status_db['status_descr'] . " entered WARNING state: " . $status_poll['status_name'] . " (previous: " . $status_db['status_name'] . ")";
            log_event($msg, $device, 'status', $status_db['status_id']);
            break;
          case 'ok':
            // New state ok
            $msg .= "Ok: " . $device['hostname'] . " " . $status_db['status_descr'] . " entered OK state: " . $status_poll['status_name'] . " (previous: " . $status_db['status_name'] . ")";
            log_event($msg, $device, 'status', $status_db['status_id'], 'warning');
            break;
        }
      }
    } else {
      // If status not changed, leave old last_change
      $status_poll['status_last_change'] = $status_db['status_last_change'];
    }

    if (OBS_DEBUG > 1)
    {
      print_vars($status_poll);
    }

    // Send statistics array via AMQP/JSON if AMQP is enabled globally and for the ports module
    if ($config['amqp']['enable'] == TRUE && $config['amqp']['modules']['status'])
    {
      $json_data = array('value' => $status_value);
      messagebus_send(array('attribs' => array('t' => time(), 'device' => $device['hostname'], 'device_id' => $device['device_id'],
                                               'e_type' => 'status', 'e_type' => $status_db['status_type'], 'e_index' => $status_db['status_index']), 'data' => $json_data));
    }

    // Update StatsD/Carbon
    if ($config['statsd']['enable'] == TRUE)
    {
      StatsD::gauge(str_replace(".", "_", $device['hostname']).'.'.'status'.'.'.$status_db['status_class'].'.'.$status_db['status_type'].'.'.$status_db['status_index'], $status_value);
    }

    // Update RRD
    rrdtool_update($device, $rrd_file,"N:$status_value");

    // Enable graph
    $graphs[$sensor_db['status']] = TRUE;

    // Check alerts
    $metrics = array();

    $metrics['status_value'] = $status_value;
    $metrics['status_name']  = $status_poll['status_name'];
    $metrics['status_name_uptime'] = $status_polled_time - $status_poll['status_last_change'];
    $metrics['status_event'] = $status_poll['status_event'];

    //print_cli_data("Event (State)", $status_poll['status_event'] ." (".$status_poll['status_name'].")", 3);

    $GLOBALS['table_rows'][] = array($status_db['status_descr'], $status_db['status_type'], $status_db['status_index'] ,$status_db['poller_type'],
                          $status_poll['status_name'], $status_poll['status_event'], format_unixtime($status_poll['status_last_change']));

    check_entity('status', $status_db, $metrics);

    // Update SQL State
    if (is_numeric($status_db['status_polled']))
    {
      dbUpdate(array('status_value'  => $status_value,
                     'status_name'   => $status_poll['status_name'],
                     'status_event'  => $status_poll['status_event'],
                     'status_last_change' => $status_poll['status_last_change'],
                     'status_polled' => $status_polled_time),
      'status-state', '`status_id` = ?', array($status_db['status_id']));
    } else {
      dbInsert(array('status_id'     => $status_db['status_id'],
                     'status_value'  => $status_value,
                     'status_name'   => $status_poll['status_name'],
                     'status_event'  => $status_poll['status_event'],
                     'status_last_change' => $status_poll['status_last_change'],
                     'status_polled' => $status_polled_time),
      'status-state');
    }
  }
}

function poll_device($device, $options)
{
  global $config, $device, $polled_devices, $db_stats, $exec_status, $alert_rules, $alert_table, $graphs, $attribs;

  //print_r($device);
  $alert_metrics = array();

  $oid_cache = array();

  $old_device_state = unserialize($device['device_state']);

  $attribs = get_entity_attribs('device', $device['device_id']);

  $alert_rules = cache_alert_rules();
  $alert_table = cache_device_alert_table($device['device_id']);

  if (OBS_DEBUG > 1 && (count($alert_rules) || count($alert_table))) // Fuck you, dirty outputs.
  {
    print_vars($alert_rules);
    print_vars($alert_table);
  }

  $status = 0;

  $device_start = utime();  // Start counting device poll time

  print_cli_heading($device['hostname'] . " [".$device['device_id']."]", 1);

  print_cli_data("OS", $device['os'], 1);

  if ($config['os'][$device['os']]['group'])
  {
    $device['os_group'] = $config['os'][$device['os']]['group'];
    print_cli_data("OS Group", $device['os_group'], 1);
  }

  if (is_numeric($device['last_polled_timetaken']))
  {
    print_cli_data("Last poll duration", $device['last_polled_timetaken']. " seconds", 1);
  }

  print_cli_data("Last Polled", $device['last_polled'], 1);
  print_cli_data("SNMP Version", $device['snmp_version'], 1);

  //unset($poll_update); unset($poll_update_query); unset($poll_separator);
  $update_array = array();

  $host_rrd_dir = $config['rrd_dir'] . "/" . $device['hostname'];
  if (!is_dir($host_rrd_dir)) { mkdir($host_rrd_dir); echo("Created directory : $host_rrd_dir\n"); }

  $flags = OBS_DNS_ALL;
  if ($device['snmp_transport'] == 'udp6' || $device['snmp_transport'] == 'tcp6') // Exclude IPv4 if used transport 'udp6' or 'tcp6'
  {
    $flags = $flags ^ OBS_DNS_A;
  }
  $attribs['ping_skip'] = isset($attribs['ping_skip']) && $attribs['ping_skip'];
  if ($attribs['ping_skip'])
  {
    $flags = $flags | OBS_PING_SKIP; // Add skip ping flag
  }
  $device['pingable'] = isPingable($device['hostname'], $flags);
  if ($device['pingable'])
  {
    $device['snmpable'] = isSNMPable($device);
    if ($device['snmpable'])
    {
      $ping_msg = ($attribs['ping_skip'] ? '' : 'PING (' . $device['pingable'] . 'ms) and ');

      print_cli_data("Device status", "Device is reachable by " . $ping_msg . "SNMP (".$device['snmpable']."ms)", 1);
      $status = "1";
      $status_type = '';
    } else {
      print_cli_data("Device status", "Device is not responding to SNMP requests", 1);
      $status = "0";
      $status_type = 'snmp';
    }
  } else {
    print_cli_data("Device status", "Device is not responding to PINGs", 1);
    $status = "0";
    $status_type = 'ping';
  }

  if ($device['status'] != $status)
  {
    dbUpdate(array('status' => $status), 'devices', 'device_id = ?', array($device['device_id']));
    // dbInsert(array('importance' => '0', 'device_id' => $device['device_id'], 'message' => "Device is " .($status == '1' ? 'up' : 'down')), 'alerts');

    $event_msg = 'Device status changed to ';
    if ($status == '1')
    {
      // Device Up, Severity Warning (4)
      $event_msg .= 'Up';
      $event_severity = 4;
    } else {
      // Device Down, Severity Error (3)!
      $event_msg .= 'Down';
      $event_severity = 3;
    }
    if ($status_type != '') { $event_msg .= ' (' . $status_type . ')'; }
    log_event($event_msg, $device, 'device', $device['device_id'], $event_severity);
  }

  $rrd_filename = "status.rrd";

  rrdtool_create($device, $rrd_filename, "DS:status:GAUGE:600:0:1 ");

  if ($status == "1" || $status == "0")
  {
    rrdtool_update($device, $rrd_filename, "N:".$status);
  } else {
    rrdtool_update($device, $rrd_filename, "N:U");
  }

  if (!$attribs['ping_skip'])
  {
    // Ping response RRD database.
    $ping_rrd = 'ping.rrd';
    rrdtool_create($device, $ping_rrd, "DS:ping:GAUGE:600:0:65535 " );

    if ($device['pingable'])
    {
      rrdtool_update($device, $ping_rrd,"N:".$device['pingable']);
    } else {
      rrdtool_update($device, $ping_rrd,"N:U");
    }
  }

  // SNMP response RRD database.
  $ping_snmp_rrd = 'ping_snmp.rrd';
  rrdtool_create($device, $ping_snmp_rrd, "DS:ping_snmp:GAUGE:600:0:65535 " );

  if ($device['snmpable'])
  {
    rrdtool_update($device, $ping_snmp_rrd,"N:".$device['snmpable']);
  } else {
    rrdtool_update($device, $ping_snmp_rrd,"N:U");
  }

  $alert_metrics['device_status'] = $status;
  $alert_metrics['device_status_type'] = $status_type;
  $alert_metrics['device_ping'] = $device['pingable']; // FIXME, when ping skipped, here always 0.001
  $alert_metrics['device_snmp'] = $device['snmpable'];

  if ($status == "1")
  {
    // Arrays for store and check enabled/disabled graphs
    $graphs    = array();
    $graphs_db = array();
    foreach (dbFetchRows("SELECT * FROM `device_graphs` WHERE `device_id` = ?", array($device['device_id'])) as $entry)
    {
      $graphs_db[$entry['graph']] = (isset($entry['enabled']) ? (bool)$entry['enabled'] : TRUE);
    }

    if (!$attribs['ping_skip'])
    {
      // Enable Ping graphs
      $graphs['ping'] = TRUE;
    }

    // Enable SNMP graphs
    $graphs['ping_snmp'] = TRUE;

    // Run these base modules always and before all other modules!
    $poll_modules = array('system', 'os');

    $mods_disabled_global = array();
    $mods_disabled_device = array();
    $mods_excluded        = array();

    if ($options['m'])
    {
      foreach (explode(',', $options['m']) as $module)
      {
        $module = trim($module);
        if (in_array($module, $poll_modules)) { continue; } // Skip already added modules
        if ($module == 'unix-agent')
        {
          array_unshift($poll_modules, $module);            // Add 'unix-agent' before all
          continue;
        }
        if (is_file($config['install_dir'] . "/includes/polling/$module.inc.php"))
        {
          $poll_modules[] = $module;
        }
      }
    } else {
      foreach ($config['poller_modules'] as $module => $module_status)
      {
        if (in_array($module, $poll_modules)) { continue; } // Skip already added modules
        if ($attribs['poll_'.$module] || ($module_status && !isset($attribs['poll_'.$module])))
        {
          if (poller_module_excluded($device, $module))
          {
            $mods_excluded[] = $module;
            //print_warning("Module [ $module ] excluded for device.");
            continue;
          }
          if ($module == 'unix-agent')
          {
            array_unshift($poll_modules, $module);          // Add 'unix-agent' before all
            continue;
          }
          if (is_file($config['install_dir'] . "/includes/polling/$module.inc.php"))
          {
            $poll_modules[] = $module;
          }
        }
        elseif (isset($attribs['poll_'.$module]) && !$attribs['poll_'.$module])
        {
          $mods_disabled_device[] = $module;
          //print_warning("Module [ $module ] disabled on device.");
        } else {
          $mods_disabled_global[] = $module;
          //print_warning("Module [ $module ] disabled globally.");
        }
      }

    }

    if (count($mods_excluded)) { print_cli_data("Modules Excluded", implode(", ", $mods_excluded), 1); }
    if (count($mods_disabled_global)) { print_cli_data("Disabled Globally", implode(", ", $mods_disabled_global), 1); }
    if (count($mods_disabled_device)) { print_cli_data("Disabled Device", implode(", ", $mods_disabled_global), 1); }
    if (count($poll_modules)) { print_cli_data("Modules Enabled", implode(", ", $poll_modules), 1); }

    echo(PHP_EOL);

    foreach ($poll_modules as $module)
    {
      print_debug(PHP_EOL . "including: includes/polling/$module.inc.php");

      print_cli_heading("Module Start: %R".$module."");

      $m_start = utime();

      /* insert your logic to choose between normal and cached polling*/
      if ($module == 'ports' && $device['hardware'] == 'BROKEN-SW-HW') {
          include($config['install_dir'] . "/includes/polling/ports-cached.inc.php");
      } else {
        include($config['install_dir'] . "/includes/polling/$module.inc.php");
      }

      $m_end   = utime();

      $m_run   = round($m_end - $m_start, 4);
      $device_state['poller_mod_perf'][$module] = number_format($m_run, 4);
      print_cli_data("Module time", "$m_run"."s");

      echo(PHP_EOL);

    }

    print_cli_heading($device['hostname']. " [" . $device['device_id'] . "] completed poller modules at " . date("Y-m-d H:i:s"), 1);

    // Check and update graphs DB
    $graphs_stat = array();

    if (!isset($options['m']))
    {
      // Hardcoded poller performance
      $graphs['poller_perf'] = TRUE;

      // Delete not exists graphs from DB (only if poller run without modules option)
      foreach ($graphs_db as $graph => $value)
      {
        if (!isset($graphs[$graph]))
        {
          dbDelete('device_graphs', "`device_id` = ? AND `graph` = ?", array($device['device_id'], $graph));
          unset($graphs_db[$graph]);
          $graphs_stat['deleted'][] = $graph;
        }
      }
    }

    // Add or update graphs in DB
    foreach ($graphs as $graph => $value)
    {
      if (!isset($graphs_db[$graph]))
      {
        dbInsert(array('device_id' => $device['device_id'], 'graph' => $graph, 'enabled' => $value), 'device_graphs');
        $graphs_stat['added'][] = $graph;
      }
      else if ($value != $graphs_db[$graph])
      {
        dbUpdate(array('enabled' => $value), 'device_graphs', '`device_id` = ? AND `graph` = ?', array($device['device_id'], $graph));
        $graphs_stat['updated'][] = $graph;
      } else {
        $graphs_stat['checked'][] = $graph;
      }
    }

    // Print graphs stats
    foreach ($graphs_stat as $key => $stat)
    {
      if (count($stat)) { print_cli_data('Graphs ['.$key.']', implode(', ', $stat), 1); }
    }

    $device_end = utime(); $device_run = $device_end - $device_start; $device_time = round($device_run, 4);

    $update_array['last_polled'] = array('NOW()');
    $update_array['last_polled_timetaken'] = $device_time;

    $update_array['device_state'] = serialize($device_state);

    #echo("$device_end - $device_start; $device_time $device_run");

    print_cli_data("Poller time", $device_time." seconds", 1);
    //print_message(PHP_EOL."Polled in $device_time seconds");

    // Only store performance data if we're not doing a single-module poll
    if (!$options['m'])
    {
      dbInsert(array('device_id' => $device['device_id'], 'operation' => 'poll', 'start' => $device_start, 'duration' => $device_run), 'devices_perftimes');

      $poller_rrd = "perf-poller.rrd";
      rrdtool_create($device, $poller_rrd, "DS:val:GAUGE:600:0:38400 ");
      rrdtool_update($device, $poller_rrd, "N:".$device_time);
    }

    if (OBS_DEBUG) {
      echo("Updating " . $device['hostname'] . " - ");
      print_vars($update_array);
      echo(" \n"); }

    $updated = dbUpdate($update_array, 'devices', '`device_id` = ?', array($device['device_id']));

    if ($updated) {
      print_cli_data("Updated Data", implode(", ", array_keys($update_array)), 1);
    //echo("UPDATED!\n");
    }

    $alert_metrics['device_uptime']   = $device['uptime'];
    $alert_metrics['device_rebooted'] = $rebooted; // 0 - not rebooted, 1 - rebooted
    $alert_metrics['device_duration_poll'] = $device['last_polled_timetaken'];

    unset($cache_storage); // Clear cache of hrStorage ** MAYBE FIXME? ** (ok, later)
    unset($cache); // Clear cache (unify all things here?)
  }

  check_entity('device', $device, $alert_metrics);

  echo(PHP_EOL);

  unset($alert_metrics);
}

///FIXME. It's not a very nice solution, but will approach as temporal.
// Function return FALSE, if poller module allowed for device os (otherwise TRUE).
function poller_module_excluded($device, $module)
{
  ///FIXME. rename module: 'wmi' -> 'windows-wmi'
  if ($module == 'wmi'  && $device['os'] != 'windows') { return TRUE; }

  if ($module == 'ipmi' && !($device['os_group'] == 'unix' || $device['os'] == 'drac' || $device['os'] == 'windows' || $device['os'] == 'generic')) { return TRUE; }
  if ($module == 'unix-agent' && !($device['os_group'] == 'unix' || $device['os'] == 'generic')) { return TRUE; }

  $os_test = explode('-', $module, 2);
  if (count($os_test) === 1) { return FALSE; } // Check modules only with a dash.
  list($os_test) = $os_test;

  ///FIXME. rename module: 'cipsec-tunnels' -> 'cisco-ipsec-tunnels'
  if (($os_test == 'cisco' || $os_test == 'cipsec') && $device['os_group'] != 'cisco') { return TRUE; }
  //$os_groups = array('cisco', 'unix');
  //foreach ($os_groups as $os_group)
  //{
  //  if ($os_test == $os_group && $device['os_group'] != $os_group) { return TRUE; }
  //}

  $oses = array('junose', 'arista_eos', 'netscaler', 'arubaos');
  foreach ($oses as $os)
  {
    if (strpos($os, $os_test) !== FALSE && $device['os'] != $os) { return TRUE; }
  }

  return FALSE;
}

/**
 * Poll a table or oids from SNMP and build an RRD based on an array of arguments.
 *
 * Current limitations:
 *  - single MIB and RRD file for all graphs
 *  - single table per MIB
 *  - if set definition 'call_function', than poll used specific function for snmp walk/get,
 *    else by default used snmpwalk_cache_oid()
 *  - allowed oids only with simple numeric index (oid.0, oid.33), NOT allowed (oid.1.2.23)
 *  - only numeric data
 *
 * Example of (full) args array:
 *  array(
 *   'file'          => 'someTable.rrd',              // [MANDATORY] RRD filename, but if not set used MIB_table.rrd as filename
 *   'call_function' => 'snmpwalk_cache_oid'          // [OPTIONAL] Which function to use for snmp poll, bu default snmpwalk_cache_oid()
 *   'mib'           => 'SOMETHING-MIB',              // [OPTIONAL] MIB or list of MIBs separated by a colon
 *   'mib_dir'       => 'something',                  // [OPTIONAL] OS MIB directory or array of directories
 *   'graphs'        => array('one','two'),           // [OPTIONAL] List of graph_types that this table provides
 *   'table'         => 'someTable',                  // [RECOMENDED] Table name for OIDs
 *   'numeric'       => '.1.3.6.1.4.1.555.4.1.1.48',  // [OPTIONAL] Numeric table OID
 *   'ds_rename'     => array('http' => ''),          // [OPTIONAL] Array for renaming OIDs to DSes
 *   'oids'          => array(                        // List of OIDs you can use as key: full OID name
 *     'someOid' => array(                                 // OID name (You can use OID name, like 'cpvIKECurrSAs')
 *       'descr'     => 'Current IKE SAs',                 // [OPTIONAL] Description of the OID contents
 *       'numeric'   => '.1.3.6.1.4.1.555.4.1.1.48.45',    // [OPTIONAL] Numeric OID
 *       'index'     => '0',                               // [OPTIONAL] OID index, if not set equals '0'
 *       'ds_name'   => 'IKECurrSAs',                      // [OPTIONAL] DS name, if not set used OID name truncated to 18 chars
 *       'ds_type'   => 'GAUGE',                           // [OPTIONAL] DS type, if not set equals 'COUNTER'
 *       'ds_min'    => '0',                               // [OPTIONAL] Min value for DS, if not set equals 'U'
 *       'ds_max'    => '30000'                            // [OPTIONAL] Max value for DS, if not set equals '100000000000'
 *    )
 *  )
 *
 */

function collect_table($device, $oids_def, &$graphs)
{
  $rrd      = array();
  $mib      = NULL;
  $mib_dirs = NULL;
  $use_walk = isset($oids_def['table']) && $oids_def['table']; // Use snmpwalk by default
  $call_function = strtolower($oids_def['call_function']);
  switch ($call_function)
  {
    case 'snmp_get_multi':
      $use_walk = FALSE;
      break;
    case 'snmpwalk_cache_oid':
    default:
      $call_function = 'snmpwalk_cache_oid';
      if (!$use_walk)
      {
        // Break because we should use snmpwalk, but walking table not set
        return FALSE;
      }
  }
  if (isset($oids_def['numeric'])) { $oids_def['numeric'] = '.'.trim($oids_def['numeric'], '. '); } // Remove trailing dot
  if (isset($oids_def['mib']))     { $mib      = $oids_def['mib']; }
  if (isset($oids_def['mib_dir'])) { $mib_dirs = mib_dirs($oids_def['mib_dir']); }
  if (isset($oids_def['file']))
  {
    $rrd_file = $oids_def['file'];
  }
  else if ($mib && isset($oids_def['table']))
  {
    // Try to use MIB & tableName as rrd_file
    $rrd_file = strtolower(safename($mib.'_'.$oids_def['table'])).'.rrd';
  } else {
    print_debug("  WARNING, not have rrd filename.");
    return FALSE; // Not have RRD filename
  }

  // Get MIBS/Tables/OIDs permissions
  if ($use_walk)
  {
    // if use table walk, than check only this table permission (not oids)
    if (dbFetchCell("SELECT COUNT(*) FROM `devices_mibs` WHERE `device_id` = ? AND `mib` = ? AND `table_name` = ?
                    AND (`oid` = '' OR `oid` IS NULL) AND `disabled` = '1'", array($device['device_id'], $mib, $oids_def['table'])))
    {
      print_debug("  WARNING, table '".$oids_def['table']."' for '$mib' disabled and skipped.");
      return FALSE; // table disabled, exit
    }
    $oids_ok = TRUE;
  } else {
    // if use multi_get, than get all disabled oids
    $oids_disabled = dbFetchColumn("SELECT `oid` FROM `devices_mibs` WHERE `device_id` = ? AND `mib` = ?
                                   AND (`oid` != '' AND `oid` IS NOT NULL) AND `disabled` = '1'", array($device['device_id'], $mib));
    $oids_ok = empty($oids_disabled); // if empty disabled, than set to TRUE
  }

  $search  = array();
  $replace = array();
  if (is_array($oids_def['ds_rename']))
  {
    foreach ($oids_def['ds_rename'] as $s => $r)
    {
      $search[]  = $s;
      $replace[] = $r;
    }
  }

  $oids       = array();
  $oids_index = array();
  foreach ($oids_def['oids'] as $oid => $entry)
  {
    //if (!isset($entry['descr']))   { $entry['descr'] = ''; }   // Descr not used in any case
    if (is_numeric($entry['numeric']) && isset($oids_def['numeric']))
    {
      $entry['numeric'] = $oids_def['numeric'] . '.' . $entry['numeric']; // Numeric oid, for future using
    }
    if (!isset($entry['index']))   { $entry['index'] = '0'; }
    if (!isset($entry['ds_type'])) { $entry['ds_type'] = 'COUNTER'; }
    if (!isset($entry['ds_min']))  { $entry['ds_min']  = 'U'; }
    if (!isset($entry['ds_max']))  { $entry['ds_max']  = '100000000000'; }
    if (!isset($entry['ds_name']))
    {
      // Convert OID name to DS name
      $ds_name = $oid;
      if (is_array($oids_def['ds_rename'])) { $ds_name = str_replace($search, $replace, $ds_name); }
    } else {
      $ds_name = $entry['ds_name'];
    }
    $ds_len = ($mib != 'NS-ROOT-MIB' ? 19 : 18); // Hardcode max len for NS-ROOT-MIB to 18 chars
    if (strlen($ds_name) > $ds_len) { $ds_name = truncate($ds_name, $ds_len, ''); }

    if (isset($oids_def['no_index']) && $oids_def['no_index'] == TRUE)
    {
      $oids[]       = $oid;
    } else {
      $oids[]       = $oid.'.'.$entry['index'];
    }
    $oids_index[] = array('index' => $entry['index'], 'oid' => $oid);

    if (!$use_walk)
    {
      // Check permissions for snmp_get_multi _ONLY_
      // if at least one oid missing in $oids_disabled than TRUE
      $oids_ok = $oids_ok || !in_array($oid, $oids_disabled);
    }

    $rrd['rrd_create'][] = ' DS:'.$ds_name.':'.$entry['ds_type'].':600:'.$entry['ds_min'].':'.$entry['ds_max'];
    if ($GLOBALS['debug']) { $rrd['ds_list'][] = $ds_name; } // Make DS lists for compare with RRD file in debug
  }

  if (!$use_walk && !$oids_ok)
  {
    print_debug("  WARNING, oids '".implode("', '", array_keys($oids_def['oids']))."' for '$mib' disabled and skipped.");
    return FALSE;  // All oids disabled, exit
  }

  switch ($call_function)
  {
    case 'snmpwalk_cache_oid':
      $data = snmpwalk_cache_oid($device, $oids_def['table'], array(), $mib, $mib_dirs);
      break;
    case 'snmp_get_multi':
      $data = snmp_get_multi($device, $oids, "-OQUs", $mib, $mib_dirs);
      break;
  }
  if (isset($GLOBALS['exec_status']['exitcode']) && $GLOBALS['exec_status']['exitcode'] !== 0)
  {
    // Break because latest snmp walk/get return not good exitstatus (wrong mib/timeout/error/etc)
    print_debug("  WARNING, latest snmp walk/get return not good exitstatus for '$mib', RRD update skipped.");
    return FALSE;
  }
  if (isset($oids_def['no_index']) && $oids_def['no_index'] == TRUE)
  {
    $data[0] = $data[''];
  }
  foreach ($oids_index as $entry)
  {
    $index = $entry['index'];
    $oid   = $entry['oid'];
    if (is_numeric($data[$index][$oid]))
    {
      $rrd['ok']           = TRUE; // We have any data for current rrd_file
      $rrd['rrd_update'][] = $data[$index][$oid];
    } else {
      $rrd['rrd_update'][] = 'U';
    }
  }

  // Ok, all previous checks done, update RRD, table/oids permissions, $graphs
  if (isset($rrd['ok']) && $rrd['ok'])
  {
    // Create/update RRD file
    $rrd_create = implode('', $rrd['rrd_create']);
    $rrd_update = 'N:'.implode(':', $rrd['rrd_update']);
    rrdtool_create($device, $rrd_file, $rrd_create);
    rrdtool_update($device, $rrd_file, $rrd_update);

    foreach ($oids_def['graphs'] as $graph)
    {
      $graphs[$graph] = TRUE; // Set all graphs to TRUE
    }

    // Compare DSes form RRD file with DSes from array
    if (OBS_DEBUG)
    {
      $graph_template  = "\$config['graph_types']['device']['GRAPH_CHANGE_ME'] = array(\n";
      $graph_template .= "  'file'      => '$rrd_file',\n";
      $graph_template .= "  'ds'        => array(\n";
      $rrd_file_info = rrdtool_file_info(get_rrd_path($device, $rrd_file));
      foreach ($rrd_file_info['DS'] as $ds => $nothing)
      {
        $ds_list[] = $ds;
        $graph_template .= "    '$ds' => array('label' => 'CHANGE_ME'),\n";
      }
      $graph_template .= "  )\n);";
      $in_args = array_diff($rrd['ds_list'], $ds_list);
      if ($in_args)
      {
        print_message("%rWARNING%n, in file '%W".$rrd_file_info['filename']."%n' different DS lists. NOT have: ".implode(', ', $in_args));
      }
      $in_file = array_diff($ds_list, $rrd['ds_list']);
      if ($in_file)
      {
        print_message("%rWARNING%n, in file '%W".$rrd_file_info['filename']."%n' different DS lists. Excess: ".implode(', ', $in_file));
      }

      // Print example for graph template using rrd_file and ds list
      print_message($graph_template);
    }
  }
  else if ($use_walk)
  {
    // Table NOT exist on device!
    // Disable polling table (only if table not enabled manually in DB)
    if (!dbFetchCell("SELECT COUNT(*) FROM `devices_mibs` WHERE `device_id` = ? AND `mib` = ?
                     AND `table_name` = ? AND (`oid` = '' OR `oid` IS NULL)", array($device['device_id'], $mib, $oids_def['table'])))
    {
      dbInsert(array('device_id' => $device['device_id'], 'mib' => $mib,
                     'table_name' => $oids_def['table'], 'disabled' => '1'), 'devices_mibs');
    }
    print_debug("  WARNING, table '".$oids_def['table']."' for '$mib' disabled.");
  } else {
    // OIDs NOT exist on device!
    // Disable polling oids (only if table not enabled manually in DB)
    foreach (array_keys($oids_def['oids']) as $oid)
    {
      if (!dbFetchCell("SELECT COUNT(*) FROM `devices_mibs` WHERE `device_id` = ? AND `mib` = ?
                       AND `oid` = ?", array($device['device_id'], $mib, $oid)))
      {
        dbInsert(array('device_id' => $device['device_id'], 'mib' => $mib,
                       'oid' => $oid, 'disabled' => '1'), 'devices_mibs');
      }
    }
    print_debug("  WARNING, oids '".implode("', '", array_keys($oids_def['oids']))."' for '$mib' disabled.");
  }

  // Return obtained snmp data
  return $data;
}

// Poll a table from SNMP and build an RRD based on an array of arguments.

function collect_table_old($args, $device, &$graphs)
{

  $data = snmpwalk_cache_oid($device, $args['table'], array(), $args['mib']);

  echo("Collecting: ".$args['table']." ");

  $rrd_update = "N";

  $search  = array();
  $replace = array();
  if (is_array($args['ds_rename']))
  {
    foreach ($args['ds_rename'] AS $s => $r)
    {
      $search[]  = $s;
      $replace[] = $r;
    }
  }

  foreach ($args['ds_list'] as $ds_name => $ds_data)
  {

    if (!isset($ds_data['type'])) { $ds_data['type'] = 'COUNTER'; }
    if (!isset($ds_data['min']))  { $ds_data['min']  = 'U'; }
    if (!isset($ds_data['max']))  { $ds_data['max']  = '100000000000'; }

    if (is_array($args['ds_rename'])) { $ds = str_replace($search, $replace, $ds_name); } else { $ds = $ds_name; }
    if (strlen($ds) > 18) { $ds = truncate($ds, 18, ''); }

    $rrd_create .= ' DS:'.$ds.':'.$ds_data['type'].':600:'.$ds_data['min'].':'.$ds_data['max'];

    if (is_numeric($data[0][$ds_name]))
    {
      $rrd_update .= ":".$data[0][$ds_name];
    } else {
      $rrd_update .= ":U";
    }

  }

  rrdtool_create($device, $args['file'], $rrd_create);
  rrdtool_update($device, $args['file'], $rrd_update);

  // We should only create a graph when the OID was present -- FIXME :)
  foreach ($args['graphs'] as $g) { $graphs[$g] = TRUE; }
}

function poll_p2p_radio($device, $mib, $index, $radio)
{

  $params  = array('radio_tx_freq', 'radio_rx_freq', 'radio_tx_power', 'radio_rx_level', 'radio_name', 'radio_bandwidth', 'radio_modulation', 'radio_total_capacity', 'radio_standard', 'radio_loopback', 'radio_tx_mute', 'radio_eth_capacity', 'radio_e1t1_channels', 'radio_cur_capacity');

  if (is_array($GLOBALS['cache']['p2p_radios'][$mib][$index])) { $radio_db = $GLOBALS['cache']['p2p_radios'][$mib][$index]; }

  // Update the Database

  if (!isset($radio_db['radio_id']))  // If we don't have an entry already, create it
  {
    $insert = array();
    $insert['device_id'] = $device['device_id'];
    $insert['radio_mib'] = $mib;
    $insert['radio_index'] = $index;

    foreach ($params as $param)
    {
      $insert[$param] = $radio[$param];
      if ($radio[$param] == NULL) { $insert[$param] = array('NULL'); }
    }

    $radio_id = dbInsert($insert, 'p2p_radios');
    echo("+");

  } else {  // If we already have an entry, check if it needs updating

    $update = array();
    foreach ($params as $param)
    {
      if ($radio[$param] != $radio_db[$param]) { $update[$param] = $radio[$param]; }
    }
    if (count($update)) // If there have been changes, update it
    {
      dbUpdate($update, 'p2p_radios', '`radio_id` = ?', array($radio_db['radio_id']));
      echo('U');
    } else {
      echo('.');
    }
  }

  // Create RRD files

  $dses = array('tx_power'             => array('type' => 'gauge'),
                'rx_level'             => array('type' => 'gauge'),
                'rmse'                 => array('type' => 'gauge'),
                'agc_gain'             => array('type' => 'gauge'),
                'cur_capacity'         => array('type' => 'gauge'),
                'sym_rate_tx'          => array('type' => 'gauge'),
                'sym_rate_rx'          => array('type' => 'gauge'),
  );

  $rrd_file = "p2p_radio-" . $mib . "-" . $index . ".rrd";
  $rrd_update = "N";
  $rrd_create = "";

  foreach ($dses as $ds => $ds_data)
  {

    $field = "radio_".$ds;

    $radio[$ds] = $radio[$oid];

    if ($ds_data['type'] == 'gauge')
    {
      $rrd_create .= " DS:" . $ds . ":GAUGE:600:U:100000000000";
    }
    else
    {
      $rrd_create .= " DS:" . $ds . ":COUNTER:600:U:100000000000";
    }

    if (is_numeric($radio[$field]))
    {
      $rrd_update .= ":" . $radio[$field];
    }
    else
    {
      $rrd_update .= ":U";
    }
  }

  rrdtool_create($device, $rrd_file, $rrd_create);
  rrdtool_update($device, $rrd_file, $rrd_update);

  $GLOBALS['valid']['p2p_radio'][$mib][$index] = 1; // FIXME. What? How it passed there?

}

// EOF
