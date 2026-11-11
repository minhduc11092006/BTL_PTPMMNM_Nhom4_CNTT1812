<?php
// gtfs_browse.php - Forwarder hợp nhất vào views/map.php?tab=browse
$params = $_GET;
$subtab = ($params['tab'] ?? '') === 'stops' ? 'stops' : 'routes';
$params['tab'] = 'browse';
$params['subtab'] = $subtab;
$qs = !empty($params) ? ('?' . http_build_query($params)) : '';
header('Location: views/map.php' . $qs);
exit;
