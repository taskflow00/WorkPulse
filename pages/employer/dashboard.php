<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['role'] != 'employer') {
    header('Location: ../../login.php');
    exit;
}

echo "Welcome, Employer!";
