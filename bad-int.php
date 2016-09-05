<?php
$config['bad_if'][] = "voip-null";
$config['bad_if'][] = "virtual-";
$config['bad_if'][] = "unrouted";
$config['bad_if'][] = "eobc";
$config['bad_if'][] = "lp0";
$config['bad_if'][] = "-atm";
$config['bad_if'][] = "faith0";
$config['bad_if'][] = "container";
$config['bad_if'][] = "async";
$config['bad_if'][] = "plip";
$config['bad_if'][] = "-physical";
$config['bad_if'][] = "container";
$config['bad_if'][] = "unrouted";
$config['bad_if'][] = "bluetooth";
$config['bad_if'][] = "isatap";
$config['bad_if'][] = "ras";
$config['bad_if'][] = "qos";
$config['bad_if'][] = "span rp";
$config['bad_if'][] = "span sp";
$config['bad_if'][] = "sslvpn";
$config['bad_if'][] = "pppoe-";

// Ignore ports based on ifType. Case-sensitive.
$config['bad_iftype'][] = "voiceEncap";
$config['bad_iftype'][] = "voiceFXO";
$config['bad_iftype'][] = "voiceFXS";
$config['bad_iftype'][] = "voiceOverAtm";
$config['bad_iftype'][] = "voiceOverFrameRelay";
$config['bad_iftype'][] = "voiceOverIp";
$config['bad_iftype'][] = "ds0";
$config['bad_iftype'][] = "ds1";
$config['bad_iftype'][] = "ds3";
$config['bad_iftype'][] = "isdn";
$config['bad_iftype'][] = "lapd";
$config['bad_iftype'][] = "sonet";
$config['bad_iftype'][] = "atmSubInterface";
$config['bad_iftype'][] = "aal5";
$config['bad_iftype'][] = "shdsl";
$config['bad_iftype'][] = "mpls";

$config['bad_if_regexp'][] = "/^ng[0-9]+$/";
$config['bad_if_regexp'][] = "/^sl[0-9]/";

// custom
$config['bad_iftype'][] = "other";
$config['bad_iftype'][] = "tunnel";
$config['bad_iftype'][] = "softwareLoopback";

$config['bad_if_regexp'][] = "/^bme.*/";
$config['bad_if_regexp'][] = "/^me.*/";
$config['bad_if_regexp'][] = "/^em.*/";
$config['bad_if_regexp'][] = "/^vme.*/";
$config['bad_if_regexp'][] = "/^fxp.*/";
$config['bad_if_regexp'][] = "/^lc-.*/";
$config['bad_if_regexp'][] = "/^lo.*/";
$config['bad_if_regexp'][] = "/^lsi.*/";
$config['bad_if_regexp'][] = "/^pfe.*/";
$config['bad_if_regexp'][] = "/^pfh.*/";
$config['bad_if_regexp'][] = "/^vcp-.*/";
$config['bad_if_regexp'][] = "/^xe.*32767$/";

$config['bad_if'][] = "cbp0";
$config['bad_if'][] = "control plane";
$config['bad_if'][] = "demux0";
$config['bad_if'][] = "dsc";
$config['bad_if'][] = "gre";
$config['bad_if'][] = "ipip";
$config['bad_if'][] = "irb";
$config['bad_if'][] = "mtun";
$config['bad_if'][] = "Null0";
$config['bad_if'][] = "pimd";
$config['bad_if'][] = "pime";
$config['bad_if'][] = "pip0";
$config['bad_if'][] = "pp0";
$config['bad_if'][] = "tap";
?>
