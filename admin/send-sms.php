<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Define constants only if not already defined
if (!defined('API_KEY')) {
    define('API_KEY', '3B853539856F3FD36823E959EF82ABF6');
}
if (!defined('API_URL')) {
    define('API_URL', 'https://user.birasms.com/api/smsapi');
}
if (!defined('ROUTE_ID')) {
    define('ROUTE_ID', 'SI_Alert');
}

// Now include the SMS functions
require_once '../includes/sms_functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: ../index.php");
    exit;
}

// Initialize variables
$error = '';
$success = '';
$members = [];
$templates = [];

// Get SMS configuration
$config_query = "SELECT * FROM sms_config WHERE is_active = 1 LIMIT 1";
$config_result = $conn->query($config_query);
$sms_enabled = ($config_result && $config_result->num_rows > 0);

if (!$sms_enabled) {
    $error = "SMS notifications are not configured or enabled. Please configure SMS settings first.";
}

// Get SMS templates
$templates_query = "SELECT * FROM sms_templates WHERE is_active = 1 ORDER BY template_name";
$templates_result = $conn->query($templates_query);
if ($templates_result && $templates_result->num_rows > 0) {
    while ($template = $templates_result->fetch_assoc()) {
        $templates[] = $template;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sms_enabled) {
    // Send SMS to selected members
    if (isset($_POST['send_sms'])) {
        $message = sanitizeInput($_POST['message']);
        $selected_members = isset($_POST['selected_members']) ? $_POST['selected_members'] : [];
        
        if (empty($message)) {
            $error = "Message content is required";
        } elseif (empty($selected_members)) {
            $error = "Please select at least one member";
        } else {
            $result = sendBulkSMS($conn, $selected_members, $message);
            
            if ($result['success']) {
                $success = "SMS sent successfully to {$result['sent']} member(s)";
                if ($result['failed'] > 0) {
                    $success .= ". Failed to send to {$result['failed']} member(s).";
                }
                logActivity($conn, $_SESSION['admin_id'], "Sent bulk SMS to " . count($selected_members) . " members");
            } else {
                $error = "Failed to send SMS: " . $result['message'];
            }
        }
    }
    
    // Send SMS to filtered members
    elseif (isset($_POST['send_filtered_sms'])) {
        $message = sanitizeInput($_POST['filtered_message']);
        $filter_status = isset($_POST['filter_status']) ? $_POST['filter_status'] : '';
        $filter_membership = isset($_POST['filter_membership']) ? (int)$_POST['filter_membership'] : 0;
        $filter_expiry = isset($_POST['filter_expiry']) ? $_POST['filter_expiry'] : '';
        
        if (empty($message)) {
            $error = "Message content is required";
        } else {
            // Build query based on filters
            $query = "SELECT id FROM gym_members WHERE 1=1";
            $params = [];
            $param_types = "";
            
            if (!empty($filter_status)) {
                $query .= " AND status = ?";
                $params[] = $filter_status;
                $param_types .= "s";
            }
            
            if (!empty($filter_membership)) {
                $query .= " AND membership_id = ?";
                $params[] = $filter_membership;
                $param_types .= "i";
            }
            
            if (!empty($filter_expiry)) {
                $today = date('Y-m-d');
                if ($filter_expiry === 'expiring_7') {
                    $future_date = date('Y-m-d', strtotime('+7 days'));
                    $query .= " AND membership_end_date BETWEEN ? AND ?";
                    $params[] = $today;
                    $params[] = $future_date;
                    $param_types .= "ss";
                } elseif ($filter_expiry === 'expiring_30') {
                    $future_date = date('Y-m-d', strtotime('+30 days'));
                    $query .= " AND membership_end_date BETWEEN ? AND ?";
                    $params[] = $today;
                    $params[] = $future_date;
                    $param_types .= "ss";
                } elseif ($filter_expiry === 'expired') {
                    $query .= " AND membership_end_date < ?";
                    $params[] = $today;
                    $param_types .= "s";
                }
            }
            
            // Get filtered members
            $stmt = $conn->prepare($query);
            if (!empty($params)) {
                $stmt->bind_param($param_types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
            
            $filtered_members = [];
            while ($row = $result->fetch_assoc()) {
                $filtered_members[] = $row['id'];
            }
            
            if (empty($filtered_members)) {
                $error = "No members match the selected filters";
            } else {
                $result = sendBulkSMS($conn, $filtered_members, $message);
                
                if ($result['success']) {
                    $success = "SMS sent successfully to {$result['sent']} member(s)";
                    if ($result['failed'] > 0) {
                        $success .= ". Failed to send to {$result['failed']} member(s).";
                    }
                    logActivity($conn, $_SESSION['admin_id'], "Sent filtered SMS to " . count($filtered_members) . " members");
                } else {
                    $error = "Failed to send SMS: " . $result['message'];
                }
            }
        }
    }
}

// Get members for selection
$members_query = "
    SELECT m.id, m.member_id, m.first_name, m.last_name, m.phone, m.status, 
           mp.name as plan_name, m.membership_end_date
    FROM gym_members m
    LEFT JOIN membership_plans mp ON m.membership_id = mp.id
    WHERE m.phone IS NOT NULL AND m.phone != ''
    ORDER BY m.first_name, m.last_name
";
$members_result = $conn->query($members_query);
if ($members_result && $members_result->num_rows > 0) {
    while ($member = $members_result->fetch_assoc()) {
        $members[] = $member;
    }
}

// Get membership plans for filter
$plans_query = "SELECT id, name FROM membership_plans WHERE status = 'active' ORDER BY name";
$plans_result = $conn->query($plans_query);
$membership_plans = [];
if ($plans_result && $plans_result->num_rows > 0) {
    while ($plan = $plans_result->fetch_assoc()) {
        $membership_plans[] = $plan;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send SMS - Gym Management System</title>
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
                    <h1 class="h2">Send SMS Notifications</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="sms-settings.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-gear me-1"></i> SMS Settings
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
                
                <?php if (!$sms_enabled): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        SMS notifications are not configured or enabled. Please <a href="sms-settings.php" class="alert-link">configure SMS settings</a> first.
                    </div>
                <?php endif; ?>
                
                <!-- SMS Provider Info -->
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    <strong>SMS Provider:</strong> BIR SMS API
                    <button type="button" class="btn btn-sm btn-outline-info ms-3" data-bs-toggle="modal" data-bs-target="#smsInfoModal">
                        <i class="bi bi-question-circle me-1"></i> SMS API Information
                    </button>
                </div>
                
                <!-- SMS Sending Tabs -->
                <ul class="nav nav-tabs mb-4" id="smsSendingTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="select-tab" data-bs-toggle="tab" data-bs-target="#select-tab-pane" type="button" role="tab" aria-controls="select-tab-pane" aria-selected="true">
                            <i class="bi bi-check-square me-1"></i> Select Recipients
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="filter-tab" data-bs-toggle="tab" data-bs-target="#filter-pane" type="button" role="tab" aria-controls="filter-pane" aria-selected="false">
                            <i class="bi bi-funnel me-1"></i> Filter Recipients
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="smsSendingTabsContent">
                    <!-- Select Recipients Tab -->
                    <div class="tab-pane fade show active" id="select-tab-pane" role="tabpanel" aria-labelledby="select-tab" tabindex="0">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Send SMS to Selected Members</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" id="selectMembersForm">
                                    <div class="mb-3">
                                        <label for="template_select" class="form-label">Use Template (Optional)</label>
                                        <select class="form-select" id="template_select" onchange="loadTemplate(this.value)">
                                            <option value="">Select a template or write custom message</option>
                                            <?php foreach ($templates as $template): ?>
                                                <option value="<?php echo $template['id']; ?>" data-content="<?php echo htmlspecialchars($template['template_content']); ?>">
                                                    <?php echo htmlspecialchars($template['template_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="message" class="form-label">Message</label>
                                        <textarea class="form-control" id="message" name="message" rows="4" required <?php echo !$sms_enabled ? 'disabled' : ''; ?>></textarea>
                                        <div class="d-flex justify-content-between mt-1">
                                            <div class="form-text">
                                                Available placeholders: {member_name}, {plan_name}, {expiry_date}
                                            </div>
                                            <div class="form-text">
                                                <span id="charCount">0</span> characters
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Select Recipients</label>
                                        <div class="d-flex justify-content-between mb-2">
                                            <div>
                                                <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn">Select All</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">Deselect All</button>
                                            </div>
                                            <div class="input-group" style="max-width: 300px;">
                                                <input type="text" class="form-control form-control-sm" id="memberSearch" placeholder="Search members...">
                                                <button class="btn btn-outline-secondary btn-sm" type="button" id="clearSearchBtn">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                            <table class="table table-striped table-hover">
                                                <thead class="sticky-top bg-light">
                                                    <tr>
                                                        <th width="40">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                                            </div>
                                                        </th>
                                                        <th>Member ID</th>
                                                        <th>Name</th>
                                                        <th>Phone</th>
                                                        <th>Membership</th>
                                                        <th>Expiry Date</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($members) > 0): ?>
                                                        <?php foreach ($members as $member): ?>
                                                            <tr class="member-row">
                                                                <td>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input member-checkbox" type="checkbox" name="selected_members[]" value="<?php echo $member['id']; ?>">
                                                                    </div>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($member['member_id']); ?></td>
                                                                <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                                                <td><?php echo htmlspecialchars($member['phone']); ?></td>
                                                                <td><?php echo htmlspecialchars($member['plan_name'] ?? 'None'); ?></td>
                                                                <td>
                                                                    <?php if ($member['membership_end_date']): ?>
                                                                        <?php echo date('d-m-Y', strtotime($member['membership_end_date'])); ?>
                                                                    <?php else: ?>
                                                                        N/A
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <span class="badge <?php 
                                                                        echo $member['status'] === 'active' ? 'bg-success' : 
                                                                            ($member['status'] === 'inactive' ? 'bg-danger' : 'bg-warning'); 
                                                                    ?>">
                                                                        <?php echo ucfirst($member['status']); ?>
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr>
                                                            <td colspan="7" class="text-center">No members found</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="send_sms" class="btn btn-primary" <?php echo !$sms_enabled ? 'disabled' : ''; ?>>
                                            <i class="bi bi-send me-1"></i> Send SMS
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Recipients Tab -->
                    <div class="tab-pane fade" id="filter-pane" role="tabpanel" aria-labelledby="filter-tab" tabindex="0">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Send SMS to Filtered Members</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="row mb-4">
                                        <div class="col-md-4">
                                            <label for="filter_status" class="form-label">Member Status</label>
                                            <select class="form-select" id="filter_status" name="filter_status">
                                                <option value="">All Statuses</option>
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                                <option value="pending">Pending</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="filter_membership" class="form-label">Membership Plan</label>
                                            <select class="form-select" id="filter_membership" name="filter_membership">
                                                <option value="">All Plans</option>
                                                <?php foreach ($membership_plans as $plan): ?>
                                                    <option value="<?php echo $plan['id']; ?>"><?php echo htmlspecialchars($plan['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="filter_expiry" class="form-label">Membership Expiry</label>
                                            <select class="form-select" id="filter_expiry" name="filter_expiry">
                                                <option value="">Any</option>
                                                <option value="expiring_7">Expiring in 7 days</option>
                                                <option value="expiring_30">Expiring in 30 days</option>
                                                <option value="expired">Already Expired</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="filtered_template_select" class="form-label">Use Template (Optional)</label>
                                        <select class="form-select" id="filtered_template_select" onchange="loadFilteredTemplate(this.value)">
                                            <option value="">Select a template or write custom message</option>
                                            <?php foreach ($templates as $template): ?>
                                                <option value="<?php echo $template['id']; ?>" data-content="<?php echo htmlspecialchars($template['template_content']); ?>">
                                                    <?php echo htmlspecialchars($template['template_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="filtered_message" class="form-label">Message</label>
                                        <textarea class="form-control" id="filtered_message" name="filtered_message" rows="4" required <?php echo !$sms_enabled ? 'disabled' : ''; ?>></textarea>
                                        <div class="d-flex justify-content-between mt-1">
                                            <div class="form-text">
                                                Available placeholders: {member_name}, {plan_name}, {expiry_date}
                                            </div>
                                            <div class="form-text">
                                                <span id="filteredCharCount">0</span> characters
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle-fill me-2"></i>
                                        SMS will be sent to all members matching the selected filters. Placeholders will be replaced with each member's specific information.
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="send_filtered_sms" class="btn btn-primary" <?php echo !$sms_enabled ? 'disabled' : ''; ?>>
                                            <i class="bi bi-send me-1"></i> Send SMS to Filtered Members
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- SMS API Information Modal -->
    <div class="modal fade" id="smsInfoModal" tabindex="-1" aria-labelledby="smsInfoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="smsInfoModalLabel">BIR SMS API Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-primary">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        This system is configured to use the BIR SMS API for sending SMS notifications to gym members.
                    </div>
                    
                    <h6 class="mt-3">API Configuration</h6>
                    <table class="table table-bordered">
                        <tr>
                            <th width="30%">API Provider</th>
                            <td>BIR SMS</td>
                        </tr>
                        <tr>
                            <th>API URL</th>
                            <td><code><?php echo API_URL; ?></code></td>
                        </tr>
                        <tr>
                            <th>Route ID</th>
                            <td><code><?php echo ROUTE_ID; ?></code></td>
                        </tr>
                    </table>
                    
                    <h6 class="mt-3">SMS Usage Guidelines</h6>
                    <ul>
                        <li>SMS messages are charged per text message sent</li>
                        <li>Keep messages concise to avoid additional charges for long messages</li>
                        <li>Use templates for consistent messaging</li>
                        <li>Avoid sending bulk messages during peak hours</li>
                    </ul>
                    
                    <h6 class="mt-3">SMS Logs</h6>
                    <p>All SMS messages sent through this system are logged in the database for tracking and auditing purposes. You can view the SMS logs in the <a href="sms-logs.php">SMS Logs</a> section.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <script>
        // Handle member selection
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const memberCheckboxes = document.querySelectorAll('.member-checkbox');
            const selectAllBtn = document.getElementById('selectAllBtn');
            const deselectAllBtn = document.getElementById('deselectAllBtn');
            const memberSearch = document.getElementById('memberSearch');
            const clearSearchBtn = document.getElementById('clearSearchBtn');
            const messageTextarea = document.getElementById('message');
            const filteredMessageTextarea = document.getElementById('filtered_message');
            const charCount = document.getElementById('charCount');
            const filteredCharCount = document.getElementById('filteredCharCount');
            const selectMembersForm = document.getElementById('selectMembersForm');
            
            // Select all checkbox functionality
            selectAllCheckbox.addEventListener('change', function() {
                memberCheckboxes.forEach(function(checkbox) {
                    const row = checkbox.closest('tr');
                    if (!row.classList.contains('d-none')) {
                        checkbox.checked = selectAllCheckbox.checked;
                    }
                });
            });
            
            // Select all button
            selectAllBtn.addEventListener('click', function() {
                memberCheckboxes.forEach(function(checkbox) {
                    const row = checkbox.closest('tr');
                    if (!row.classList.contains('d-none')) {
                        checkbox.checked = true;
                    }
                });
                updateSelectAllCheckbox();
            });
            
            // Deselect all button
            deselectAllBtn.addEventListener('click', function() {
                memberCheckboxes.forEach(function(checkbox) {
                    checkbox.checked = false;
                });
                selectAllCheckbox.checked = false;
            });
            
            // Member search functionality
            memberSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const memberRows = document.querySelectorAll('.member-row');
                
                memberRows.forEach(function(row) {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.classList.remove('d-none');
                    } else {
                        row.classList.add('d-none');
                    }
                });
                
                updateSelectAllCheckbox();
            });
            
            // Clear search button
            clearSearchBtn.addEventListener('click', function() {
                memberSearch.value = '';
                const memberRows = document.querySelectorAll('.member-row');
                memberRows.forEach(function(row) {
                    row.classList.remove('d-none');
                });
                updateSelectAllCheckbox();
            });
            
            // Update select all checkbox state
            function updateSelectAllCheckbox() {
                const visibleCheckboxes = Array.from(memberCheckboxes).filter(function(checkbox) {
                    return !checkbox.closest('tr').classList.contains('d-none');
                });
                
                const allChecked = visibleCheckboxes.every(function(checkbox) {
                    return checkbox.checked;
                });
                
                const someChecked = visibleCheckboxes.some(function(checkbox) {
                    return checkbox.checked;
                });
                
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = someChecked && !allChecked;
            }
            
            // Individual checkbox change
            memberCheckboxes.forEach(function(checkbox) {
                checkbox.addEventListener('change', updateSelectAllCheckbox);
            });
            
            // Character count for message
            messageTextarea.addEventListener('input', function() {
                charCount.textContent = this.value.length;
            });
            
            // Character count for filtered message
            filteredMessageTextarea.addEventListener('input', function() {
                filteredCharCount.textContent = this.value.length;
            });
            
            // Form submission validation
            selectMembersForm.addEventListener('submit', function(event) {
                const selectedMembers = document.querySelectorAll('.member-checkbox:checked');
                if (selectedMembers.length === 0) {
                    event.preventDefault();
                    alert('Please select at least one member to send SMS.');
                }
                
                if (messageTextarea.value.trim() === '') {
                    event.preventDefault();
                    alert('Please enter a message to send.');
                }
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert-dismissible');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
        
        // Load template content
        function loadTemplate(templateId) {
            if (!templateId) {
                document.getElementById('message').value = '';
                document.getElementById('charCount').textContent = '0';
                return;
            }
            
            const option = document.querySelector(`#template_select option[value="${templateId}"]`);
            const content = option.getAttribute('data-content');
            
            document.getElementById('message').value = content;
            document.getElementById('charCount').textContent = content.length;
        }
        
        // Load template content for filtered message
        function loadFilteredTemplate(templateId) {
            if (!templateId) {
                document.getElementById('filtered_message').value = '';
                document.getElementById('filteredCharCount').textContent = '0';
                return;
            }
            
            const option = document.querySelector(`#filtered_template_select option[value="${templateId}"]`);
            const content = option.getAttribute('data-content');
            
            document.getElementById('filtered_message').value = content;
            document.getElementById('filteredCharCount').textContent = content.length;
        }
    </script>
</body>
</html>
