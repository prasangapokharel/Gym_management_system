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
$orders = [];

// Check if cafe tables exist
$table_exists = $conn->query("SHOW TABLES LIKE 'cafe_orders'");
if ($table_exists->num_rows == 0) {
    // If table doesn't exist, redirect to products page to create tables
    header("Location: cafe-products.php");
    exit;
}

// Handle order actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_order'])) {
        $order_id = (int)$_POST['order_id'];
        
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            // Get order details
            $stmt = $conn->prepare("SELECT order_number, status FROM cafe_orders WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Order not found.");
            }
            
            $order = $result->fetch_assoc();
            
            if ($order['status'] === 'cancelled') {
                throw new Exception("Order is already cancelled.");
            }
            
            // Get order items
            $stmt = $conn->prepare("SELECT product_id, quantity FROM cafe_order_items WHERE order_id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $items = $stmt->get_result();
            
            // Restore stock for each item
            while ($item = $items->fetch_assoc()) {
                $stmt = $conn->prepare("UPDATE cafe_products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                $stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt->execute();
            }
            
            // Update order status
            $stmt = $conn->prepare("UPDATE cafe_orders SET status = 'cancelled' WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to cancel order: " . $stmt->error);
            }
            
            // Commit transaction
            $conn->commit();
            
            $success = "Order cancelled successfully";
            logActivity($conn, $_SESSION['admin_id'], "Cancelled cafe order #{$order['order_number']}");
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = "System error: " . $e->getMessage();
        }
    }
}

// Get all orders with pagination and search
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// Search parameters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

try {
    // Build query with search conditions
    $query = "FROM cafe_orders o
              JOIN gym_members m ON o.member_id = m.id
              WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $search_param = "%$search%";
        $query .= " AND (o.order_number LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR m.member_id LIKE ?)";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    if (!empty($date_from)) {
        $query .= " AND DATE(o.created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    
    if (!empty($date_to)) {
        $query .= " AND DATE(o.created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
    
    if (!empty($status)) {
        $query .= " AND o.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Count total records
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total " . $query);
    if (!empty($types)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $records_per_page);
    
    // Get orders with pagination
    $query .= " ORDER BY o.created_at DESC LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $records_per_page;
    $types .= "ii";
    
    $stmt = $conn->prepare("SELECT o.*, CONCAT(m.first_name, ' ', m.last_name) as member_name, m.member_id as member_number " . $query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $orders = $stmt->get_result();
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Get order statistics
try {
    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    
    // Today's orders
    $stmt = $conn->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM cafe_orders WHERE DATE(created_at) = ? AND status = 'completed'");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $today_stats = $stmt->get_result()->fetch_assoc();
    
    // This month's orders
    $stmt = $conn->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM cafe_orders WHERE DATE(created_at) >= ? AND status = 'completed'");
    $stmt->bind_param("s", $month_start);
    $stmt->execute();
    $month_stats = $stmt->get_result()->fetch_assoc();
    
    // Total orders
    $stmt = $conn->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM cafe_orders WHERE status = 'completed'");
    $stmt->execute();
    $total_stats = $stmt->get_result()->fetch_assoc();
} catch (Exception $e) {
    // Ignore error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cafe Orders - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .order-card {
            transition: all 0.2s ease;
        }
        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .search-container {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
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
                    <h1 class="h2">Cafe Order History</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="cafe-pos.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle me-1"></i> New Order
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Order Statistics -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card bg-primary text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Today's Orders</h6>
                                        <h2 class="display-6"><?php echo isset($today_stats['count']) ? $today_stats['count'] : 0; ?></h2>
                                        <p class="mb-0">Rs <?php echo isset($today_stats['total']) ? number_format($today_stats['total'], 2) : '0.00'; ?></p>
                                    </div>
                                    <i class="bi bi-calendar-day display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">This Month</h6>
                                        <h2 class="display-6"><?php echo isset($month_stats['count']) ? $month_stats['count'] : 0; ?></h2>
                                        <p class="mb-0">Rs <?php echo isset($month_stats['total']) ? number_format($month_stats['total'], 2) : '0.00'; ?></p>
                                    </div>
                                    <i class="bi bi-calendar-month display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-info text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Orders</h6>
                                        <h2 class="display-6"><?php echo isset($total_stats['count']) ? $total_stats['count'] : 0; ?></h2>
                                        <p class="mb-0">Rs <?php echo isset($total_stats['total']) ? number_format($total_stats['total'], 2) : '0.00'; ?></p>
                                    </div>
                                    <i class="bi bi-cart-check display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Search and Filter -->
                <div class="search-container">
                    <form action="" method="GET" class="row g-3">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" name="search" placeholder="Search by order #, member..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="date_from" placeholder="From Date" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="date_to" placeholder="To Date" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>
                
                <!-- Orders List -->
                <div class="row">
                    <?php if (is_object($orders) && $orders->num_rows > 0): ?>
                        <?php while ($order = $orders->fetch_assoc()): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card order-card h-100 position-relative">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0"><?php echo htmlspecialchars($order['order_number']); ?></h5>
                                        <span class="badge <?php echo $order['status'] === 'completed' ? 'bg-success' : 'bg-danger'; ?> status-badge">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <i class="bi bi-person-circle me-2"></i>
                                            <strong><?php echo htmlspecialchars($order['member_name']); ?></strong>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($order['member_number']); ?></small>
                                        </div>
                                        <div class="mb-3">
                                            <i class="bi bi-calendar me-2"></i>
                                            <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                                            <small class="text-muted d-block"><?php echo date('h:i A', strtotime($order['created_at'])); ?></small>
                                        </div>
                                        <div class="mb-3">
                                            <i class="bi bi-credit-card me-2"></i>
                                            <?php echo ucwords(str_replace('_', ' ', $order['payment_method'])); ?>
                                        </div>
                                        <div class="mb-0">
                                            <i class="bi bi-cash me-2"></i>
                                            <strong class="text-primary">Rs <?php echo number_format($order['total_amount'], 2); ?></strong>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-transparent d-flex justify-content-between">
                                        <a href="view-cafe-order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i> View Details
                                        </a>
                                        <?php if ($order['status'] === 'completed'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelOrderModal<?php echo $order['id']; ?>">
                                                <i class="bi bi-x-circle me-1"></i> Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Cancel Order Modal -->
                                <?php if ($order['status'] === 'completed'): ?>
                                    <div class="modal fade" id="cancelOrderModal<?php echo $order['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Confirm Cancel Order</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Are you sure you want to cancel order <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>?</p>
                                                    <div class="alert alert-warning">
                                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                                        This will return all items to inventory and mark the order as cancelled.
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                        <button type="submit" name="cancel_order" class="btn btn-danger">Cancel Order</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No orders found. <?php echo !empty($search) || !empty($date_from) || !empty($date_to) || !empty($status) ? 'Try adjusting your search filters.' : ''; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status=<?php echo urlencode($status); ?>">Previous</a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status=<?php echo urlencode($status); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status=<?php echo urlencode($status); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>
