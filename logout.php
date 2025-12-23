<?php
require_once 'config.php';

// Destroy session and redirect to login
session_destroy();
header('Location: login.php');
exit();
?>