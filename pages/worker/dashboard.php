<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] != 'worker') {
    header('Location: ../../login.php');
    exit;
}

echo "Welcome, Worker!";
?>

<a href="../../logout.php">Logout</a>
<a href="offers.php">Employment Offers</a>
<a href="employment.php">My Employment</a>
<a href="tasks.php">My Tasks</a>
<a href="time-tracker.php">Time Tracker</a>
<a href="timesheets.php">Time Sheets</a>
<a href="earnigs.php">earnings</a>