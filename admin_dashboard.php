<?php
// admin_dashboard.php - Forwarder tương thích ngược cho admin/dashboard.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: admin/dashboard.php' . $qs);
exit;
