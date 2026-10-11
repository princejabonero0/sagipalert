<?php
require __DIR__ . '/inc/bootstrap.php';
if (!empty($_SESSION['uid'])) audit('logout', 'User logged out');
$_SESSION = [];
session_destroy();
redirect('login.php');
