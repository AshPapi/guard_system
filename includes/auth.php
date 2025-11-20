<?php
session_start();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
