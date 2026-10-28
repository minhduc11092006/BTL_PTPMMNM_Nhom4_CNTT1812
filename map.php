<?php
// map.php - Forwarder tương thích ngược cho views/map.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: views/map.php' . $qs);
exit;
