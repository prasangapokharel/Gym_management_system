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
$payment_id = null;

// Get all members for dropdown
try {
    $stmt = $conn->prepare("SELECT id, member_id, CONCAT(first_name, ' ', last_name) as name 
                           FROM gym_members 
                           WHERE status = 'active' 
                           ORDER BY first_name, last_name");
    $stmt->execute();
    $members = $stmt->get_result();
    $stmt->close();
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Get membership plans for dropdown
try {
    $result = $conn->query("SELECT id, name, duration, price FROM membership_plans WHERE status = 'active' ORDER BY duration ASC");
    $membership_plans = $result;
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = (int)$_POST['member_id'];
    $amount = (float)$_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $payment_method = sanitizeInput($_POST['payment_method']);
    $description = sanitizeInput($_POST['description']);
    
    // Validate input
    if (empty($member_id) || $amount <= 0 || empty($payment_date) || empty($payment_method)) {
        $error = "Please fill in all required fields with valid values.";
    } else {
        try {
            // Generate receipt number
            $receipt_number = "R" . date('YmdHis');
            
            // Insert payment record
            $stmt = $conn->prepare("INSERT INTO payments (member_id, amount, payment_date, payment_method, description, receipt_number, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idsssi", $member_id, $amount, $payment_date, $payment_method, $description, $receipt_number, $_SESSION['admin_id']);
            
            if ($stmt->execute()) {
                $payment_id = $conn->insert_id;
                
                // Log activity
                logActivity($conn, $_SESSION['admin_id'], "Recorded payment of $amount for member #$member_id");
                
                // Update membership if selected
                if (isset($_POST['update_membership']) && $_POST['update_membership'] == 1) {
                    $membership_id = (int)$_POST['membership_id'];
                    $membership_start_date = $_POST['membership_start_date'];
                    
                    // Get membership duration
                    $stmt = $conn->prepare("SELECT duration FROM membership_plans WHERE id = ?");
                    $stmt->bind_param("i", $membership_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $plan = $result->fetch_assoc();
                        $duration = $plan['duration'];
                        $membership_end_date = date('Y-m-d', strtotime($membership_start_date . ' + ' . $duration . ' days'));
                        
                        // Update member's membership
                        $stmt = $conn->prepare("UPDATE gym_members SET 
                                              membership_id = ?, 
                                              membership_start_date = ?, 
                                              membership_end_date = ?, 
                                              status = 'active' 
                                              WHERE id = ?");
                        $stmt->bind_param("issi", $membership_id, $membership_start_date, $membership_end_date, $member_id);
                        $stmt->execute();
                        
                        // Record in membership history
                        $stmt = $conn->prepare("INSERT INTO membership_history (member_id, membership_id, start_date, end_date, payment_id, status) 
                                              VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->bind_param("iissi", $member_id, $membership_id, $membership_start_date, $membership_end_date, $payment_id);
                        $stmt->execute();
                        
                        logActivity($conn, $_SESSION['admin_id'], "Updated membership for member #$member_id");
                    }
                }
                
                $success = "Payment recorded successfully";
            } else {
                $error = "Failed to record payment: " . $stmt->error;
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
    <title>Record Payment - Gym Management System</title>
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
                    <h1 class="h2">Record Payment</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="payments.php" class="btn btn-sm btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Back to Payments
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
                            <a href="payments.php" class="btn btn-sm btn-primary">View All Payments</a>
                            <a href="add-payment.php" class="btn btn-sm btn-outline-primary">Record Another Payment</a>
                            <?php if ($payment_id): ?>
                                <a href="../print-receipt.php?payment_id=<?php echo $payment_id; ?>" class="btn btn-sm btn-success" target="_blank">
                                    <i class="bi bi-printer me-1"></i> Print Receipt
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Payment Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="member_id" class="form-label">Member <span class="text-danger">*</span></label>
                                        <select class="form-select" id="member_id" name="member_id" required>
                                            <option value="">Select Member</option>
                                            <?php if (is_object($members) && $members->num_rows > 0): ?>
                                                <?php while ($member = $members->fetch_assoc()): ?>
                                                    <option value="<?php echo $member['id']; ?>">
                                                        <?php echo htmlspecialchars($member['name']); ?> (<?php echo htmlspecialchars($member['member_id']); ?>)
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="amount" class="form-label">Amount (Rs) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                        <select class="form-select" id="payment_method" name="payment_method" required>
                                            <option value="cash">Cash</option>
                                            <option value="credit_card">Credit Card</option>
                                            <option value="debit_card">Debit Card</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="update_membership" name="update_membership" value="1">
                                                <label class="form-check-label" for="update_membership">
                                                    Update Membership with this Payment
                                                </label>
                                            </div>
                                        </div>
                                        <div class="card-body" id="membership_section" style="display: none;">
                                            <div class="mb-3">
                                                <label for="membership_id" class="form-label">Membership Plan</label>
                                                <select class="form-select" id="membership_id" name="membership_id">
                                                    <option value="">Select Plan</option>
                                                    <?php if (is_object($membership_plans) && $membership_plans->num_rows > 0): ?>
                                                        <?php while ($plan = $membership_plans->fetch_assoc()): ?>
                                                            <option value="<?php echo $plan['id']; ?>" data-price="<?php echo $plan['price']; ?>">
                                                                <?php echo htmlspecialchars($plan['name']); ?> - $<?php echo number_format($plan['price'], 2); ?> (<?php echo $plan['duration']; ?> days)
                                                            </option>
                                                        <?php endwhile; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="membership_start_date" class="form-label">Start Date</label>
                                                <input type="date" class="form-control" id="membership_start_date" name="membership_start_date" value="<?php echo date('Y-m-d'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <h6><i class="bi bi-info-circle me-2"></i>Payment Information</h6>
                                        <p class="mb-0">Recording a payment will automatically generate a receipt that can be printed.</p>
                                        <p class="mb-0">If you select "Update Membership", the member's membership status and dates will be updated.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="payments.php" class="btn btn-secondary me-md-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Record Payment</button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const updateMembershipCheckbox = document.getElementById('update_membership');
            const membershipSection = document.getElementById('membership_section');
            const membershipSelect = document.getElementById('membership_id');
            const amountInput = document.getElementById('amount');
            
            // Function to toggle membership section visibility
            function toggleMembershipSection() {
                if (updateMembershipCheckbox.checked) {
                    membershipSection.style.display = 'block';
                } else {
                    membershipSection.style.display = 'none';
                }
            }
            
            // Function to update amount based on selected membership
            function updateAmount() {
                if (updateMembershipCheckbox.checked && membershipSelect.value) {
                    const selectedOption = membershipSelect.options[membershipSelect.selectedIndex];
                    const price = selectedOption.dataset.price;
                    if (price) {
                        amountInput.value = price;
                    }
                }
            }
            
            // Initial setup
            toggleMembershipSection();
            
            // Event listeners
            updateMembershipCheckbox.addEventListener('change', toggleMembershipSection);
            membershipSelect.addEventListener('change', updateAmount);
        });
    </script>
</body>
</html>

