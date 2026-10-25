<?php
// book_ticket.php - Forwarder tương thích ngược cho views/book_ticket.php
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: views/book_ticket.php' . $qs);
exit;
