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
$memberships = [];

// Check if membership_plans table exists, create if not
$table_exists = $conn->query("SHOW TABLES LIKE 'membership_plans'");
if ($table_exists->num_rows == 0) {
    try {
        // Create membership_plans table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS membership_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            duration INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description TEXT,
            features TEXT,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        if ($conn->query($create_table)) {
            $success = "Membership plans table created successfully.";
            
            // Insert some default plans
            $default_plans = [
                ['Basic Monthly', 30, 49.99, 'Basic gym access with standard amenities', 'Gym access,Locker room,Basic equipment', 'active'],
                ['Premium Quarterly', 90, 129.99, 'Premium access with additional benefits', 'Gym access,Locker room,All equipment,1 free PT session', 'active'],
                ['Elite Annual', 365, 399.99, 'Full access to all facilities and services', 'Gym access,Locker room,All equipment,5 free PT sessions,Towel service,Sauna access', 'active']
            ];
            
            $stmt = $conn->prepare("INSERT INTO membership_plans (name, duration, price, description, features, status) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($default_plans as $plan) {
                $stmt->bind_param("sidsss", $plan[0], $plan[1], $plan[2], $plan[3], $plan[4], $plan[5]);
                $stmt->execute();
            }
            $stmt->close();
            
            $success .= " Default plans added.";
        } else {
            $error = "Failed to create membership plans table: " . $conn->error;
        }
    } catch (Exception $e) {
        $error = "System error: " . $e->getMessage();
    }
}

// Handle membership actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CREATE operation
    if (isset($_POST['add_membership'])) {
        $name = sanitizeInput($_POST['name']);
        $duration = (int)$_POST['duration'];
        $price = (float)$_POST['price'];
        $description = sanitizeInput($_POST['description']);
        $features = isset($_POST['features']) ? sanitizeInput($_POST['features']) : '';
        $status = $_POST['status'];
        
        // Validate input
        $validation_errors = [];
        if (empty($name)) $validation_errors[] = "Plan name is required.";
        if ($duration <= 0) $validation_errors[] = "Duration must be greater than 0.";
        if ($price < 0) $validation_errors[] = "Price cannot be negative.";
        
        if (empty($validation_errors)) {
            try {
                $stmt = $conn->prepare("INSERT INTO membership_plans (name, duration, price, description, features, status) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("sidsss", $name, $duration, $price, $description, $features, $status);
                
                if ($stmt->execute()) {
                    $success = "Membership plan added successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Added new membership plan: $name");
                    
                    // Clear form data after successful submission
                    $_POST = array();
                } else {
                    $error = "Failed to add membership plan: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        } else {
            $error = "Validation errors: " . implode(" ", $validation_errors);
        }
    } 
    // UPDATE operation
    elseif (isset($_POST['update_membership'])) {
        $id = (int)$_POST['membership_id'];
        $name = sanitizeInput($_POST['name']);
        $duration = (int)$_POST['duration'];
        $price = (float)$_POST['price'];
        $description = sanitizeInput($_POST['description']);
        $features = isset($_POST['features']) ? sanitizeInput($_POST['features']) : '';
        $status = $_POST['status'];
        
        // Validate input
        $validation_errors = [];
        if (empty($name)) $validation_errors[] = "Plan name is required.";
        if ($duration <= 0) $validation_errors[] = "Duration must be greater than 0.";
        if ($price < 0) $validation_errors[] = "Price cannot be negative.";
        
        if (empty($validation_errors)) {
            try {
                $stmt = $conn->prepare("UPDATE membership_plans SET name = ?, duration = ?, price = ?, description = ?, features = ?, status = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("sidsssi", $name, $duration, $price, $description, $features, $status, $id);
                
                if ($stmt->execute()) {
                    $success = "Membership plan updated successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Updated membership plan #$id: $name");
                } else {
                    $error = "Failed to update membership plan: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        } else {
            $error = "Validation errors: " . implode(" ", $validation_errors);
        }
    } 
    // DELETE operation
    elseif (isset($_POST['delete_membership'])) {
        $id = (int)$_POST['membership_id'];
        
        try {
            // Check if membership is in use
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM gym_members WHERE membership_id = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            $stmt->close();
            
            if ($count > 0) {
                $error = "Cannot delete this membership plan as it is assigned to $count member(s).";
            } else {
                // Get plan name for activity log
                $stmt = $conn->prepare("SELECT name FROM membership_plans WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $plan_name = $result->fetch_assoc()['name'];
                $stmt->close();
                
                // Delete the plan
                $stmt = $conn->prepare("DELETE FROM membership_plans WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $success = "Membership plan deleted successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Deleted membership plan #$id: $plan_name");
                } else {
                    $error = "Failed to delete membership plan: " . $stmt->error;
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
    // DUPLICATE operation
    elseif (isset($_POST['duplicate_membership'])) {
        $id = (int)$_POST['membership_id'];
        
        try {
            // Get the plan details
            $stmt = $conn->prepare("SELECT name, duration, price, description, features, status FROM membership_plans WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $plan = $result->fetch_assoc();
            $stmt->close();
            
            if ($plan) {
                // Create a copy with "Copy of" prefix
                $new_name = "Copy of " . $plan['name'];
                $stmt = $conn->prepare("INSERT INTO membership_plans (name, duration, price, description, features, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sidsss", $new_name, $plan['duration'], $plan['price'], $plan['description'], $plan['features'], $plan['status']);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    $success = "Membership plan duplicated successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Duplicated membership plan #$id to #$new_id: $new_name");
                } else {
                    $error = "Failed to duplicate membership plan: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Membership plan not found";
            }
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
    // BULK DELETE operation
    elseif (isset($_POST['bulk_delete'])) {
        if (isset($_POST['selected_plans']) && is_array($_POST['selected_plans'])) {
            $selected_plans = $_POST['selected_plans'];
            $deleted_count = 0;
            $error_count = 0;
            
            foreach ($selected_plans as $plan_id) {
                $id = (int)$plan_id;
                
                // Check if membership is in use
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM gym_members WHERE membership_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = $result->fetch_assoc()['count'];
                $stmt->close();
                
                if ($count > 0) {
                    $error_count++;
                    continue;
                }
                
                // Delete the plan
                $stmt = $conn->prepare("DELETE FROM membership_plans WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $deleted_count++;
                    logActivity($conn, $_SESSION['admin_id'], "Deleted membership plan #$id (bulk delete)");
                } else {
                    $error_count++;
                }
                $stmt->close();
            }
            
            if ($deleted_count > 0) {
                $success = "$deleted_count membership plan(s) deleted successfully";
            }
            
            if ($error_count > 0) {
                $error = "$error_count membership plan(s) could not be deleted (may be in use or other error)";
            }
        } else {
            $error = "No plans selected for deletion";
        }
    }
    // BULK STATUS UPDATE operation
    elseif (isset($_POST['bulk_status_update'])) {
        if (isset($_POST['selected_plans']) && is_array($_POST['selected_plans']) && isset($_POST['bulk_status'])) {
            $selected_plans = $_POST['selected_plans'];
            $new_status = $_POST['bulk_status'];
            $updated_count = 0;
            
            if ($new_status === 'active' || $new_status === 'inactive') {
                foreach ($selected_plans as $plan_id) {
                    $id = (int)$plan_id;
                    
                    $stmt = $conn->prepare("UPDATE membership_plans SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $new_status, $id);
                    if ($stmt->execute()) {
                        $updated_count++;
                        logActivity($conn, $_SESSION['admin_id'], "Updated membership plan #$id status to $new_status (bulk update)");
                    }
                    $stmt->close();
                }
                
                if ($updated_count > 0) {
                    $success = "$updated_count membership plan(s) updated to $new_status status";
                } else {
                    $error = "No plans were updated";
                }
            } else {
                $error = "Invalid status value";
            }
        } else {
            $error = "No plans selected for status update";
        }
    }
}

// READ operation - Get all membership plans with sorting and filtering
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$sort_order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$search_term = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Validate sort column to prevent SQL injection
$allowed_sort_columns = ['id', 'name', 'duration', 'price', 'status', 'created_at'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'id';
}

// Build the query with filters
$query = "SELECT * FROM membership_plans WHERE 1=1";

if ($filter_status !== '') {
    $query .= " AND status = '" . $conn->real_escape_string($filter_status) . "'";
}

if ($search_term !== '') {
    $query .= " AND (name LIKE '%" . $conn->real_escape_string($search_term) . "%' OR 
                     description LIKE '%" . $conn->real_escape_string($search_term) . "%')";
}

$query .= " ORDER BY $sort_by $sort_order";

try {
    $result = $conn->query($query);
    $memberships = $result;
    $total_plans = $memberships->num_rows;
} catch (Exception $e) {
    $error = "Error retrieving membership plans: " . $e->getMessage();
}

// Get usage statistics for each plan
$plan_usage = [];
try {
    $usage_query = "SELECT membership_id, COUNT(*) as member_count FROM gym_members GROUP BY membership_id";
    $usage_result = $conn->query($usage_query);
    if ($usage_result) {
        while ($row = $usage_result->fetch_assoc()) {
            $plan_usage[$row['membership_id']] = $row['member_count'];
        }
    }
} catch (Exception $e) {
    // Silently handle error, not critical
    error_log("Error getting plan usage: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Memberships - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .feature-badge {
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px;
            padding: 5px 10px;
            border-radius: 15px;
            background-color: #f0f0f0;
            font-size: 0.8rem;
        }
        .plan-card {
            transition: transform 0.2s;
            height: 100%;
        }
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .action-buttons {
            white-space: nowrap;
        }
        .sort-icon {
            font-size: 0.7rem;
            margin-left: 5px;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .bulk-actions {
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>
            
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Manage Membership Plans</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewCardsModal">
                                <i class="bi bi-grid me-1"></i> Card View
                            </button>
                            <a href="membership-analytics.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-graph-up me-1"></i> Analytics
                            </a>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addMembershipModal">
                            <i class="bi bi-plus-circle me-1"></i> Add New Plan
                        </button>
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
                
                <!-- Filter and Search Section -->
                <div class="filter-section">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Search plans..." name="search" value="<?php echo htmlspecialchars($search_term); ?>">
                                <button class="btn btn-outline-secondary" type="submit">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" name="status">
                                <option value="" <?php echo $filter_status === '' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" name="sort">
                                <option value="id" <?php echo $sort_by === 'id' ? 'selected' : ''; ?>>Sort by ID</option>
                                <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Sort by Name</option>
                                <option value="duration" <?php echo $sort_by === 'duration' ? 'selected' : ''; ?>>Sort by Duration</option>
                                <option value="price" <?php echo $sort_by === 'price' ? 'selected' : ''; ?>>Sort by Price</option>
                                <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Sort by Date Added</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="order">
                                <option value="asc" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                                <option value="desc" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <a href="memberships.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
                
                <!-- Bulk Actions -->
                <form method="POST" action="" id="bulkActionForm">
                    <div class="bulk-actions">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        Bulk Actions
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><button type="submit" name="bulk_status_update" class="dropdown-item" onclick="document.getElementById('bulk_status').value='active';">Set Active</button></li>
                                        <li><button type="submit" name="bulk_status_update" class="dropdown-item" onclick="document.getElementById('bulk_status').value='inactive';">Set Inactive</button></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button type="submit" name="bulk_delete" class="dropdown-item text-danger" onclick="return confirm('Are you sure you want to delete the selected plans? This cannot be undone.')">Delete Selected</button></li>
                                    </ul>
                                </div>
                                <input type="hidden" name="bulk_status" id="bulk_status" value="">
                            </div>
                            <div class="col-md-6 text-end">
                                <span class="text-muted">Total: <?php echo $total_plans; ?> plan(s)</span>
                            </div>
                        </div>
                    </div>
                
                    <!-- Membership Plans List -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Membership Plans</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th width="40">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                                </div>
                                            </th>
                                            <th>
                                                <a href="?sort=id&order=<?php echo $sort_by === 'id' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search_term); ?>">
                                                    ID
                                                    <?php if ($sort_by === 'id'): ?>
                                                        <i class="bi bi-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?sort=name&order=<?php echo $sort_by === 'name' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search_term); ?>">
                                                    Name
                                                    <?php if ($sort_by === 'name'): ?>
                                                        <i class="bi bi-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?sort=duration&order=<?php echo $sort_by === 'duration' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search_term); ?>">
                                                    Duration
                                                    <?php if ($sort_by === 'duration'): ?>
                                                        <i class="bi bi-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="?sort=price&order=<?php echo $sort_by === 'price' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search_term); ?>">
                                                    Price
                                                    <?php if ($sort_by === 'price'): ?>
                                                        <i class="bi bi-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Features</th>
                                            <th>Usage</th>
                                            <th>
                                                <a href="?sort=status&order=<?php echo $sort_by === 'status' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&status=<?php echo $filter_status; ?>&search=<?php echo urlencode($search_term); ?>">
                                                    Status
                                                    <?php if ($sort_by === 'status'): ?>
                                                        <i class="bi bi-arrow-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?> sort-icon"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($memberships) && $memberships->num_rows > 0): ?>
                                            <?php while ($plan = $memberships->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <div class="form-check">
                                                            <input class="form-check-input plan-checkbox" type="checkbox" name="selected_plans[]" value="<?php echo $plan['id']; ?>">
                                                        </div>
                                                    </td>
                                                    <td><?php echo $plan['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($plan['name']); ?></td>
                                                    <td>
                                                        <?php 
                                                            if ($plan['duration'] == 30) echo "1 Month";
                                                            elseif ($plan['duration'] == 90) echo "3 Months";
                                                            elseif ($plan['duration'] == 180) echo "6 Months";
                                                            elseif ($plan['duration'] == 365) echo "1 Year";
                                                            else echo $plan['duration'] . " days";
                                                        ?>
                                                    </td>
                                                    <td>Rs <?php echo number_format($plan['price'], 2); ?></td>
                                                    <td>
                                                        <?php if (!empty($plan['features'])): ?>
                                                            <?php 
                                                                $features = explode(',', $plan['features']);
                                                                $display_features = array_slice($features, 0, 2);
                                                                foreach ($display_features as $feature): 
                                                            ?>
                                                                <span class="feature-badge"><?php echo htmlspecialchars($feature); ?></span>
                                                            <?php endforeach; ?>
                                                            
                                                            <?php if (count($features) > 2): ?>
                                                                <span class="badge bg-secondary">+<?php echo count($features) - 2; ?> more</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">No features listed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                            $usage_count = isset($plan_usage[$plan['id']]) ? $plan_usage[$plan['id']] : 0;
                                                            echo $usage_count . ' member' . ($usage_count != 1 ? 's' : '');
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $plan['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo ucfirst($plan['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="action-buttons">
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#viewMembershipModal<?php echo $plan['id']; ?>">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editMembershipModal<?php echo $plan['id']; ?>">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#duplicateMembershipModal<?php echo $plan['id']; ?>">
                                                                <i class="bi bi-copy"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMembershipModal<?php echo $plan['id']; ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                
                                                <!-- View Membership Modal -->
                                                <div class="modal fade" id="viewMembershipModal<?php echo $plan['id']; ?>" tabindex="-1" aria-labelledby="viewMembershipModalLabel<?php echo $plan['id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="viewMembershipModalLabel<?php echo $plan['id']; ?>">View Membership Plan</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="card">
                                                                    <div class="card-header bg-primary text-white">
                                                                        <h5 class="mb-0"><?php echo htmlspecialchars($plan['name']); ?></h5>
                                                                    </div>
                                                                    <div class="card-body">
                                                                        <h3 class="card-title pricing-card-title">Rs <?php echo number_format($plan['price'], 2); ?></h3>
                                                                        <p class="text-muted">
                                                                            <?php 
                                                                                if ($plan['duration'] == 30) echo "1 Month";
                                                                                elseif ($plan['duration'] == 90) echo "3 Months";
                                                                                elseif ($plan['duration'] == 180) echo "6 Months";
                                                                                elseif ($plan['duration'] == 365) echo "1 Year";
                                                                                else echo $plan['duration'] . " days";
                                                                            ?>
                                                                        </p>
                                                                        <p><?php echo htmlspecialchars($plan['description']); ?></p>
                                                                        
                                                                        <h6 class="mt-4 mb-3">Features:</h6>
                                                                        <?php if (!empty($plan['features'])): ?>
                                                                            <ul class="list-group list-group-flush">
                                                                                <?php foreach (explode(',', $plan['features']) as $feature): ?>
                                                                                    <li class="list-group-item">
                                                                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                                                                        <?php echo htmlspecialchars($feature); ?>
                                                                                    </li>
                                                                                <?php endforeach; ?>
                                                                            </ul>
                                                                        <?php else: ?>
                                                                            <p class="text-muted">No features listed</p>
                                                                        <?php endif; ?>
                                                                        
                                                                        <div class="mt-4">
                                                                            <p><strong>Status:</strong> 
                                                                                <span class="badge <?php echo $plan['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                                                    <?php echo ucfirst($plan['status']); ?>
                                                                                </span>
                                                                            </p>
                                                                            <p><strong>Created:</strong> <?php echo date('M d, Y', strtotime($plan['created_at'])); ?></p>
                                                                            <p><strong>Last Updated:</strong> <?php echo date('M d, Y', strtotime($plan['updated_at'])); ?></p>
                                                                            <p><strong>Current Usage:</strong> <?php echo isset($plan_usage[$plan['id']]) ? $plan_usage[$plan['id']] : 0; ?> member(s)</p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editMembershipModal<?php echo $plan['id']; ?>" data-bs-dismiss="modal">Edit Plan</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Edit Membership Modal -->
                                                <div class="modal fade" id="editMembershipModal<?php echo $plan['id']; ?>" tabindex="-1" aria-labelledby="editMembershipModalLabel<?php echo $plan['id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="editMembershipModalLabel<?php echo $plan['id']; ?>">Edit Membership Plan</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <form method="POST" action="">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="membership_id" value="<?php echo $plan['id']; ?>">
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="name<?php echo $plan['id']; ?>" class="form-label">Plan Name</label>
                                                                        <input type="text" class="form-control" id="name<?php echo $plan['id']; ?>" name="name" value="<?php echo htmlspecialchars($plan['name']); ?>" required>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="duration<?php echo $plan['id']; ?>" class="form-label">Duration (days)</label>
                                                                        <input type="number" class="form-control" id="duration<?php echo $plan['id']; ?>" name="duration" value="<?php echo $plan['duration']; ?>" required min="1">
                                                                        <div class="form-text">Common durations: 30 days (1 month), 90 days (3 months), 180 days (6 months), 365 days (1 year)</div>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="price<?php echo $plan['id']; ?>" class="form-label">Price (Rs)</label>
                                                                        <input type="number" class="form-control" id="price<?php echo $plan['id']; ?>" name="price" value="<?php echo $plan['price']; ?>" required min="0" step="0.01">
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="description<?php echo $plan['id']; ?>" class="form-label">Description</label>
                                                                        <textarea class="form-control" id="description<?php echo $plan['id']; ?>" name="description" rows="3"><?php echo htmlspecialchars($plan['description']); ?></textarea>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="features<?php echo $plan['id']; ?>" class="form-label">Features (comma separated)</label>
                                                                        <textarea class="form-control" id="features<?php echo $plan['id']; ?>" name="features" rows="3" placeholder="Gym access,Locker room,Towel service"><?php echo htmlspecialchars($plan['features']); ?></textarea>
                                                                        <div class="form-text">Enter features separated by commas</div>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="status<?php echo $plan['id']; ?>" class="form-label">Status</label>
                                                                        <select class="form-select" id="status<?php echo $plan['id']; ?>" name="status" required>
                                                                            <option value="active" <?php echo $plan['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                            <option value="inactive" <?php echo $plan['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" name="update_membership" class="btn btn-primary">Update Plan</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Duplicate Membership Modal -->
                                                <div class="modal fade" id="duplicateMembershipModal<?php echo $plan['id']; ?>" tabindex="-1" aria-labelledby="duplicateMembershipModalLabel<?php echo $plan['id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="duplicateMembershipModalLabel<?php echo $plan['id']; ?>">Duplicate Membership Plan</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>Are you sure you want to create a duplicate of the "<?php echo htmlspecialchars($plan['name']); ?>" membership plan?</p>
                                                                <p>A new plan will be created with "Copy of" prefix and the same settings.</p>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <form method="POST" action="">
                                                                    <input type="hidden" name="membership_id" value="<?php echo $plan['id']; ?>">
                                                                    <button type="submit" name="duplicate_membership" class="btn btn-success">Duplicate</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Delete Membership Modal -->
                                                <div class="modal fade" id="deleteMembershipModal<?php echo $plan['id']; ?>" tabindex="-1" aria-labelledby="deleteMembershipModalLabel<?php echo $plan['id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="deleteMembershipModalLabel<?php echo $plan['id']; ?>">Confirm Delete</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>Are you sure you want to delete the "<?php echo htmlspecialchars($plan['name']); ?>" membership plan?</p>
                                                                <div class="alert alert-warning">
                                                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                                                    This action cannot be undone. If members are currently using this plan, you will not be able to delete it.
                                                                </div>
                                                                <?php if (isset($plan_usage[$plan['id']]) && $plan_usage[$plan['id']] > 0): ?>
                                                                    <div class="alert alert-danger">
                                                                        <i class="bi bi-x-circle-fill me-2"></i>
                                                                        This plan is currently assigned to <?php echo $plan_usage[$plan['id']]; ?> member(s) and cannot be deleted.
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <form method="POST" action="">
                                                                    <input type="hidden" name="membership_id" value="<?php echo $plan['id']; ?>">
                                                                    <button type="submit" name="delete_membership" class="btn btn-danger" <?php echo (isset($plan_usage[$plan['id']]) && $plan_usage[$plan['id']] > 0) ? 'disabled' : ''; ?>>Delete</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center">No membership plans found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
                
                <!-- Add Membership Modal -->
                <div class="modal fade" id="addMembershipModal" tabindex="-1" aria-labelledby="addMembershipModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addMembershipModalLabel">Add New Membership Plan</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Plan Name</label>
                                        <input type="text" class="form-control" id="name" name="name" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="duration" class="form-label">Duration (days)</label>
                                        <input type="number" class="form-control" id="duration" name="duration" required min="1" value="30">
                                        <div class="form-text">Common durations: 30 days (1 month), 90 days (3 months), 180 days (6 months), 365 days (1 year)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="price" class="form-label">Price (Rs)</label>
                                        <input type="number" class="form-control" id="price" name="price" required min="0" step="0.01">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="features" class="form-label">Features (comma separated)</label>
                                        <textarea class="form-control" id="features" name="features" rows="3" placeholder="Gym access,Locker room,Towel service"></textarea>
                                        <div class="form-text">Enter features separated by commas</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="active" selected>Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="add_membership" class="btn btn-primary">Add Plan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Card View Modal -->
                <div class="modal fade" id="viewCardsModal" tabindex="-1" aria-labelledby="viewCardsModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="viewCardsModalLabel">Membership Plans - Card View</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <?php 
                                    if (is_object($memberships)) {
                                        $memberships->data_seek(0); // Reset result pointer
                                        while ($plan = $memberships->fetch_assoc()): 
                                    ?>
                                        <div class="col-md-4 mb-4">
                                            <div class="card plan-card h-100">
                                                <div class="card-header bg-primary text-white">
                                                    <h5 class="mb-0"><?php echo htmlspecialchars($plan['name']); ?></h5>
                                                    <span class="status-badge badge <?php echo $plan['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo ucfirst($plan['status']); ?>
                                                    </span>
                                                </div>
                                                <div class="card-body">
                                                    <h3 class="card-title pricing-card-title">Rs <?php echo number_format($plan['price'], 2); ?></h3>
                                                    <p class="text-muted">
                                                        <?php 
                                                            if ($plan['duration'] == 30) echo "1 Month";
                                                            elseif ($plan['duration'] == 90) echo "3 Months";
                                                            elseif ($plan['duration'] == 180) echo "6 Months";
                                                            elseif ($plan['duration'] == 365) echo "1 Year";
                                                            else echo $plan['duration'] . " days";
                                                        ?>
                                                    </p>
                                                    <p><?php echo htmlspecialchars($plan['description']); ?></p>
                                                    
                                                    <?php if (!empty($plan['features'])): ?>
                                                        <ul class="list-group list-group-flush mt-3">
                                                            <?php foreach (explode(',', $plan['features']) as $feature): ?>
                                                                <li class="list-group-item">
                                                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                                                    <?php echo htmlspecialchars($feature); ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="card-footer">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-muted">
                                                            <?php echo isset($plan_usage[$plan['id']]) ? $plan_usage[$plan['id']] . ' member(s)' : 'No members'; ?>
                                                        </small>
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editMembershipModal<?php echo $plan['id']; ?>" data-bs-dismiss="modal">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMembershipModal<?php echo $plan['id']; ?>" data-bs-dismiss="modal">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php 
                                        endwhile;
                                    }
                                    ?>
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
        // Select all checkbox functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.getElementsByClassName('plan-checkbox');
            for (let checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });
        
        // Check if any checkbox is checked
        const planCheckboxes = document.getElementsByClassName('plan-checkbox');
        for (let checkbox of planCheckboxes) {
            checkbox.addEventListener('change', function() {
                const allChecked = document.querySelectorAll('.plan-checkbox:checked').length === planCheckboxes.length;
                document.getElementById('selectAll').checked = allChecked;
            });
        }
        
        // Auto-dismiss alerts after 5 seconds
        window.addEventListener('DOMContentLoaded', (event) => {
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
