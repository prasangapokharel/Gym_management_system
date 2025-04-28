<?php
// admin/sms-settings.php

// Include necessary files and configurations
require_once('../functions.php');

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/sms_functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: ../index.php");
    exit;
}

// Database connection


// Initialize variables
$error = '';
$success = '';

// Sanitize input function
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Handle form submission (if any) - Placeholder for future settings updates

// Test SMS functionality
if (isset($_POST['test_sms'])) {
    $test_phone = sanitizeInput($_POST['test_phone']);
    $test_message = sanitizeInput($_POST['test_message']);
    
    if (empty($test_phone)) {
        $error = "Test phone number is required";
    } elseif (empty($test_message)) {
        $error = "Test message is required";
    } else {
        // Send test SMS
        $result = sendSMS($test_phone, $test_message);
        
        if ($result['success']) {
            $success = "Test SMS sent successfully";
            logActivity($conn, $_SESSION['admin_id'], "Sent test SMS to {$test_phone}");
        } else {
            $error = "Failed to send test SMS: " . $result['message'];
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Settings</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <h2>SMS Settings</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Test SMS Form -->
        <h3>Test SMS</h3>
        <form method="post">
            <label for="test_phone">Phone Number:</label>
            <input type="text" name="test_phone" id="test_phone" required><br><br>

            <label for="test_message">Message:</label>
            <textarea name="test_message" id="test_message" required></textarea><br><br>

            <button type="submit" name="test_sms">Send Test SMS</button>
        </form>

        <!-- Future settings form will go here -->

        <p><a href="dashboard.php">Back to Dashboard</a></p>
    </div>
</body>
</html>
