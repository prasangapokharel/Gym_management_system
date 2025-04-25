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

// Handle forgot password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email']);
    
    if (empty($email)) {
        $error = "Please enter your email address";
    } else {
        try {
            // Check if email exists
            $stmt = $conn->prepare("SELECT id, username FROM admins WHERE email = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $admin = $result->fetch_assoc();
                
                // Generate reset token
                $token = generateToken();
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store token in database
                $stmt = $conn->prepare("INSERT INTO password_resets (admin_id, token, expiry) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $admin['id'], $token, $expiry);
                
                if ($stmt->execute()) {
                    // In a real application, you would send an email with the reset link
                    // For this example, we'll just show the token
                    $reset_link = "reset-password.php?token=" . $token;
                    $success = "Password reset link has been sent to your email address. <br>For demo purposes, here's the link: <a href='$reset_link'>$reset_link</a>";
                } else {
                    $error = "Failed to process your request. Please try again.";
                }
            } else {
                // Don't reveal that the email doesn't exist for security reasons
                $success = "If your email address exists in our database, you will receive a password recovery link.";
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
    <title>Gym Management System - Forgot Password</title>
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
                        <h2 class="text-center mb-4">Forgot Password</h2>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php else: ?>
                            <p class="text-center mb-4">Enter your email address and we'll send you a link to reset your password.</p>
                            
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">Send Reset Link</button>
                                </div>
                            </form>
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

