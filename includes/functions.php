<?php
// Function to check if admin is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

// Function to sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to generate random token for password reset
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Function to get base URL
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = dirname($_SERVER['SCRIPT_NAME']);
    return $protocol . '://' . $host . $script;
}

// Function to redirect to dashboard
function redirectToDashboard() {
    header("Location: admin/dashboard.php");
    exit;
}

// Function to log activity
function logActivity($conn, $admin_id, $action) {
    try {
        // Check if activity_logs table exists, create it if it doesn't
        $check_table = $conn->query("SHOW TABLES LIKE 'activity_logs'");
        if ($check_table->num_rows == 0) {
            $create_table = "CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                action VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
            )";
            $conn->query($create_table);
        }
        
        // Now prepare the statement
        $stmt = $conn->prepare("INSERT INTO activity_logs (admin_id, action) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("is", $admin_id, $action);
            $stmt->execute();
            $stmt->close();
        } else {
            // Log error if prepare fails
            error_log("Failed to prepare statement for activity log: " . $conn->error);
        }
    } catch (Exception $e) {
        // Log any exceptions
        error_log("Error in logActivity function: " . $e->getMessage());
    }
}

/**
 * Format currency in Rupees
 * 
 * @param float $amount The amount to format
 * @param bool $includeSymbol Whether to include the Rs symbol
 * @return string Formatted amount
 */
function formatRupees($amount, $includeSymbol = true) {
    // Format with 2 decimal places and thousands separator
    $formattedAmount = number_format($amount, 2, '.', ',');
    
    // Add Rs symbol if requested
    if ($includeSymbol) {
        return 'Rs ' . $formattedAmount;
    }
    
    return $formattedAmount;
}
