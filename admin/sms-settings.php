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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save SMS API Configuration
    if (isset($_POST['save_sms_config'])) {
        $api_provider = sanitizeInput($_POST['api_provider']);
        $api_endpoint = sanitizeInput($_POST['api_endpoint']);
        $api_key = sanitizeInput($_POST['api_key']);
        $sender_id = sanitizeInput($_POST['sender_id']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        try {
            // Check if configuration already exists
            $check_query = "SELECT id FROM sms_config LIMIT 1";
            $check_result = $conn->query($check_query);
            
            if ($check_result->num_rows > 0) {
                // Update existing configuration
                $config_id = $check_result->fetch_assoc()['id'];
                $stmt = $conn->prepare("UPDATE sms_config SET api_provider = ?, api_endpoint = ?, api_key = ?, sender_id = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("ssssii", $api_provider, $api_endpoint, $api_key, $sender_id, $is_active, $config_id);
            } else {
                // Insert new configuration
                $stmt = $conn->prepare("INSERT INTO sms_config (api_provider, api_endpoint, api_key, sender_id, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $api_provider, $api_endpoint, $api_key, $sender_id, $is_active);
            }
            
            if ($stmt->execute()) {
                $success = "SMS configuration saved successfully";
                logActivity($conn, $_SESSION['admin_id'], "Updated SMS API configuration");
            } else {
                $error = "Failed to save SMS configuration: " . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
    
    // Save SMS Template
    elseif (isset($_POST['save_template'])) {
        $template_id = isset($_POST['template_id']) ? (int)$_POST['template_id'] : null;
        $template_name = sanitizeInput($_POST['template_name']);
        $template_type = sanitizeInput($_POST['template_type']);
        $template_content = sanitizeInput($_POST['template_content']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        try {
            if ($template_id) {
                // Update existing template
                $stmt = $conn->prepare("UPDATE sms_templates SET template_name = ?, template_type = ?, template_content = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("sssii", $template_name, $template_type, $template_content, $is_active, $template_id);
            } else {
                // Insert new template
                $stmt = $conn->prepare("INSERT INTO sms_templates (template_name, template_type, template_content, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $template_name, $template_type, $template_content, $is_active);
            }
            
            if ($stmt->execute()) {
                $success = "SMS template saved successfully";
                logActivity($conn, $_SESSION['admin_id'], "Updated SMS template: $template_name");
            } else {
                $error = "Failed to save SMS template: " . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
    
    // Delete SMS Template
    elseif (isset($_POST['delete_template'])) {
        $template_id = (int)$_POST['template_id'];
        
        try {
            $stmt = $conn->prepare("DELETE FROM sms_templates WHERE id = ?");
            $stmt->bind_param("i", $template_id);
            
            if ($stmt->execute()) {
                $success = "SMS template deleted successfully";
                logActivity($conn, $_SESSION['admin_id'], "Deleted SMS template #$template_id");
            } else {
                $error = "Failed to delete SMS template: " . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
    
    // Test SMS
    elseif (isset($_POST['test_sms'])) {
        $phone_number = sanitizeInput($_POST['test_phone']);
        $message = sanitizeInput($_POST['test_message']);
        
        if (empty($phone_number) || empty($message)) {
            $error = "Phone number and message are required for test SMS";
        } else {
            $result = sendSMS($conn, $phone_number, $message);
            
            if ($result['success']) {
                $success = "Test SMS sent successfully";
            } else {
                $error = "Failed to send test SMS: " . $result['message'];
            }
        }
    }
}

// Get current SMS configuration
$sms_config = null;
$config_query = "SELECT * FROM sms_config LIMIT 1";
$config_result = $conn->query($config_query);
if ($config_result->num_rows > 0) {
    $sms_config = $config_result->fetch_assoc();
}

// Get SMS templates
$templates_query = "SELECT * FROM sms_templates ORDER BY template_type, template_name";
$templates_result = $conn->query($templates_query);
$templates = [];
if ($templates_result->num_rows > 0) {
    while ($template = $templates_result->fetch_assoc()) {
        $templates[] = $template;
    }
}

// Get SMS logs
$logs_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $logs_per_page;

$logs_query = "
    SELECT l.*, m.first_name, m.last_name, m.phone, t.template_name
    FROM sms_logs l
    LEFT JOIN gym_members m ON l.member_id = m.id
    LEFT JOIN sms_templates t ON l.template_id = t.id
    ORDER BY l.sent_at DESC
    LIMIT ?, ?
";

$logs_stmt = $conn->prepare($logs_query);
$logs_stmt->bind_param("ii", $offset, $logs_per_page);
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();
$logs = [];
while ($log = $logs_result->fetch_assoc()) {
    $logs[] = $log;
}
$logs_stmt->close();

// Get total logs count for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM sms_logs");
$total_logs = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_logs / $logs_per_page);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Settings - Gym Management System</title>
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
                    <h1 class="h2">SMS Notification Settings</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="send-sms.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-send me-1"></i> Send SMS
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
                
                <!-- SMS Settings Tabs -->
                <ul class="nav nav-tabs mb-4" id="smsSettingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="api-tab" data-bs-toggle="tab" data-bs-target="#api-tab-pane" type="button" role="tab" aria-controls="api-tab-pane" aria-selected="true">
                            <i class="bi bi-gear me-1"></i> API Configuration
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="templates-tab" data-bs-toggle="tab" data-bs-target="#templates-pane" type="button" role="tab" aria-controls="templates-pane" aria-selected="false">
                            <i class="bi bi-file-text me-1"></i> Message Templates
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs-pane" type="button" role="tab" aria-controls="logs-pane" aria-selected="false">
                            <i class="bi bi-list-check me-1"></i> SMS Logs
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="test-tab" data-bs-toggle="tab" data-bs-target="#test-pane" type="button" role="tab" aria-controls="test-pane" aria-selected="false">
                            <i class="bi bi-send-check me-1"></i> Test SMS
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="smsSettingsTabsContent">
                    <!-- API Configuration Tab -->
                    <div class="tab-pane fade show active" id="api-tab-pane" role="tabpanel" aria-labelledby="api-tab" tabindex="0">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">SMS API Configuration</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="api_provider" class="form-label">API Provider</label>
                                        <select class="form-select" id="api_provider" name="api_provider" required>
                                            <option value="">Select Provider</option>
                                            <option value="textlocal" <?php echo ($sms_config && $sms_config['api_provider'] == 'textlocal') ? 'selected' : ''; ?>>TextLocal</option>
                                            <option value="twilio" <?php echo ($sms_config && $sms_config['api_provider'] == 'twilio') ? 'selected' : ''; ?>>Twilio</option>
                                            <option value="msg91" <?php echo ($sms_config && $sms_config['api_provider'] == 'msg91') ? 'selected' : ''; ?>>MSG91</option>
                                            <option value="custom" <?php echo ($sms_config && $sms_config['api_provider'] == 'custom') ? 'selected' : ''; ?>>Custom</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="api_endpoint" class="form-label">API Endpoint URL</label>
                                        <input type="url" class="form-control" id="api_endpoint" name="api_endpoint" value="<?php echo $sms_config ? htmlspecialchars($sms_config['api_endpoint']) : ''; ?>" required>
                                        <div class="form-text">The full URL of the SMS API endpoint</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="api_key" class="form-label">API Key</label>
                                        <input type="text" class="form-control" id="api_key" name="api_key" value="<?php echo $sms_config ? htmlspecialchars($sms_config['api_key']) : ''; ?>" required>
                                        <div class="form-text">Your API key or authentication token</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="sender_id" class="form-label">Sender ID</label>
                                        <input type="text" class="form-control" id="sender_id" name="sender_id" value="<?php echo $sms_config ? htmlspecialchars($sms_config['sender_id']) : ''; ?>" required>
                                        <div class="form-text">The sender ID or name that will appear on the recipient's phone</div>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" <?php echo ($sms_config && $sms_config['is_active']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">Enable SMS Notifications</label>
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="save_sms_config" class="btn btn-primary">
                                            <i class="bi bi-save me-1"></i> Save Configuration
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Message Templates Tab -->
                    <div class="tab-pane fade" id="templates-pane" role="tabpanel" aria-labelledby="templates-tab" tabindex="0">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">SMS Message Templates</h5>
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
                                    <i class="bi bi-plus-circle me-1"></i> Add Template
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Type</th>
                                                <th>Content</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($templates) > 0): ?>
                                                <?php foreach ($templates as $template): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($template['template_name']); ?></td>
                                                        <td>
                                                            <span class="badge <?php 
                                                                echo $template['template_type'] === 'activation' ? 'bg-success' : 
                                                                    ($template['template_type'] === 'renewal' ? 'bg-warning' : 'bg-info'); 
                                                            ?>">
                                                                <?php echo ucfirst($template['template_type']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                                <?php echo htmlspecialchars($template['template_content']); ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?php echo $template['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                                                <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <button type="button" class="btn btn-primary edit-template-btn" 
                                                                        data-id="<?php echo $template['id']; ?>"
                                                                        data-name="<?php echo htmlspecialchars($template['template_name']); ?>"
                                                                        data-type="<?php echo $template['template_type']; ?>"
                                                                        data-content="<?php echo htmlspecialchars($template['template_content']); ?>"
                                                                        data-active="<?php echo $template['is_active']; ?>"
                                                                        data-bs-toggle="modal" data-bs-target="#editTemplateModal">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteTemplateModal<?php echo $template['id']; ?>">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </div>
                                                            
                                                            <!-- Delete Template Modal -->
                                                            <div class="modal fade" id="deleteTemplateModal<?php echo $template['id']; ?>" tabindex="-1" aria-labelledby="deleteTemplateModalLabel<?php echo $template['id']; ?>" aria-hidden="true">
                                                                <div class="modal-dialog">
                                                                    <div class="modal-content">
                                                                        <div class="modal-header">
                                                                            <h5 class="modal-title" id="deleteTemplateModalLabel<?php echo $template['id']; ?>">Confirm Delete</h5>
                                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                        </div>
                                                                        <div class="modal-body">
                                                                            <p>Are you sure you want to delete the template "<?php echo htmlspecialchars($template['template_name']); ?>"?</p>
                                                                            <div class="alert alert-warning">
                                                                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                                                                This action cannot be undone.
                                                                            </div>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                            <form method="POST" action="">
                                                                                <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                                                                <button type="submit" name="delete_template" class="btn btn-danger">Delete</button>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">No templates found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <h6>Available Placeholders:</h6>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item"><code>{member_name}</code> - Member's full name</li>
                                        <li class="list-group-item"><code>{plan_name}</code> - Membership plan name</li>
                                        <li class="list-group-item"><code>{expiry_date}</code> - Membership expiry date</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SMS Logs Tab -->
                    <div class="tab-pane fade" id="logs-pane" role="tabpanel" aria-labelledby="logs-tab" tabindex="0">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">SMS Logs</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date/Time</th>
                                                <th>Recipient</th>
                                                <th>Template</th>
                                                <th>Message</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($logs) > 0): ?>
                                                <?php foreach ($logs as $log): ?>
                                                    <tr>
                                                        <td><?php echo date('d-m-Y H:i:s', strtotime($log['sent_at'])); ?></td>
                                                        <td>
                                                            <?php if ($log['member_id']): ?>
                                                                <a href="view-member.php?id=<?php echo $log['member_id']; ?>">
                                                                    <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                                                </a><br>
                                                                <small><?php echo htmlspecialchars($log['phone_number']); ?></small>
                                                            <?php else: ?>
                                                                <?php echo htmlspecialchars($log['phone_number']); ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo $log['template_name'] ? htmlspecialchars($log['template_name']) : 'Custom'; ?></td>
                                                        <td>
                                                            <div style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                                <?php echo htmlspecialchars($log['message']); ?>
                                                            </div>
                                                            <button type="button" class="btn btn-sm btn-link view-message" data-bs-toggle="modal" data-bs-target="#viewMessageModal" data-message="<?php echo htmlspecialchars($log['message']); ?>">
                                                                View
                                                            </button>
                                                        </td>
                                                        <td>
                                                            <span class="badge <?php 
                                                                echo $log['status'] === 'sent' ? 'bg-success' : 
                                                                    ($log['status'] === 'failed' ? 'bg-danger' : 'bg-warning'); 
                                                            ?>">
                                                                <?php echo ucfirst($log['status']); ?>
                                                            </span>
                                                            <?php if ($log['status'] === 'failed' && !empty($log['error_message'])): ?>
                                                                <button type="button" class="btn btn-sm btn-link text-danger view-error" data-bs-toggle="modal" data-bs-target="#viewErrorModal" data-error="<?php echo htmlspecialchars($log['error_message']); ?>">
                                                                    Error
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">No SMS logs found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                    <nav aria-label="SMS logs pagination" class="mt-4">
                                        <ul class="pagination justify-content-center">
                                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?>#logs-pane" tabindex="-1" aria-disabled="<?php echo ($page <= 1) ? 'true' : 'false'; ?>">Previous</a>
                                            </li>
                                            
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?>#logs-pane"><?php echo $i; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?>#logs-pane">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Test SMS Tab -->
                    <div class="tab-pane fade" id="test-pane" role="tabpanel" aria-labelledby="test-tab" tabindex="0">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Test SMS Notification</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="test_phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="test_phone" name="test_phone" placeholder="Enter phone number" required>
                                        <div class="form-text">Enter the phone number with country code (e.g., 919876543210 for India)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="test_message" class="form-label">Message</label>
                                        <textarea class="form-control" id="test_message" name="test_message" rows="4" required>This is a test message from your gym management system.</textarea>
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="test_sms" class="btn btn-primary">
                                            <i class="bi bi-send me-1"></i> Send Test SMS
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Add Template Modal -->
                <div class="modal fade" id="addTemplateModal" tabindex="-1" aria-labelledby="addTemplateModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addTemplateModalLabel">Add SMS Template</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="template_name" class="form-label">Template Name</label>
                                        <input type="text" class="form-control" id="template_name" name="template_name" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="template_type" class="form-label">Template Type</label>
                                        <select class="form-select" id="template_type" name="template_type" required>
                                            <option value="activation">Membership Activation</option>
                                            <option value="renewal">Membership Renewal</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="template_content" class="form-label">Template Content</label>
                                        <textarea class="form-control" id="template_content" name="template_content" rows="5" required></textarea>
                                        <div class="form-text">
                                            Available placeholders: {member_name}, {plan_name}, {expiry_date}
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="save_template" class="btn btn-primary">Save Template</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Template Modal -->
                <div class="modal fade" id="editTemplateModal" tabindex="-1" aria-labelledby="editTemplateModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editTemplateModalLabel">Edit SMS Template</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <div class="modal-body">
                                    <input type="hidden" id="edit_template_id" name="template_id">
                                    
                                    <div class="mb-3">
                                        <label for="edit_template_name" class="form-label">Template Name</label>
                                        <input type="text" class="form-control" id="edit_template_name" name="template_name" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="edit_template_type" class="form-label">Template Type</label>
                                        <select class="form-select" id="edit_template_type" name="template_type" required>
                                            <option value="activation">Membership Activation</option>
                                            <option value="renewal">Membership Renewal</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="edit_template_content" class="form-label">Template Content</label>
                                        <textarea class="form-control" id="edit_template_content" name="template_content" rows="5" required></textarea>
                                        <div class="form-text">
                                            Available placeholders: {member_name}, {plan_name}, {expiry_date}
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active">
                                        <label class="form-check-label" for="edit_is_active">Active</label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="save_template" class="btn btn-primary">Update Template</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- View Message Modal -->
                <div class="modal fade" id="viewMessageModal" tabindex="-1" aria-labelledby="viewMessageModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="viewMessageModalLabel">SMS Message</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="p-3 bg-light rounded">
                                    <p id="fullMessage" class="mb-0"></p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- View Error Modal -->
                <div class="modal fade" id="viewErrorModal" tabindex="-1" aria-labelledby="viewErrorModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="viewErrorModalLabel">Error Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="p-3 bg-light rounded">
                                    <p id="errorMessage" class="mb-0 text-danger"></p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <script>
        // Handle tab activation from URL hash
        document.addEventListener('DOMContentLoaded', function() {
            // Get hash from URL (remove the # symbol)
            const hash = window.location.hash.substring(1);
            
            // If hash exists and corresponds to a tab, activate that tab
            if (hash) {
                const tabId = hash.replace('-pane', '');
                const tabElement = document.getElementById(tabId);
                
                if (tabElement) {
                    const tab = new bootstrap.Tab(tabElement);
                    tab.show();
                }
            }
            
            // Edit template modal
            const editButtons = document.querySelectorAll('.edit-template-btn');
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const type = this.getAttribute('data-type');
                    const content = this.getAttribute('data-content');
                    const active = this.getAttribute('data-active') === '1';
                    
                    document.getElementById('edit_template_id').value = id;
                    document.getElementById('edit_template_name').value = name;
                    document.getElementById('edit_template_type').value = type;
                    document.getElementById('edit_template_content').value = content;
                    document.getElementById('edit_is_active').checked = active;
                });
            });
            
            // View message modal
            const viewMessageButtons = document.querySelectorAll('.view-message');
            viewMessageButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const message = this.getAttribute('data-message');
                    document.getElementById('fullMessage').textContent = message;
                });
            });
            
            // View error modal
            const viewErrorButtons = document.querySelectorAll('.view-error');
            viewErrorButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const error = this.getAttribute('data-error');
                    document.getElementById('errorMessage').textContent = error;
                });
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>
