<?php
// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'gym_management';

// Create database connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

