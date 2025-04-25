<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    redirectToDashboard();
}

$error = '';
$success = '';
$token = isset($_GET['token']) ? $_GET['token'] : '';
$valid_token = false;

if (empty($token)) {
    $error = "Invalid or missing token";
} else {
    try {
        // Check if token exists and is valid
        $stmt = $conn->prepare("SELECT pr.admin_id, a.username FROM password_resets pr 
                             JOIN admins a ON pr.admin_id = a.id 
                             WHERE pr.token = ? AND pr.expiry > NOW() AND pr.used = 0");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $valid_token = true;
            $reset_data = $result->fetch_assoc();
            $admin_id = $reset_data['admin_id'];
            $username = $reset_data['username'];
        } else {
            $error = "Invalid or expired token";
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $error = "System error: " . $e->getMessage();
    }
}

// Handle reset password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($password) || empty($confirm_password)) {
        $error = "Please enter both password fields";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } else {
        try {
            // Hash new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Update admin password
            $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("si", $hashed_password, $admin_id);
            
            if ($stmt->execute()) {
                // Mark token as used
                $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
                $stmt->bind_param("s", $token);
                $stmt->execute();
                
                // Log password reset
                logActivity($conn, $admin_id, 'Reset password');
                
                $success = "Your password has been reset successfully. You can now login with your new password.";
            } else {
                $error = "Failed to reset password. Please try again.";
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Management System - Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h2 class="text-center mb-4">Reset Password</h2>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                            <div class="text-center mt-3">
                                <a href="index.php" class="btn btn-primary">Go to Login</a>
                            </div>
                        <?php elseif ($valid_token): ?>
                            <p class="text-center mb-4">Create a new password for <?php echo htmlspecialchars($username); ?></p>
                            
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <div class="form-text">Password must be at least 8 characters long</div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Reset Password</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center mt-3">
                                <a href="forgot-password.php" class="btn btn-primary">Request New Reset Link</a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3 text-center">
                            <a href="index.php" class="text-decoration-none">Back to Login</a>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-3 text-muted">
                    <small>&copy; <?php echo date('Y'); ?> Gym Management System</small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>

