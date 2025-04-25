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

// Initialize variables
$error = '';
$success = '';
$member = null;
$payment_id = null;

// Check if member ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: members.php");
    exit;
}

$member_id = (int)$_GET['id'];

// Check if required tables exist
$tables_exist = true;
$required_tables = ['membership_plans'];

foreach ($required_tables as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows == 0) {
        $tables_exist = false;
        $error = "Required tables do not exist. Please run the database_update.sql script.";
        break;
    }
}

// Get membership plans for dropdown
$membership_plans = [];
if ($tables_exist) {
    $result = $conn->query("SELECT id, name, duration, price FROM membership_plans ORDER BY duration ASC");
    if ($result) {
        $membership_plans = $result;
    }
}

// Get member details
try {
    $stmt = $conn->prepare("SELECT * FROM gym_members WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: members.php");
        exit;
    }
    
    $member = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_exist) {
    // Get form data
    $first_name = sanitizeInput($_POST['first_name']);
    $last_name = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    $gender = sanitizeInput($_POST['gender']);
    $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $address = sanitizeInput($_POST['address']);
    $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']);
    $emergency_contact_phone = sanitizeInput($_POST['emergency_contact_phone']);
    $membership_id = !empty($_POST['membership_id']) ? (int)$_POST['membership_id'] : null;
    $membership_start_date = !empty($_POST['membership_start_date']) ? $_POST['membership_start_date'] : null;
    $status = sanitizeInput($_POST['status']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate input
    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Check if email already exists for another member
            $stmt = $conn->prepare("SELECT id FROM gym_members WHERE email = ? AND id != ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("si", $email, $member_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "Email address already exists for another member.";
            } else {
                // Calculate membership end date if membership is selected
                $membership_end_date = null;
                if ($membership_id && $membership_start_date) {
                    $stmt = $conn->prepare("SELECT duration FROM membership_plans WHERE id = ?");
                    $stmt->bind_param("i", $membership_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $plan = $result->fetch_assoc();
                        $duration = $plan['duration'];
                        $membership_end_date = date('Y-m-d', strtotime($membership_start_date . ' + ' . $duration . ' days'));
                    }
                }
                
                // Update member
                $stmt = $conn->prepare("UPDATE gym_members SET 
                    first_name = ?, 
                    last_name = ?, 
                    email = ?, 
                    phone = ?, 
                    gender = ?, 
                    date_of_birth = ?, 
                    address = ?, 
                    emergency_contact_name = ?, 
                    emergency_contact_phone = ?, 
                    membership_id = ?, 
                    membership_start_date = ?, 
                    membership_end_date = ?, 
                    status = ?, 
                    notes = ? 
                    WHERE id = ?");
                
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $stmt->bind_param("ssssssssssssssi", 
                    $first_name, 
                    $last_name, 
                    $email, 
                    $phone, 
                    $gender, 
                    $date_of_birth, 
                    $address, 
                    $emergency_contact_name, 
                    $emergency_contact_phone, 
                    $membership_id, 
                    $membership_start_date, 
                    $membership_end_date, 
                    $status, 
                    $notes, 
                    $member_id
                );
                
                if ($stmt->execute()) {
                    // Log activity
                    logActivity($conn, $_SESSION['admin_id'], "Updated member: $first_name $last_name");
                    
                    // Record payment if membership is selected and payment option is checked
                    if ($membership_id && isset($_POST['record_payment']) && $_POST['record_payment'] == 1) {
                        $payment_amount = (float)$_POST['payment_amount'];
                        $payment_method = sanitizeInput($_POST['payment_method']);
                        $payment_date = date('Y-m-d');
                        $receipt_number = "R" . date('YmdHis');
                        $description = "Payment for " . $_POST['membership_name'];
                        
                        $stmt = $conn->prepare("INSERT INTO payments (member_id, amount, payment_date, payment_method, description, receipt_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("idssssi", $member_id, $payment_amount, $payment_date, $payment_method, $description, $receipt_number, $_SESSION['admin_id']);
                        $stmt->execute();
                        $payment_id = $conn->insert_id;
                        
                        // Record in membership history
                        $stmt = $conn->prepare("INSERT INTO membership_history (member_id, membership_id, start_date, end_date, payment_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->bind_param("iissi", $member_id, $membership_id, $membership_start_date, $membership_end_date, $payment_id);
                        $stmt->execute();
                        
                        logActivity($conn, $_SESSION['admin_id'], "Recorded payment of $payment_amount for member #$member_id");
                    }
                    
                    $success = "Member updated successfully";
                    
                    // Refresh member data
                    $stmt = $conn->prepare("SELECT * FROM gym_members WHERE id = ?");
                    $stmt->bind_param("i", $member_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $member = $result->fetch_assoc();
                } else {
                    $error = "Failed to update member: " . $stmt->error;
                }
                $stmt->close();
            }
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
    <title>Edit Member - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h5 class="text-white">Gym Management</h5>
                        <p class="text-white-50">Admin Panel</p>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="members.php">
                                <i class="bi bi-people me-2"></i>
                                Members
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="memberships.php">
                                <i class="bi bi-card-checklist me-2"></i>
                                Memberships
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="payments.php">
                                <i class="bi bi-cash-stack me-2"></i>
                                Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profile.php">
                                <i class="bi bi-person me-2"></i>
                                Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>
                                Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Edit Member</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="members.php" class="btn btn-sm btn-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i> Back to Members
                        </a>
                        <a href="view-member.php?id=<?php echo $member_id; ?>" class="btn btn-sm btn-info">
                            <i class="bi bi-eye me-1"></i> View Member
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <?php echo $success; ?>
                        <div class="mt-2">
                            <a href="members.php" class="btn btn-sm btn-primary">Back to Members</a>
                            <a href="view-member.php?id=<?php echo $member_id; ?>" class="btn btn-sm btn-info">View Member</a>
                            <?php if ($payment_id): ?>
                                <a href="../generate-receipt.php?payment_id=<?php echo $payment_id; ?>" class="btn btn-sm btn-success" target="_blank">
                                    <i class="bi bi-printer me-1"></i> Print Receipt
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($tables_exist && $member): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Edit Member: <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?> (ID: <?php echo htmlspecialchars($member['member_id']); ?>)</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <!-- Personal Information -->
                                    <div class="col-md-6">
                                        <h5 class="border-bottom pb-2 mb-3">Personal Information</h5>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($member['first_name']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($member['last_name']); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($member['email']); ?>" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($member['phone']); ?>" required>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                                <select class="form-select" id="gender" name="gender" required>
                                                    <option value="">Select Gender</option>
                                                    <option value="male" <?php echo $member['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="female" <?php echo $member['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                                    <option value="other" <?php echo $member['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="date_of_birth" class="form-label">Date of Birth</label>
                                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo $member['date_of_birth']; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="address" class="form-label">Address</label>
                                            <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($member['address']); ?></textarea>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                                                <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($member['emergency_contact_name']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                                                <input type="text" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo htmlspecialchars($member['emergency_contact_phone']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Membership Information -->
                                    <div class="col-md-6">
                                        <h5 class="border-bottom pb-2 mb-3">Membership Information</h5>
                                        
                                        <div class="mb-3">
                                            <label for="membership_id" class="form-label">Membership Plan</label>
                                            <select class="form-select" id="membership_id" name="membership_id">
                                                <option value="">No Membership</option>
                                                <?php if (is_object($membership_plans) && $membership_plans->num_rows > 0): ?>
                                                    <?php while ($plan = $membership_plans->fetch_assoc()): ?>
                                                        <option value="<?php echo $plan['id']; ?>" 
                                                                data-price="<?php echo $plan['price']; ?>"
                                                                data-name="<?php echo htmlspecialchars($plan['name']); ?>"
                                                                <?php echo ($member['membership_id'] == $plan['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($plan['name']); ?> - $<?php echo number_format($plan['price'], 2); ?> (<?php echo $plan['duration']; ?> days)
                                                        </option>
                                                    <?php endwhile; ?>
                                                <?php endif; ?>
                                            </select>
                                            <input type="hidden" id="membership_name" name="membership_name" value="">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="membership_start_date" class="form-label">Start Date</label>
                                            <input type="date" class="form-control" id="membership_start_date" name="membership_start_date" value="<?php echo $member['membership_start_date']; ?>">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                            <select class="form-select" id="status" name="status" required>
                                                <option value="active" <?php echo $member['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $member['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="pending" <?php echo $member['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($member['notes']); ?></textarea>
                                        </div>
                                        
                                        <div id="payment_section" class="border p-3 rounded mb-3" style="display: none;">
                                            <h5 class="border-bottom pb-2 mb-3">Record New Payment</h5>
                                            
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="record_payment" name="record_payment" value="1">
                                                <label class="form-check-label" for="record_payment">
                                                    Record a new payment for this membership
                                                </label>
                                            </div>
                                            
                                            <div id="payment_details" style="display: none;">
                                                <div class="mb-3">
                                                    <label for="payment_amount" class="form-label">Payment Amount ($)</label>
                                                    <input type="number" class="form-control" id="payment_amount" name="payment_amount" step="0.01" min="0" value="0.00">
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label for="payment_method" class="form-label">Payment Method</label>
                                                    <select class="form-select" id="payment_method" name="payment_method">
                                                        <option value="cash">Cash</option>
                                                        <option value="credit_card">Credit Card</option>
                                                        <option value="debit_card">Debit Card</option>
                                                        <option value="bank_transfer">Bank Transfer</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                    <a href="members.php" class="btn btn-secondary me-md-2">Cancel</a>
                                    <button type="submit" class="btn btn-primary">Update Member</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const membershipSelect = document.getElementById('membership_id');
            const paymentSection = document.getElementById('payment_section');
            const paymentAmountInput = document.getElementById('payment_amount');
            const membershipNameInput = document.getElementById('membership_name');
            const recordPaymentCheckbox = document.getElementById('record_payment');
            const paymentDetailsDiv = document.getElementById('payment_details');
            
            // Function to toggle payment section visibility
            function togglePaymentSection() {
                if (membershipSelect.value) {
                    paymentSection.style.display = 'block';
                    const selectedOption = membershipSelect.options[membershipSelect.selectedIndex];
                    const price = selectedOption.dataset.price;
                    const name = selectedOption.dataset.name;
                    paymentAmountInput.value = price;
                    membershipNameInput.value = name;
                } else {
                    paymentSection.style.display = 'none';
                    paymentAmountInput.value = '0.00';
                    membershipNameInput.value = '';
                }
            }
            
            // Function to toggle payment details visibility
            function togglePaymentDetails() {
                if (recordPaymentCheckbox.checked) {
                    paymentDetailsDiv.style.display = 'block';
                } else {
                    paymentDetailsDiv.style.display = 'none';
                }
            }
            
            // Initial setup
            togglePaymentSection();
            togglePaymentDetails();
            
            // Event listeners
            membershipSelect.addEventListener('change', togglePaymentSection);
            recordPaymentCheckbox.addEventListener('change', togglePaymentDetails);
        });
    </script>
</body>
</html>

