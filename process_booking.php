<?php
// process_booking.php - Forwarder tương thích ngược cho views/process_booking.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/views/process_booking.php';
    exit;
}
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: views/process_booking.php' . $qs);
exit;
