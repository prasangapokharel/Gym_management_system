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
$members = [];
$total_records = 0;
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Handle member actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_member'])) {
        $member_id = $_POST['member_id'];
        
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            // Delete related records first (to handle foreign key constraints)
            // Delete from notifications
            $stmt = $conn->prepare("DELETE FROM notifications WHERE member_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Delete from membership_history
            $stmt = $conn->prepare("DELETE FROM membership_history WHERE member_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Delete from payments
            $stmt = $conn->prepare("DELETE FROM payments WHERE member_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Finally delete the member
            $stmt = $conn->prepare("DELETE FROM gym_members WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("i", $member_id);
            
            if ($stmt->execute()) {
                // Commit transaction
                $conn->commit();
                $success = "Member deleted successfully";
                logActivity($conn, $_SESSION['admin_id'], "Deleted member #$member_id");
            } else {
                throw new Exception("Failed to delete member");
            }
            $stmt->close();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = "System error: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_status'])) {
        $member_id = $_POST['member_id'];
        $status = $_POST['status'];
        
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
    }
}

// Build query based on filters
$where_clauses = [];
$params = [];
$param_types = "";

// Status filter
if (isset($_GET['status']) && in_array($_GET['status'], ['active', 'inactive', 'pending'])) {
    $where_clauses[] = "status = ?";
    $params[] = $_GET['status'];
    $param_types .= "s";
}

// Membership filter
if (isset($_GET['membership']) && is_numeric($_GET['membership'])) {
    $where_clauses[] = "membership_id = ?";
    $params[] = $_GET['membership'];
    $param_types .= "i";
}

// Expiring filter
if (isset($_GET['filter']) && $_GET['filter'] === 'expiring') {
    $today = date('Y-m-d');
    $next_month = date('Y-m-d', strtotime('+30 days'));
    $where_clauses[] = "membership_end_date BETWEEN ? AND ?";
    $params[] = $today;
    $params[] = $next_month;
    $param_types .= "ss";
}

// Expired filter
if (isset($_GET['filter']) && $_GET['filter'] === 'expired') {
    $today = date('Y-m-d');
    $where_clauses[] = "membership_end_date < ?";
    $params[] = $today;
    $param_types .= "s";
}

// New members filter
if (isset($_GET['filter']) && $_GET['filter'] === 'new') {
    $first_day_of_month = date('Y-m-01');
    $where_clauses[] = "created_at >= ?";
    $params[] = $first_day_of_month;
    $param_types .= "s";
}

// Search filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = '%' . $_GET['search'] . '%';
    $where_clauses[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR member_id LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "sssss";
}

// Construct the WHERE clause
$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Get total records for pagination
try {
    if (!empty($params)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM gym_members $where_sql");
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $total_records = $result->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $result = $conn->query("SELECT COUNT(*) as total FROM gym_members");
        $total_records = $result->fetch_assoc()['total'];
    }
    
    // Calculate total pages
    $total_pages = ceil($total_records / $records_per_page);
    
    // Ensure current page is within valid range
    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
    
    // Get members with pagination
    $sql = "SELECT m.*, mp.name as membership_name, 
            DATEDIFF(m.membership_end_date, CURDATE()) as days_remaining
            FROM gym_members m
            LEFT JOIN membership_plans mp ON m.membership_id = mp.id
            $where_sql
            ORDER BY m.created_at DESC
            LIMIT ?, ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    // Add pagination parameters
    $params[] = $offset;
    $params[] = $records_per_page;
    $param_types .= "ii";
    
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $members = $stmt->get_result();
    $stmt->close();
    
    // Get membership plans for filter dropdown
    $membership_plans = $conn->query("SELECT id, name FROM membership_plans WHERE status = 'active' ORDER BY name");
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - Gym Management System</title>
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
                    <h1 class="h2">Manage Members</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="add-member.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-person-plus me-1"></i> Add New Member
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Filters</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (isset($_GET['status']) && $_GET['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="membership" class="form-label">Membership</label>
                                <select class="form-select" id="membership" name="membership">
                                    <option value="">All Memberships</option>
                                    <?php if (isset($membership_plans) && $membership_plans->num_rows > 0): ?>
                                        <?php while ($plan = $membership_plans->fetch_assoc()): ?>
                                            <option value="<?php echo $plan['id']; ?>" <?php echo (isset($_GET['membership']) && $_GET['membership'] == $plan['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($plan['name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="filter" class="form-label">Special Filters</label>
                                <select class="form-select" id="filter" name="filter">
                                    <option value="">No Filter</option>
                                    <option value="expiring" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'expiring') ? 'selected' : ''; ?>>Expiring Soon</option>
                                    <option value="expired" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'expired') ? 'selected' : ''; ?>>Expired</option>
                                    <option value="new" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'new') ? 'selected' : ''; ?>>New This Month</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Name, Email, Phone..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="members.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Members List -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Members List</h5>
                        <span class="badge bg-primary"><?php echo $total_records; ?> members found</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Contact</th>
                                        <th>Membership</th>
                                        <th>Expiry Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($members->num_rows > 0): ?>
                                        <?php while ($member = $members->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($member['member_id']); ?></td>
                                                <td>
                                                    <a href="view-member.php?id=<?php echo $member['id']; ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($member['email']); ?></div>
                                                    <div><?php echo htmlspecialchars($member['phone']); ?></div>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['membership_name'] ?? 'None'); ?></td>
                                                <td>
                                                    <?php if ($member['membership_end_date']): ?>
                                                        <?php echo date('M d, Y', strtotime($member['membership_end_date'])); ?>
                                                        <?php if ($member['days_remaining'] !== null): ?>
                                                            <?php if ($member['days_remaining'] < 0): ?>
                                                                <span class="badge bg-danger">Expired</span>
                                                            <?php elseif ($member['days_remaining'] <= 7): ?>
                                                                <span class="badge bg-warning"><?php echo $member['days_remaining']; ?> days left</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success"><?php echo $member['days_remaining']; ?> days left</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
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
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="view-member.php?id=<?php echo $member['id']; ?>" class="btn btn-info" title="View">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="edit-member.php?id=<?php echo $member['id']; ?>" class="btn btn-primary" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-danger" title="Delete" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $member['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    
                                                    <!-- Status Update Dropdown -->
                                                    <div class="dropdown d-inline-block">
                                                        <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="statusDropdown<?php echo $member['id']; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                                            Status
                                                        </button>
                                                        <ul class="dropdown-menu" aria-labelledby="statusDropdown<?php echo $member['id']; ?>">
                                                            <li>
                                                                <form method="POST" action="">
                                                                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                                    <input type="hidden" name="status" value="active">
                                                                    <button type="submit" name="update_status" class="dropdown-item">Set Active</button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="POST" action="">
                                                                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                                    <input type="hidden" name="status" value="inactive">
                                                                    <button type="submit" name="update_status" class="dropdown-item">Set Inactive</button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="POST" action="">
                                                                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                                    <input type="hidden" name="status" value="pending">
                                                                    <button type="submit" name="update_status" class="dropdown-item">Set Pending</button>
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                    
                                                    <!-- Delete Confirmation Modal -->
                                                    <div class="modal fade" id="deleteModal<?php echo $member['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $member['id']; ?>" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="deleteModalLabel<?php echo $member['id']; ?>">Confirm Delete</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    Are you sure you want to delete <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>?
                                                                    <p class="text-danger mt-2">This action cannot be undone.</p>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <form method="POST" action="">
                                                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                                        <button type="submit" name="delete_member" class="btn btn-danger">Delete</button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center">No members found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['membership']) ? '&membership=' . $_GET['membership'] : ''; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>">Previous</a>
                                    </li>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['membership']) ? '&membership=' . $_GET['membership'] : ''; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?><?php echo isset($_GET['membership']) ? '&membership=' . $_GET['membership'] : ''; ?><?php echo isset($_GET['filter']) ? '&filter=' . $_GET['filter'] : ''; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>

