<?php
// saved_queries.php - Forwarder hợp nhất vào views/map.php?tab=saved
$params = $_GET;
$params['tab'] = 'saved';
$qs = !empty($params) ? ('?' . http_build_query($params)) : '';
header('Location: views/map.php' . $qs);
exit;
