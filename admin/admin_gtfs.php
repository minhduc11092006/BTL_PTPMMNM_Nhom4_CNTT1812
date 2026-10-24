<?php
// admin_gtfs.php - Chuyển hướng phân tầng sang admin/gtfs.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: admin/gtfs.php' . $qs);
exit;
