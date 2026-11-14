<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] != 'employer') {
    header('Location: ../../login.php');
    exit;
}

echo "Welcome, Employer!";
?>

<a href="../../logout.php">Logout</a>

<a href="applications.php">Manage Applications</a>
<a href="employees.php">My Employees</a>
<a href="assign-task.php">Assign Task</a>
<a href="tasks.php">Task Management</a>
<a href="timesheets.php">Timesheets</a>
<a href="time-proofs.php">Daily Time Proofs</a>
<a href="earnings.php">Labor Costs</a>