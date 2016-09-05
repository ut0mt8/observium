<?php

/**
 * Observium
 *
 *   This file is part of Observium.
 *
 * @package    observium
 * @subpackage graphs
 * @copyright  (C) 2006-2013 Adam Armstrong, (C) 2013-2016 Observium Limited
 *
 */

$ds_in = "reads";
$ds_out = "writes";

$colour_area_in = "FF3300";
$colour_line_in = "FF0000";
$colour_area_out = "FF6633";
$colour_line_out = "CC3300";

$colour_area_in_max = "FF6633";
$colour_area_out_max = "FF9966";

$graph_max = 1;

$unit_text = "Ops/sec";

include("includes/graphs/generic_duplex.inc.php");

?>
