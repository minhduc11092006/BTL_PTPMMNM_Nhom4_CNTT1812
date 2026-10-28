<?php
// gtfs_browse.php - Forwarder tương thích ngược cho views/gtfs_browse.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: views/gtfs_browse.php' . $qs);
exit;
