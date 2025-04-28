<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/sms_functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: ../index.php");
    exit;
}

// Initialize variables
$error = '';
$success = '';
$first_name = '';
$last_name = '';
$email = '';
$phone = '';
$gender = '';
$dob = '';
$address = '';
$emergency_contact_name = '';
$emergency_contact_phone = '';
$membership_id = '';
$membership_start_date = date('Y-m-d');
$membership_end_date = '';
$status = 'active';
$notes = '';
$payment_amount = '';
$payment_method = 'cash';

// Get membership plans
$plans_query = "SELECT * FROM membership_plans WHERE status = 'active' ORDER BY name";
$plans_result = $conn->query($plans_query);
$membership_plans = [];

if ($plans_result && $plans_result->num_rows > 0) {
    while ($plan = $plans_result->fetch_assoc()) {
        $membership_plans[] = $plan;
    }
}

// Check if SMS is enabled
$sms_enabled = false;
$sms_config_query = "SELECT * FROM sms_config WHERE is_active = 1 LIMIT 1";
$sms_config_result = $conn->query($sms_config_query);
if ($sms_config_result && $sms_config_result->num_rows > 0) {
    $sms_enabled = true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $first_name = sanitizeInput($_POST['first_name']);
    $last_name = sanitizeInput($_POST['last_name']);
    $email = sanitizeInput($_POST['email']);
    $phone = sanitizeInput($_POST['phone']);
    $gender = sanitizeInput($_POST['gender']);
    $dob = !empty($_POST['dob']) ? sanitizeInput($_POST['dob']) : null;
    $address = sanitizeInput($_POST['address']);
    $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']);
    $emergency_contact_phone = sanitizeInput($_POST['emergency_contact_phone']);
    $membership_id = (int)$_POST['membership_id'];
    $membership_start_date = sanitizeInput($_POST['membership_start_date']);
    $status = sanitizeInput($_POST['status']);
    $notes = sanitizeInput($_POST['notes']);
    $payment_amount = (float)$_POST['payment_amount'];
    $payment_method = sanitizeInput($_POST['payment_method']);
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($phone) || empty($membership_id)) {
        $error = "Please fill in all required fields";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get the next member ID
            $member_id_query = "SELECT MAX(CAST(SUBSTRING(member_id, 3) AS UNSIGNED)) as max_id FROM gym_members";
            $member_id_result = $conn->query($member_id_query);
            $max_id = 0;
            
            if ($member_id_result && $member_id_result->num_rows > 0) {
                $row = $member_id_result->fetch_assoc();
                $max_id = $row['max_id'];
            }
            
            $next_id = $max_id + 1;
            $member_id = 'GM' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
            
            // Get membership plan details
            $plan_query = "SELECT * FROM membership_plans WHERE id = ?";
            $plan_stmt = $conn->prepare($plan_query);
            $plan_stmt->bind_param("i", $membership_id);
            $plan_stmt->execute();
            $plan_result = $plan_stmt->get_result();
            $plan = $plan_result->fetch_assoc();
            $plan_stmt->close();
            
            // Calculate membership end date
            $membership_end_date = date('Y-m-d', strtotime($membership_start_date . ' + ' . $plan['duration'] . ' days'));
            
            // Insert new member
            $insert_query = "INSERT INTO gym_members (member_id, first_name, last_name, email, phone, gender, date_of_birth, 
                            address, emergency_contact_name, emergency_contact_phone, membership_id, 
                            membership_start_date, membership_end_date, status, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("ssssssssssissss", $member_id, $first_name, $last_name, $email, $phone, $gender, $dob, 
                            $address, $emergency_contact_name, $emergency_contact_phone, $membership_id, 
                            $membership_start_date, $membership_end_date, $status, $notes);
            
            if ($stmt->execute()) {
                $new_member_id = $stmt->insert_id;
                $stmt->close();
                
                // Generate receipt number
                $receipt_number = 'R' . date('YmdHis');
                
                // Record payment
                $payment_query = "INSERT INTO payments (member_id, amount, payment_date, payment_method, description, receipt_number, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                $payment_stmt = $conn->prepare($payment_query);
                $payment_description = "Payment for " . $plan['name'];
                // Fixed: Added 'i' to the type string to match the 7 parameters
                $payment_stmt->bind_param("idsssis", $new_member_id, $payment_amount, $membership_start_date, $payment_method, $payment_description, $receipt_number, $_SESSION['admin_id']);
                $payment_stmt->execute();
                $payment_id = $payment_stmt->insert_id;
                $payment_stmt->close();
                
                // Record membership history
                $history_query = "INSERT INTO membership_history (member_id, membership_id, start_date, end_date, payment_id, status) 
                                VALUES (?, ?, ?, ?, ?, 'active')";
                $history_stmt = $conn->prepare($history_query);
                $history_stmt->bind_param("iissi", $new_member_id, $membership_id, $membership_start_date, $membership_end_date, $payment_id);
                $history_stmt->execute();
                $history_stmt->close();
                
                // Log activity
                $activity = "Added new member: " . $first_name . " " . $last_name;
                logActivity($conn, $_SESSION['admin_id'], $activity);
                
                // Log payment activity
                $payment_activity = "Recorded payment of " . $payment_amount . " for member #" . $member_id;
                logActivity($conn, $_SESSION['admin_id'], $payment_activity);
                
                // Send welcome SMS if enabled
                if ($sms_enabled && !empty($phone)) {
                    // Get the welcome template
                    $template_query = "SELECT * FROM sms_templates WHERE template_type = 'activation' AND is_active = 1 LIMIT 1";
                    $template_result = $conn->query($template_query);
                    
                    if ($template_result && $template_result->num_rows > 0) {
                        $template = $template_result->fetch_assoc();
                        $message_template = $template['template_content'];
                        
                        // Replace placeholders
                        $member_name = $first_name . ' ' . $last_name;
                        $plan_name = $plan['name'];
                        $expiry_date = date('d-m-Y', strtotime($membership_end_date));
                        
                        $message = str_replace(
                            ['{member_name}', '{plan_name}', '{expiry_date}'],
                            [$member_name, $plan_name, $expiry_date],
                            $message_template
                        );
                        
                        // Send the SMS
                        $sms_result = sendSMS($phone, $message);
                        
                        // Log the SMS
                        $sms_status = $sms_result['success'] ? 'sent' : 'failed';
                        $error_message = $sms_result['success'] ? null : $sms_result['message'];
                        logSmsActivity($conn, $new_member_id, $phone, $message, $sms_status, $error_message);
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                $success = "Member added successfully with ID: " . $member_id;
                
                // Reset form fields
                $first_name = '';
                $last_name = '';
                $email = '';
                $phone = '';
                $gender = '';
                $dob = '';
                $address = '';
                $emergency_contact_name = '';
                $emergency_contact_phone = '';
                $membership_id = '';
                $membership_start_date = date('Y-m-d');
                $status = 'active';
                $notes = '';
                $payment_amount = '';
                $payment_method = 'cash';
            } else {
                $error = "Error adding member: " . $stmt->error;
                $conn->rollback();
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
            $conn->rollback();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Member - Gym Management System</title>
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
                    <h1 class="h2">Add New Member</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="members.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Members
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h5>Personal Information</h5>
                                    <hr>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo $first_name; ?>" required>
                                            <div class="invalid-feedback">Please enter first name</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo $last_name; ?>" required>
                                            <div class="invalid-feedback">Please enter last name</div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="email" class="form-label">Email</label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?php echo $email; ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo $phone; ?>" required>
                                            <div class="invalid-feedback">Please enter phone number</div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                            <select class="form-select" id="gender" name="gender" required>
                                                <option value="" disabled <?php echo empty($gender) ? 'selected' : ''; ?>>Select Gender</option>
                                                <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>Female</option>
                                                <option value="other" <?php echo $gender === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                            <div class="invalid-feedback">Please select gender</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="dob" class="form-label">Date of Birth</label>
                                            <input type="date" class="form-control" id="dob" name="dob" value="<?php echo $dob; ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="2"><?php echo $address; ?></textarea>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                                            <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo $emergency_contact_name; ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                                            <input type="text" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo $emergency_contact_phone; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5>Membership Details</h5>
                                    <hr>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="membership_id" class="form-label">Membership Plan <span class="text-danger">*</span></label>
                                            <select class="form-select" id="membership_id" name="membership_id" required onchange="updatePaymentAmount()">
                                                <option value="" disabled <?php echo empty($membership_id) ? 'selected' : ''; ?>>Select Membership Plan</option>
                                                <?php foreach ($membership_plans as $plan): ?>
                                                    <option value="<?php echo $plan['id']; ?>" data-price="<?php echo $plan['price']; ?>" data-duration="<?php echo $plan['duration']; ?>" <?php echo $membership_id == $plan['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($plan['name'] . ' - Rs ' . $plan['price'] . ' (' . $plan['duration'] . ' days)'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a membership plan</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="membership_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="membership_start_date" name="membership_start_date" value="<?php echo $membership_start_date; ?>" required onchange="updateEndDate()">
                                            <div class="invalid-feedback">Please select start date</div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="membership_end_date_display" class="form-label">End Date (Calculated)</label>
                                            <input type="date" class="form-control" id="membership_end_date_display" disabled>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="status" class="form-label">Status</label>
                                            <select class="form-select" id="status" name="status">
                                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4">Payment Information</h5>
                                    <hr>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="payment_amount" class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">Rs</span>
                                                <input type="number" step="0.01" class="form-control" id="payment_amount" name="payment_amount" value="<?php echo $payment_amount; ?>" required>
                                            </div>
                                            <div class="invalid-feedback">Please enter payment amount</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="payment_method" class="form-label">Payment Method</label>
                                            <select class="form-select" id="payment_method" name="payment_method">
                                                <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                <option value="credit_card" <?php echo $payment_method === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                                <option value="debit_card" <?php echo $payment_method === 'debit_card' ? 'selected' : ''; ?>>Debit Card</option>
                                                <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                                <option value="other" <?php echo $payment_method === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo $notes; ?></textarea>
                                    </div>
                                    
                                    <?php if ($sms_enabled): ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle-fill me-2"></i>
                                        A welcome SMS will be automatically sent to the member's phone number.
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        SMS notifications are not enabled. Welcome SMS will not be sent.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="reset" class="btn btn-secondary me-md-2">Reset</button>
                                <button type="submit" class="btn btn-primary">Add Member</button>
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
        // Form validation
        (function () {
            'use strict'
            
            // Fetch all the forms we want to apply custom Bootstrap validation styles to
            var forms = document.querySelectorAll('.needs-validation')
            
            // Loop over them and prevent submission
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
        
        // Update payment amount based on selected membership plan
        function updatePaymentAmount() {
            const membershipSelect = document.getElementById('membership_id');
            const paymentInput = document.getElementById('payment_amount');
            
            if (membershipSelect.selectedIndex > 0) {
                const selectedOption = membershipSelect.options[membershipSelect.selectedIndex];
                const price = selectedOption.getAttribute('data-price');
                paymentInput.value = price;
            } else {
                paymentInput.value = '';
            }
            
            updateEndDate();
        }
        
        // Calculate and update end date based on start date and membership duration
        function updateEndDate() {
            const membershipSelect = document.getElementById('membership_id');
            const startDateInput = document.getElementById('membership_start_date');
            const endDateDisplay = document.getElementById('membership_end_date_display');
            
            if (membershipSelect.selectedIndex > 0 && startDateInput.value) {
                const selectedOption = membershipSelect.options[membershipSelect.selectedIndex];
                const duration = parseInt(selectedOption.getAttribute('data-duration'));
                
                const startDate = new Date(startDateInput.value);
                const endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + duration);
                
                // Format the date as YYYY-MM-DD for the input
                const year = endDate.getFullYear();
                const month = String(endDate.getMonth() + 1).padStart(2, '0');
                const day = String(endDate.getDate()).padStart(2, '0');
                endDateDisplay.value = `${year}-${month}-${day}`;
            } else {
                endDateDisplay.value = '';
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updatePaymentAmount();
            updateEndDate();
        });
    </script>
</body>
</html>