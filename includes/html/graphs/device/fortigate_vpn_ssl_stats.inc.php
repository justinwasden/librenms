<?php

$graph_type = 'fortigate_vpn_ssl_stats';
$graph_subtype = 'bits';

$rrd_pattern = Rrd::name($device['hostname'], "sensor-traffic-fortinet-vpn-ssl-stats-*.rrd");
$rrd_files = glob($rrd_pattern);

if (empty($rrd_files)) {
    return;
}

$rrd_options = [];
$rrd_options[] = '--title="VPN SSL Stats"';
$rrd_options[] = '--vertical-label="Bits/s"';
$rrd_options[] = '--base=1000';
$rrd_options[] = '--lower-limit=0';

$series = [];
foreach ($rrd_files as $rrd_file) {
    $parts = explode('-', basename($rrd_file, '.rrd'));
    $username = $parts[4];
    $series[$username]['in'] = "DEF:in_bits_".$username."=".$rrd_file.":bytes_in:AVERAGE";
    $series[$username]['in'] .= " CDEF:in_bits_".$username."_cdef=in_bits_".$username.",8,*";
    $series[$username]['out'] = "DEF:out_bits_".$username."=".$rrd_file.":bytes_out:AVERAGE";
    $series[$username]['out'] .= " CDEF:out_bits_".$username."_cdef=out_bits_".$username.",8,*";
}

require 'includes/html/graphs/generic_multi_bits_separated.inc.php';

