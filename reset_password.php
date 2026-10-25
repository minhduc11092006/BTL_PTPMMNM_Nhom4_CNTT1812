<?php
// reset_password.php - Forwarder tương thích ngược cho auth/reset_password.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: auth/reset_password.php' . $qs);
exit;
