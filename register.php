<?php
// register.php - Forwarder tương thích ngược cho auth/register.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: auth/register.php' . $qs);
exit;
