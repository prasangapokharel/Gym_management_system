<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: ../index.php");
    exit;
}

$error = '';
$success = '';

// Get admin details
try {
    $stmt = $conn->prepare("SELECT username, email, fullname FROM admins WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();
    } else {
        throw new Exception("Admin not found");
    }
    $stmt->close();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $fullname = sanitizeInput($_POST['fullname']);
        $email = sanitizeInput($_POST['email']);
        
        if (empty($fullname) || empty($email)) {
            $error = "All fields are required";
        } else {
            try {
                // Check if email already exists for another admin
                $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("si", $email, $_SESSION['admin_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $error = "Email already exists";
                } else {
                    // Update admin profile
                    $stmt = $conn->prepare("UPDATE admins SET fullname = ?, email = ? WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception("Database error: " . $conn->error);
                    }
                    $stmt->bind_param("ssi", $fullname, $email, $_SESSION['admin_id']);
                    
                    if ($stmt->execute()) {
                        $success = "Profile updated successfully";
                        // Update admin data
                        $admin['fullname'] = $fullname;
                        $admin['email'] = $email;
                        
                        // Log activity
                        logActivity($conn, $_SESSION['admin_id'], 'Updated profile');
                    } else {
                        $error = "Failed to update profile";
                    }
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All password fields are required";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long";
        } else {
            try {
                // Verify current password
                $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("i", $_SESSION['admin_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $admin_data = $result->fetch_assoc();
                $stmt->close();
                
                if (password_verify($current_password, $admin_data['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    if (!$stmt) {
                        throw new Exception("Database error: " . $conn->error);
                    }
                    $stmt->bind_param("si", $hashed_password, $_SESSION['admin_id']);
                    
                    if ($stmt->execute()) {
                        $success = "Password changed successfully";
                        
                        // Log activity
                        logActivity($conn, $_SESSION['admin_id'], 'Changed password');
                    } else {
                        $error = "Failed to change password";
                    }
                    $stmt->close();
                } else {
                    $error = "Current password is incorrect";
                }
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
        <?php include 'sidebar.php'; ?>
            
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Admin Profile</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle me-1"></i>
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                                <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Edit Profile</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username</label>
                                        <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($admin['username']); ?>" disabled>
                                        <div class="form-text">Username cannot be changed</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="fullname" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="fullname" name="fullname" value="<?php echo htmlspecialchars($admin['fullname']); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                    </div>
                                    <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Change Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                        <div class="form-text">Password must be at least 8 characters long</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>

