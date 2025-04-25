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
$order = null;
$order_items = [];
$member = null;

// Check if order ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: cafe-orders.php");
    exit;
}

$order_id = (int)$_GET['id'];

// Get order details
try {
    $stmt = $conn->prepare("SELECT o.*, CONCAT(m.first_name, ' ', m.last_name) as member_name, m.member_id as member_number, m.email, m.phone
                           FROM cafe_orders o
                           JOIN gym_members m ON o.member_id = m.id
                           WHERE o.id = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: cafe-orders.php");
        exit;
    }
    
    $order = $result->fetch_assoc();
    $stmt->close();
    
    // Get order items
    $stmt = $conn->prepare("SELECT oi.*, p.name as product_name, p.category
                           FROM cafe_order_items oi
                           JOIN cafe_products p ON oi.product_id = p.id
                           WHERE oi.order_id = ?
                           ORDER BY p.category, p.name");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order_items = $stmt->get_result();
    $stmt->close();
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Handle order actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_order'])) {
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            // Check if order is already cancelled
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
            
            // Refresh order data
            $order['status'] = 'cancelled';
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
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
    <title>View Order - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            body {
                padding: 0;
                margin: 0;
            }
            .container {
                width: 100%;
                max-width: 100%;
            }
        }
        .print-only {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container-fluid no-print">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom no-print">
                    <h1 class="h2">Order Details</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="cafe-orders.php" class="btn btn-sm btn-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i> Back to Orders
                        </a>
                        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print Receipt
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger no-print"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success no-print"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($order): ?>
                    <!-- Print Header - Only visible when printing -->
                    <div class="text-center mb-4 print-only">
                        <h2>Gym Management System</h2>
                        <h4>Cafe Receipt</h4>
                        <hr>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Order #<?php echo htmlspecialchars($order['order_number']); ?></h5>
                            <span class="badge <?php echo $order['status'] === 'completed' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Order Information</h6>
                                    <p class="mb-1"><strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></p>
                                    <p class="mb-1"><strong>Payment Method:</strong> <?php echo ucwords(str_replace('_', ' ', $order['payment_method'])); ?></p>
                                    <p class="mb-1"><strong>Total Amount:</strong> $<?php echo number_format($order['total_amount'], 2); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <h6>Member Information</h6>
                                    <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['member_name']); ?></p>
                                    <p class="mb-1"><strong>Member ID:</strong> <?php echo htmlspecialchars($order['member_number']); ?></p>
                                    <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>
                                    <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($order['phone']); ?></p>
                                </div>
                            </div>
                            
                            <h6 class="mt-4">Order Items</h6>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($order_items) && $order_items->num_rows > 0): ?>
                                            <?php while ($item = $order_items->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                    <td>
                                                        <span class="badge <?php 
                                                            echo $item['category'] === 'food' ? 'bg-success' : 
                                                                ($item['category'] === 'beverage' ? 'bg-info' : 
                                                                ($item['category'] === 'supplement' ? 'bg-warning' : 'bg-secondary')); 
                                                        ?>">
                                                            <?php echo ucfirst($item['category']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end">$<?php echo number_format($item['unit_price'], 2); ?></td>
                                                    <td class="text-center"><?php echo $item['quantity']; ?></td>
                                                    <td class="text-end">$<?php echo number_format($item['subtotal'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No items found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="4" class="text-end">Total:</th>
                                            <th class="text-end">$<?php echo number_format($order['total_amount'], 2); ?></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <?php if ($order['status'] === 'completed'): ?>
                                <div class="mt-3 no-print">
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">
                                        <i class="bi bi-x-circle me-1"></i> Cancel Order
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Print Footer - Only visible when printing -->
                    <div class="print-only mt-4">
                        <hr>
                        <p class="text-center">Thank you for your purchase!</p>
                        <p class="text-center small">Printed on: <?php echo date('Y-m-d H:i:s'); ?></p>
                    </div>
                    
                    <!-- Cancel Order Modal -->
                    <?php if ($order['status'] === 'completed'): ?>
                        <div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="cancelOrderModalLabel">Confirm Cancel Order</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        Are you sure you want to cancel order #<?php echo htmlspecialchars($order['order_number']); ?>?
                                        <p class="text-danger mt-2">This will return all items to inventory.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <form method="POST" action="">
                                            <button type="submit" name="cancel_order" class="btn btn-danger">Cancel Order</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>
