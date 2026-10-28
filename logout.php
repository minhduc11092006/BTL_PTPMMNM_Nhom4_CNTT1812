<?php
// logout.php - Forwarder tương thích ngược cho auth/logout.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: auth/logout.php' . $qs);
exit;
