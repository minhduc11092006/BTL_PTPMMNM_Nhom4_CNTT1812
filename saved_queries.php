<?php
// saved_queries.php - Forwarder tương thích ngược cho views/saved_queries.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: views/saved_queries.php' . $qs);
exit;
