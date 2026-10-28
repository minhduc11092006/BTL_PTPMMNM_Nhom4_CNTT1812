<?php
// admin_dashboard.php - Chuyển hướng phân tầng sang admin/dashboard.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: dashboard.php' . $qs);
exit;
