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
$membership = null;
$payments = [];
$membership_history = [];

// Check if member ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: members.php");
    exit;
}

$member_id = (int)$_GET['id'];

// Handle member actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $status = sanitizeInput($_POST['status']);
        
        try {
            $stmt = $conn->prepare("UPDATE gym_members SET status = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("si", $status, $member_id);
            
            if ($stmt->execute()) {
                $success = "Member status updated successfully";
                logActivity($conn, $_SESSION['admin_id'], "Updated status of member #$member_id to $status");
            } else {
                $error = "Failed to update member status";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    } elseif (isset($_POST['renew_membership'])) {
        $membership_id = (int)$_POST['membership_id'];
        $start_date = $_POST['start_date'];
        $payment_amount = (float)$_POST['payment_amount'];
        $payment_method = sanitizeInput($_POST['payment_method']);
        
        try {
            // Get membership duration
            $stmt = $conn->prepare("SELECT duration, name FROM membership_plans WHERE id = ?");
            $stmt->bind_param("i", $membership_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $plan = $result->fetch_assoc();
                $duration = $plan['duration'];
                $plan_name = $plan['name'];
                $end_date = date('Y-m-d', strtotime($start_date . ' + ' . $duration . ' days'));
                
                // Begin transaction
                $conn->begin_transaction();
                
                // Update member's membership
                $stmt = $conn->prepare("UPDATE gym_members SET membership_id = ?, membership_start_date = ?, membership_end_date = ?, status = 'active' WHERE id = ?");
                $stmt->bind_param("issi", $membership_id, $start_date, $end_date, $member_id);
                $stmt->execute();
                
                // Record payment
                $payment_date = date('Y-m-d');
                $receipt_number = "R" . date('YmdHis');
                $description = "Renewal payment for " . $plan_name;
                
                $stmt = $conn->prepare("INSERT INTO payments (member_id, amount, payment_date, payment_method, description, receipt_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("idssssi", $member_id, $payment_amount, $payment_date, $payment_method, $description, $receipt_number, $_SESSION['admin_id']);
                $stmt->execute();
                $payment_id = $conn->insert_id;
                
                // Record in membership history
                $stmt = $conn->prepare("INSERT INTO membership_history (member_id, membership_id, start_date, end_date, payment_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("iissi", $member_id, $membership_id, $start_date, $end_date, $payment_id);
                $stmt->execute();
                
                // Commit transaction
                $conn->commit();
                
                $success = "Membership renewed successfully";
                logActivity($conn, $_SESSION['admin_id'], "Renewed membership for member #$member_id");
            } else {
                $error = "Invalid membership plan";
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = "System error: " . $e->getMessage();
        }
    }
}

// Get member details
try {
    $stmt = $conn->prepare("SELECT m.*, 
                           mp.name as membership_name, mp.duration as membership_duration, mp.price as membership_price,
                           DATEDIFF(m.membership_end_date, CURDATE()) as days_remaining
                           FROM gym_members m
                           LEFT JOIN membership_plans mp ON m.membership_id = mp.id
                           WHERE m.id = ?");
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
    
    // Get membership plans for renewal
    $membership_plans = $conn->query("SELECT id, name, duration, price FROM membership_plans WHERE status = 'active' ORDER BY duration ASC");
    
    // Get payment history
    $stmt = $conn->prepare("SELECT * FROM payments WHERE member_id = ? ORDER BY payment_date DESC");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $payments = $stmt->get_result();
    $stmt->close();
    
    // Get membership history
    $stmt = $conn->prepare("SELECT mh.*, mp.name as plan_name, p.amount as payment_amount
                           FROM membership_history mh
                           LEFT JOIN membership_plans mp ON mh.membership_id = mp.id
                           LEFT JOIN payments p ON mh.payment_id = p.id
                           WHERE mh.member_id = ?
                           ORDER BY mh.start_date DESC");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $membership_history = $stmt->get_result();
    $stmt->close();
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Member - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Member Details</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="members.php" class="btn btn-sm btn-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i> Back to Members
                        </a>
                        <a href="edit-member.php?id=<?php echo $member_id; ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-pencil me-1"></i> Edit Member
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($member): ?>
                    <div class="row">
                        <!-- Member Information -->
                        <div class="col-md-4">
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Member Information</h5>
                                    <span class="badge <?php 
                                        echo $member['status'] === 'active' ? 'bg-success' : 
                                            ($member['status'] === 'inactive' ? 'bg-danger' : 'bg-warning'); 
                                    ?>">
                                        <?php echo ucfirst($member['status']); ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-4">
                                        <div class="avatar-placeholder mb-2">
                                            <i class="bi bi-person-circle display-1"></i>
                                        </div>
                                        <h4><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h4>
                                        <p class="text-muted mb-0">Member ID: <?php echo htmlspecialchars($member['member_id']); ?></p>
                                        <p class="text-muted">Joined: <?php echo date('M d, Y', strtotime($member['created_at'])); ?></p>
                                    </div>
                                    
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-envelope me-2"></i> Email</span>
                                            <span><?php echo htmlspecialchars($member['email']); ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-telephone me-2"></i> Phone</span>
                                            <span><?php echo htmlspecialchars($member['phone']); ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-gender-ambiguous me-2"></i> Gender</span>
                                            <span><?php echo ucfirst(htmlspecialchars($member['gender'])); ?></span>
                                        </li>
                                        <?php if ($member['date_of_birth']): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-calendar me-2"></i> Date of Birth</span>
                                            <span><?php echo date('M d, Y', strtotime($member['date_of_birth'])); ?></span>
                                        </li>
                                        <?php endif; ?>
                                        <?php if ($member['address']): ?>
                                        <li class="list-group-item">
                                            <div><i class="bi bi-geo-alt me-2"></i> Address</div>
                                            <div class="text-muted mt-1"><?php echo nl2br(htmlspecialchars($member['address'])); ?></div>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                    
                                    <div class="mt-3">
                                        <form method="POST" action="" class="d-flex">
                                            <select class="form-select me-2" name="status">
                                                <option value="active" <?php echo $member['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $member['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="pending" <?php echo $member['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            </select>
                                            <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($member['emergency_contact_name'] || $member['emergency_contact_phone']): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Emergency Contact</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php if ($member['emergency_contact_name']): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-person me-2"></i> Name</span>
                                            <span><?php echo htmlspecialchars($member['emergency_contact_name']); ?></span>
                                        </li>
                                        <?php endif; ?>
                                        <?php if ($member['emergency_contact_phone']): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-telephone me-2"></i> Phone</span>
                                            <span><?php echo htmlspecialchars($member['emergency_contact_phone']); ?></span>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($member['notes']): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Notes</h5>
                                </div>
                                <div class="card-body">
                                    <p><?php echo nl2br(htmlspecialchars($member['notes'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Membership Information -->
                        <div class="col-md-8">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Membership Information</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($member['membership_id']): ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h6>Current Plan</h6>
                                                <p class="mb-1"><strong><?php echo htmlspecialchars($member['membership_name']); ?></strong></p>
                                                <p class="text-muted mb-0">Duration: <?php echo $member['membership_duration']; ?> days</p>
                                                <p class="text-muted">Price: Rs<?php echo number_format($member['membership_price'], 2); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6>Membership Status</h6>
                                                <p class="mb-1">Start Date: <?php echo date('M d, Y', strtotime($member['membership_start_date'])); ?></p>
                                                <p class="mb-1">End Date: <?php echo date('M d, Y', strtotime($member['membership_end_date'])); ?></p>
                                                <?php if ($member['days_remaining'] !== null): ?>
                                                    <?php if ($member['days_remaining'] < 0): ?>
                                                        <p class="text-danger"><strong>Expired <?php echo abs($member['days_remaining']); ?> days ago</strong></p>
                                                    <?php elseif ($member['days_remaining'] <= 7): ?>
                                                        <p class="text-warning"><strong><?php echo $member['days_remaining']; ?> days remaining</strong></p>
                                                    <?php else: ?>
                                                        <p class="text-success"><strong><?php echo $member['days_remaining']; ?> days remaining</strong></p>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#renewMembershipModal">
                                                <i class="bi bi-arrow-repeat me-1"></i> Renew Membership
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0">
                                            <i class="bi bi-exclamation-triangle me-2"></i> This member does not have an active membership plan.
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#renewMembershipModal">
                                                    <i class="bi bi-plus-circle me-1"></i> Add Membership
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Membership History -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Membership History</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Plan</th>
                                                    <th>Start Date</th>
                                                    <th>End Date</th>
                                                    <th>Payment</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (is_object($membership_history) && $membership_history->num_rows > 0): ?>
                                                    <?php while ($history = $membership_history->fetch_assoc()): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($history['plan_name']); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($history['start_date'])); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($history['end_date'])); ?></td>
                                                            <td>Rs<?php echo number_format($history['payment_amount'], 2); ?></td>
                                                            <td>
                                                                <span class="badge <?php 
                                                                    echo $history['status'] === 'active' ? 'bg-success' : 
                                                                        ($history['status'] === 'expired' ? 'bg-danger' : 'bg-warning'); 
                                                                ?>">
                                                                    <?php echo ucfirst($history['status']); ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center">No membership history found</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment History -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Payment History</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Amount</th>
                                                    <th>Method</th>
                                                    <th>Description</th>
                                                    <th>Receipt</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (is_object($payments) && $payments->num_rows > 0): ?>
                                                    <?php while ($payment = $payments->fetch_assoc()): ?>
                                                        <tr>
                                                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                                            <td>Rs<?php echo number_format($payment['amount'], 2); ?></td>
                                                            <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                                            <td><?php echo htmlspecialchars($payment['description']); ?></td>
                                                            <td>
                                                                <?php if ($payment['receipt_number']): ?>
                                                                    <span class="badge bg-info"><?php echo htmlspecialchars($payment['receipt_number']); ?></span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">N/A</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center">No payment history found</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Renew Membership Modal -->
                    <div class="modal fade" id="renewMembershipModal" tabindex="-1" aria-labelledby="renewMembershipModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="renewMembershipModalLabel">
                                        <?php echo $member['membership_id'] ? 'Renew Membership' : 'Add Membership'; ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form method="POST" action="">
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="membership_id" class="form-label">Membership Plan</label>
                                            <select class="form-select" id="membership_id" name="membership_id" required>
                                                <option value="">Select a Plan</option>
                                                <?php if (is_object($membership_plans) && $membership_plans->num_rows > 0): ?>
                                                    <?php while ($plan = $membership_plans->fetch_assoc()): ?>
                                                        <option value="<?php echo $plan['id']; ?>" 
                                                                data-price="<?php echo $plan['price']; ?>"
                                                                <?php echo ($member['membership_id'] == $plan['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($plan['name']); ?> - Rs<?php echo number_format($plan['price'], 2); ?> (<?php echo $plan['duration']; ?> days)
                                                        </option>
                                                    <?php endwhile; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="start_date" class="form-label">Start Date</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="payment_amount" class="form-label">Payment Amount (Rs)</label>
                                            <input type="number" class="form-control" id="payment_amount" name="payment_amount" step="0.01" min="0" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="payment_method" class="form-label">Payment Method</label>
                                            <select class="form-select" id="payment_method" name="payment_method" required>
                                                <option value="cash">Cash</option>
                                                <option value="credit_card">Credit Card</option>
                                                <option value="debit_card">Debit Card</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="renew_membership" class="btn btn-primary">
                                            <?php echo $member['membership_id'] ? 'Renew Membership' : 'Add Membership'; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
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
            const paymentAmountInput = document.getElementById('payment_amount');
            
            // Set initial payment amount based on selected membership
            if (membershipSelect && paymentAmountInput) {
                const updatePaymentAmount = () => {
                    const selectedOption = membershipSelect.options[membershipSelect.selectedIndex];
                    if (selectedOption && selectedOption.dataset.price) {
                        paymentAmountInput.value = selectedOption.dataset.price;
                    }
                };
                
                // Set initial value
                updatePaymentAmount();
                
                // Update when selection changes
                membershipSelect.addEventListener('change', updatePaymentAmount);
            }
        });
    </script>
</body>
</html>

