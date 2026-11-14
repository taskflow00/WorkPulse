<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] != 'worker') {
    header('Location: ../../login.php');
    exit;
}

echo "Welcome, Worker!";
?>

<a href="../../logout.php">Logout</a>