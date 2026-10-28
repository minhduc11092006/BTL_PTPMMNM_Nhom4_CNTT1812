<?php
// login.php - Forwarder tương thích ngược cho auth/login.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: auth/login.php' . $qs);
exit;
