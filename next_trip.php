<?php
// next_trip.php - Forwarder tương thích ngược cho views/next_trip.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: views/next_trip.php' . $qs);
exit;
