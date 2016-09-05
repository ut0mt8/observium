<?php

## Have a look in defaults.inc.php for examples of settings you can set here. DO NOT EDIT defaults.inc.php!

### Database config
$config['db_extension']  = 'mysqli';
$config['db_host'] = "10.0.0.2";
$config['db_user'] = "observium";
$config['db_pass'] = "xxxxxxx";
$config['db_name'] = "observium";

### Memcached config - We use this to store realtime usage
$config['memcached']['enable']  = FALSE;
$config['memcached']['host']    = "localhost";
$config['memcached']['port']    = 11211;

### Locations - it is recommended to keep the default
$config['install_dir']  = "/data/tools/observium";

### Default community
$config['snmp']['community'] = array("xxxxx");

### Authentication Model
$config['auth_mechanism'] = "mysql"; # default, other options: ldap, http-auth

# poller-wrapper is released public domain
$config['poller-wrapper']['alerter'] = FALSE;
# Uncomment the next line to disable daily updates
$config['update'] = 0;

# maps

$config['geocoding']['api']                = 'google';

$config['title_image']      = "images/observium-logo.png";

$config['frontpage']['eventlog']['items']          = 30;           // Only show the last XX items of the eventlog view
$config['frontpage']['syslog']['items']            = 40;

# customn bad int
include 'bad-int.php';

?>
