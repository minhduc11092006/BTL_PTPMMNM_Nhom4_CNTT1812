<?php
// admin_gtfs.php - Forwarder tương thích ngược cho admin/gtfs.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: admin/gtfs.php' . $qs);
exit;
