<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: ../index.php");
    exit;
}

// Handle order submission
$orderMessage = '';
$orderSuccess = false;
$order_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    $member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : null;
    $products = isset($_POST['products']) ? $_POST['products'] : [];
    $quantities = isset($_POST['quantities']) ? $_POST['quantities'] : [];
    $payment_method = isset($_POST['payment_method']) ? sanitizeInput($_POST['payment_method']) : 'cash';
    $total_amount = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : 0;
    
    if (!empty($products) && !empty($quantities) && count($products) === count($quantities)) {
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            // Generate order number
            $order_number = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
            
            // Create new order
            $stmt = $conn->prepare("INSERT INTO cafe_orders (order_number, member_id, total_amount, payment_method, status, created_by, created_at) 
                                   VALUES (?, ?, ?, ?, 'completed', ?, NOW())");
            $stmt->bind_param("sidsi", $order_number, $member_id, $total_amount, $payment_method, $_SESSION['admin_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create order: " . $stmt->error);
            }
            
            $order_id = $conn->insert_id;
            
            // Add order items
            $stmt = $conn->prepare("INSERT INTO cafe_order_items (order_id, product_id, quantity, unit_price, subtotal) 
                                   VALUES (?, ?, ?, ?, ?)");
            
            for ($i = 0; $i < count($products); $i++) {
                $product_id = (int)$products[$i];
                $quantity = (int)$quantities[$i];
                
                // Get product price and check stock
                $price_stmt = $conn->prepare("SELECT price, stock_quantity, name FROM cafe_products WHERE id = ?");
                $price_stmt->bind_param("i", $product_id);
                $price_stmt->execute();
                $product_result = $price_stmt->get_result();
                
                if ($product_result->num_rows === 0) {
                    throw new Exception("Product not found");
                }
                
                $product_data = $product_result->fetch_assoc();
                $price = $product_data['price'];
                $current_stock = $product_data['stock_quantity'];
                $product_name = $product_data['name'];
                
                if ($current_stock < $quantity) {
                    throw new Exception("Not enough stock for {$product_name}. Available: {$current_stock}");
                }
                
                $subtotal = $price * $quantity;
                
                $stmt->bind_param("iiidi", $order_id, $product_id, $quantity, $price, $subtotal);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to add order item: " . $stmt->error);
                }
                
                // Update product inventory
                $update_stmt = $conn->prepare("UPDATE cafe_products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $update_stmt->bind_param("ii", $quantity, $product_id);
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update inventory: " . $update_stmt->error);
                }
            }
            
            // Log activity
            logActivity($conn, $_SESSION['admin_id'], "Created cafe order #{$order_number}");
            
            // Commit transaction
            $conn->commit();
            
            $orderSuccess = true;
            $orderMessage = "Order #{$order_number} has been successfully created!";
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $orderMessage = "Error: " . $e->getMessage();
        }
    } else {
        $orderMessage = "Please add at least one product to the order.";
    }
}

// Get all products
try {
    $products_query = "SELECT * FROM cafe_products WHERE stock_quantity > 0 ORDER BY category, name";
    $products_result = $conn->query($products_query);
    
    if (!$products_result) {
        throw new Exception("Error loading products: " . $conn->error);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Get all members for search
try {
    $members_query = "SELECT id, member_id, CONCAT(first_name, ' ', last_name) AS name, email, phone FROM gym_members ORDER BY name";
    $members_result = $conn->query($members_query);
    
    if (!$members_result) {
        throw new Exception("Error loading members: " . $conn->error);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Get product categories for filtering
try {
    $categories_query = "SELECT DISTINCT category FROM cafe_products ORDER BY category";
    $categories_result = $conn->query($categories_query);
    
    if (!$categories_result) {
        throw new Exception("Error loading categories: " . $conn->error);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Get recent orders
try {
    $recent_orders_query = "SELECT o.id, o.order_number, o.created_at, o.total_amount, o.status,
                          CONCAT(m.first_name, ' ', m.last_name) AS member_name 
                          FROM cafe_orders o 
                          LEFT JOIN gym_members m ON o.member_id = m.id 
                          ORDER BY o.created_at DESC LIMIT 10";
    $recent_orders_result = $conn->query($recent_orders_query);
    
    if (!$recent_orders_result) {
        throw new Exception("Error loading recent orders: " . $conn->error);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Get popular products for quick add
try {
    $popular_products_query = "SELECT p.id, p.name, COUNT(oi.id) as order_count 
                              FROM cafe_products p 
                              JOIN cafe_order_items oi ON p.id = oi.product_id 
                              GROUP BY p.id 
                              ORDER BY order_count DESC 
                              LIMIT 5";
    $popular_products_result = $conn->query($popular_products_query);
    
    if (!$popular_products_result) {
        $popular_products_result = null;
    }
} catch (Exception $e) {
    $popular_products_result = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cafe POS System - Gym Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
         .product-card {
            cursor: pointer;
            transition: all 0.2s ease;
            height: 100%;
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .product-card.out-of-stock {
            opacity: 0.6;
            pointer-events: none;
        }
        .cart-item {
            animation: fadeIn 0.5s;
        }
        .category-pills {
            overflow-x: auto;
            white-space: nowrap;
            padding: 10px 0;
            scrollbar-width: thin;
        }
        .category-pills::-webkit-scrollbar {
            height: 5px;
        }
        .category-pills::-webkit-scrollbar-thumb {
            background-color: rgba(0,0,0,0.2);
            border-radius: 10px;
        }
        .category-pills .nav-link {
            margin-right: 5px;
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .product-image {
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }
        .product-image i {
            font-size: 3rem;
        }
        .cart-container {
            max-height: calc(100vh - 350px);
            overflow-y: auto;
        }
        .member-search-results {
            position: absolute;
            width: 100%;
            z-index: 1000;
            max-height: 200px;
            overflow-y: auto;
        }
        .search-result-item {
            cursor: pointer;
        }
        .search-result-item:hover {
            background-color: #f8f9fa;
        }
        .order-success-animation {
            animation: successPulse 1.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes successPulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
            70% { box-shadow: 0 0 0 20px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        .receipt-container {
            font-family: 'Courier New', monospace;
            width: 300px;
            padding: 20px;
            border: 1px solid #ddd;
            background-color: white;
        }
        .receipt-header, .receipt-footer {
            text-align: center;
            margin-bottom: 10px;
        }
        .receipt-body {
            margin: 15px 0;
        }
        .receipt-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .receipt-divider {
            border-top: 1px dashed #ddd;
            margin: 10px 0;
        }
        .receipt-total {
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }
        #orderSummary {
            position: sticky;
            top: 20px;
        }
        .quick-templates {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 10px;
        }
        .template-btn {
            padding: 8px 15px;
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            cursor: pointer;
            white-space: nowrap;
        }
        .template-btn:hover {
            background-color: #dee2e6;
        }
        .popular-products {
            margin-bottom: 20px;
        }
        .popular-products h5 {
            margin-bottom: 10px;
        }
        .popular-product-btn {
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px;
            padding: 5px 10px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            font-size: 14px;
            cursor: pointer;
        }
        .popular-product-btn:hover {
            background-color: #e9ecef;
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
        }
        .tab.active {
            border-bottom-color: #2c7be5;
            font-weight: bold;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .member-info {
            background-color: #e9ecef;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
        }
        .member-info.show {
            display: block;
        }
        .member-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .member-info-name {
            font-weight: bold;
            font-size: 16px;
        }
        .member-info-id {
            color: #6c757d;
        }
        .member-info-contact {
            font-size: 14px;
            color: #6c757d;
        }
        .pos-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }
        
        .products-section {
            flex: 2;
            min-width: 500px;
        }
        
        .cart-section {
            flex: 1;
            min-width: 350px;
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .product-card {
            background-color: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
            position: relative;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .product-card img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 5px;
            margin: 0 auto 10px;
        }
        
        .product-name {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .product-price {
            color: #2c7be5;
            font-weight: bold;
        }
        
        .product-stock {
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: #e9ecef;
            border-radius: 3px;
            padding: 2px 5px;
            font-size: 12px;
        }
        
        .cart-items {
            margin-bottom: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .cart-item-info {
            flex: 1;
        }
        
        .cart-item-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .cart-item-price {
            color: #6c757d;
            font-size: 14px;
        }
        
        .cart-item-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .quantity-btn {
            background-color: #e9ecef;
            border: none;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-weight: bold;
        }
        
        .quantity-btn:hover {
            background-color: #dee2e6;
        }
        
        .quantity-input {
            width: 40px;
            text-align: center;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 2px;
        }
        
        .remove-item {
            color: #dc3545;
            cursor: pointer;
        }
        
        .cart-summary {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px dashed #dee2e6;
        }
        
        .cart-total {
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .checkout-btn {
            width: 100%;
            padding: 12px;
            background-color: #2c7be5;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .checkout-btn:hover {
            background-color: #1a68d1;
        }
        
        .checkout-btn:disabled {
            background-color: #a9c6f2;
            cursor: not-allowed;
        }
        
        .category-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .category-btn {
            padding: 8px 15px;
            background-color: #e9ecef;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .category-btn:hover, .category-btn.active {
            background-color: #2c7be5;
            color: white;
        }
        
        .search-bar {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        .member-selection {
            margin-bottom: 20px;
        }
        
        .order-success {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .order-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .recent-orders {
            margin-top: 30px;
        }
        
        .recent-orders h3 {
            margin-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
        }
        
        .recent-order-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .recent-order-id {
            font-weight: bold;
        }
        
        .recent-order-details {
            color: #6c757d;
        }
        
        .recent-order-amount {
            font-weight: bold;
            color: #2c7be5;
        }
        
        .view-order-btn {
            color: #2c7be5;
            text-decoration: none;
            margin-left: 10px;
        }
        
        .view-order-btn:hover {
            text-decoration: underline;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
        }
        
        .tab.active {
            border-bottom-color: #2c7be5;
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @media (max-width: 768px) {
            .pos-container {
                flex-direction: column;
            }
            
            .products-section, .cart-section {
                min-width: 100%;
            }
            
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
        
        /* Quick order templates */
        .quick-templates {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 10px;
        }
        
        .template-btn {
            padding: 8px 15px;
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 5px;
            cursor: pointer;
            white-space: nowrap;
        }
        
        .template-btn:hover {
            background-color: #dee2e6;
        }
        
        /* Member search */
        .member-search-container {
            position: relative;
            margin-bottom: 20px;
        }
        
        .member-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid #ced4da;
            border-radius: 0 0 5px 5px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
            display: none;
        }
        
        .member-search-results.show {
            display: block;
        }
        
        .member-search-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        
        .member-search-item:hover {
            background-color: #f8f9fa;
        }
        
        /* Scanner */
        .scanner-container {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .scanner-btn {
            padding: 10px 15px;
            background-color: #2c7be5;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        #scanner-preview {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
            display: none;
        }
        
        /* Payment methods */
        .payment-methods {
            margin-bottom: 20px;
        }
        
        .payment-method-label {
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .payment-method-input {
            display: none;
        }
        
        .payment-method-input + span {
            padding: 8px 15px;
            border-radius: 5px;
            background-color: #e9ecef;
            cursor: pointer;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .payment-method-input:checked + span {
            background-color: #2c7be5;
            color: white;
        }
        
        /* Member info */
        .member-info {
            background-color: #e9ecef;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
        }
        
        .member-info.show {
            display: block;
        }
        
        .member-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .member-info-name {
            font-weight: bold;
            font-size: 16px;
        }
        
        .member-info-id {
            color: #6c757d;
        }
        
        .member-info-contact {
            font-size: 14px;
            color: #6c757d;
        }
        
        /* Popular products */
        .popular-products {
            margin-bottom: 20px;
        }
        
        .popular-products h5 {
            margin-bottom: 10px;
        }
        
        .popular-product-btn {
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px;
            padding: 5px 10px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .popular-product-btn:hover {
            background-color: #e9ecef;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="bi bi-cart-check me-2"></i> Cafe POS System</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="cafe-products.php" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-cup-hot me-1"></i> Manage Products
                </a>
                <a href="cafe-orders.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-list-check me-1"></i> Order History
                </a>
            </div>
        </div>
        
        <?php if (!empty($orderMessage)): ?>
            <div class="alert <?php echo $orderSuccess ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show">
                <?php echo $orderMessage; ?>
                <?php if ($orderSuccess && $order_id): ?>
                    <a href="view-cafe-order.php?id=<?php echo $order_id; ?>" class="alert-link ms-2">View Order Details</a>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="tabs">
            <div class="tab active" data-tab="pos">POS System</div>
            <div class="tab" data-tab="orders">Recent Orders</div>
        </div>
        
        <div class="tab-content active" id="pos-tab">
            <div class="pos-container">
                <div class="products-section">
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="product-search" class="form-control" placeholder="Search products...">
                    </div>
                    
                    <div class="category-filter">
                        <button class="category-btn active" data-category="all">All</button>
                        <?php if ($categories_result && $categories_result->num_rows > 0): ?>
                            <?php while ($category = $categories_result->fetch_assoc()): ?>
                                <button class="category-btn" data-category="<?php echo htmlspecialchars($category['category']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($category['category'])); ?>
                                </button>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($popular_products_result && $popular_products_result->num_rows > 0): ?>
                        <div class="popular-products">
                            <h5>Popular Products</h5>
                            <?php while ($product = $popular_products_result->fetch_assoc()): ?>
                                <button class="popular-product-btn" data-id="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </button>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="quick-templates">
                        <button class="template-btn" data-template="breakfast">Breakfast Combo</button>
                        <button class="template-btn" data-template="protein">Protein Pack</button>
                        <button class="template-btn" data-template="popular">Most Popular</button>
                    </div>
                    
                    <div class="product-grid">
                        <?php if ($products_result && $products_result->num_rows > 0): ?>
                            <?php while ($product = $products_result->fetch_assoc()): ?>
                                <div class="product-card" 
                                     data-id="<?php echo $product['id']; ?>" 
                                     data-name="<?php echo htmlspecialchars($product['name']); ?>" 
                                     data-price="<?php echo $product['price']; ?>" 
                                     data-category="<?php echo htmlspecialchars($product['category']); ?>"
                                     data-stock="<?php echo $product['stock_quantity']; ?>">
                                    <span class="product-stock"><?php echo $product['stock_quantity']; ?> left</span>
                                    <img src="<?php echo !empty($product['image']) ? '../' . $product['image'] : '../assets/images/product-placeholder.png'; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="product-price">Rs <?php echo number_format($product['price'], 2); ?></div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="alert alert-info w-100">
                                <i class="bi bi-info-circle me-2"></i>
                                No products available. <a href="cafe-products.php" class="alert-link">Add products</a> to start selling.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="cart-section">
                    <h2>Current Order</h2>
                    
                    <div class="member-search-container">
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-person-search"></i></span>
                            <input type="text" id="member-search" class="form-control" placeholder="Search member by name or ID...">
                            <button class="btn btn-outline-secondary" type="button" id="scan-member-card">
                                <i class="bi bi-qr-code-scan"></i>
                            </button>
                        </div>
                        <div class="member-search-results" id="member-search-results"></div>
                    </div>
                    
                    <div id="scanner-container" style="display: none;">
                        <div class="alert alert-info">
                            <i class="bi bi-camera me-2"></i>
                            Position the QR code or barcode in front of the camera
                        </div>
                        <video id="scanner-preview" style="width: 100%; max-height: 200px;"></video>
                        <button id="cancel-scan" class="btn btn-sm btn-outline-danger mt-2">
                            <i class="bi bi-x-circle me-1"></i> Cancel Scan
                        </button>
                    </div>
                    
                    <div id="member-info" class="member-info">
                        <div class="member-info-header">
                            <div class="member-info-name" id="member-name"></div>
                            <div class="member-info-id" id="member-id"></div>
                        </div>
                        <div class="member-info-contact" id="member-contact"></div>
                    </div>
                    
                    <form id="order-form" method="POST">
                        <input type="hidden" name="member_id" id="member-id-input" value="">
                        
                        <div class="cart-items" id="cart-items">
                            <div class="empty-cart-message">No items in cart</div>
                        </div>
                        
                        <div class="payment-methods">
                            <h5>Payment Method</h5>
                            <label class="payment-method-label">
                                <input type="radio" name="payment_method" value="cash" class="payment-method-input" checked>
                                <span><i class="bi bi-cash me-1"></i> Cash</span>
                            </label>
                            <label class="payment-method-label">
                                <input type="radio" name="payment_method" value="credit_card" class="payment-method-input">
                                <span><i class="bi bi-credit-card me-1"></i> Credit Card</span>
                            </label>
                            <label class="payment-method-label">
                                <input type="radio" name="payment_method" value="debit_card" class="payment-method-input">
                                <span><i class="bi bi-credit-card-2-front me-1"></i> Debit Card</span>
                            </label>
                            <label class="payment-method-label">
                                <input type="radio" name="payment_method" value="mobile_payment" class="payment-method-input">
                                <span><i class="bi bi-phone me-1"></i> Mobile Payment</span>
                            </label>
                        </div>
                        
                        <div class="cart-summary">
                            <div class="cart-total">
                                <span>Total:</span>
                                <span id="cart-total-amount">Rs 0.00</span>
                            </div>
                            
                            <input type="hidden" name="total_amount" id="total-amount-input" value="0">
                            <button type="submit" name="submit_order" class="checkout-btn" disabled>
                                <i class="bi bi-check-circle me-1"></i> Complete Order
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="orders-tab">
            <div class="recent-orders">
                <h3>Recent Orders</h3>
                
                <?php if ($recent_orders_result && $recent_orders_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date & Time</th>
                                    <th>Member</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($order = $recent_orders_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></td>
                                        <td><?php echo $order['member_name'] ? htmlspecialchars($order['member_name']) : 'Guest'; ?></td>
                                        <td>Rs <?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge <?php echo $order['status'] === 'completed' ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view-cafe-order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        No recent orders found.
                    </div>
                <?php endif; ?>
                
                <div class="mt-4">
                    <a href="cafe-orders.php" class="btn btn-primary">
                        <i class="bi bi-list-check me-1"></i> View All Orders
                    </a>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/@zxing/library@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const productCards = document.querySelectorAll('.product-card');
            const cartItems = document.getElementById('cart-items');
            const cartTotalAmount = document.getElementById('cart-total-amount');
            const totalAmountInput = document.getElementById('total-amount-input');
            const checkoutBtn = document.querySelector('.checkout-btn');
            const productSearch = document.getElementById('product-search');
            const categoryBtns = document.querySelectorAll('.category-btn');
            const memberSearch = document.getElementById('member-search');
            const memberSearchResults = document.getElementById('member-search-results');
            const memberIdInput = document.getElementById('member-id-input');
            const memberInfo = document.getElementById('member-info');
            const memberNameEl = document.getElementById('member-name');
            const memberIdEl = document.getElementById('member-id');
            const memberContactEl = document.getElementById('member-contact');
            const scannerBtn = document.getElementById('scan-member-card');
            const scannerContainer = document.getElementById('scanner-container');
            const scannerPreview = document.getElementById('scanner-preview');
            const cancelScanBtn = document.getElementById('cancel-scan');
            const templateBtns = document.querySelectorAll('.template-btn');
            const popularProductBtns = document.querySelectorAll('.popular-product-btn');
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            // State
            let cart = [];
            let codeReader = null;
            let members = [];
            
            // Load members data
            <?php if ($members_result && $members_result->num_rows > 0): ?>
                members = [
                    <?php while ($member = $members_result->fetch_assoc()): ?>
                        {
                            id: <?php echo $member['id']; ?>,
                            memberId: "<?php echo htmlspecialchars($member['member_id']); ?>",
                            name: "<?php echo htmlspecialchars($member['name']); ?>",
                            email: "<?php echo htmlspecialchars($member['email']); ?>",
                            phone: "<?php echo htmlspecialchars($member['phone']); ?>"
                        },
                    <?php endwhile; ?>
                ];
            <?php endif; ?>
            
            // Product click event
            productCards.forEach(card => {
                card.addEventListener('click', function() {
                    const id = parseInt(this.dataset.id);
                    const name = this.dataset.name;
                    const price = parseFloat(this.dataset.price);
                    const stock = parseInt(this.dataset.stock);
                    
                    // Check if product is already in cart
                    const existingItem = cart.find(item => item.id === id);
                    
                    if (existingItem) {
                        if (existingItem.quantity < stock) {
                            existingItem.quantity++;
                            updateCart();
                        } else {
                            alert(`Sorry, only ${stock} items available in stock.`);
                        }
                    } else {
                        cart.push({
                            id: id,
                            name: name,
                            price: price,
                            quantity: 1,
                            maxStock: stock
                        });
                        updateCart();
                    }
                });
            });
            
            // Popular product buttons
            popularProductBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const productId = parseInt(this.dataset.id);
                    const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
                    
                    if (productCard) {
                        productCard.click();
                    }
                });
            });
            
            // Update cart display
            function updateCart() {
                if (cart.length === 0) {
                    cartItems.innerHTML = '<div class="empty-cart-message">No items in cart</div>';
                    checkoutBtn.disabled = true;
                } else {
                    let cartHTML = '';
                    let total = 0;
                    
                    cart.forEach((item, index) => {
                        const itemTotal = item.price * item.quantity;
                        total += itemTotal;
                        
                        cartHTML += `
                            <div class="cart-item">
                                <div class="cart-item-info">
                                    <div class="cart-item-name">${item.name}</div>
                                    <div class="cart-item-price">Rs ${item.price.toFixed(2)} each</div>
                                </div>
                                <div class="cart-item-actions">
                                    <div class="quantity-control">
                                        <button type="button" class="quantity-btn decrease-btn" data-index="${index}">-</button>
                                        <input type="number" class="quantity-input" value="${item.quantity}" min="1" max="${item.maxStock}" data-index="${index}">
                                        <button type="button" class="quantity-btn increase-btn" data-index="${index}">+</button>
                                    </div>
                                    <div class="remove-item" data-index="${index}">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                                <input type="hidden" name="products[]" value="${item.id}">
                                <input type="hidden" name="quantities[]" value="${item.quantity}">
                            </div>
                        `;
                    });
                    
                    cartItems.innerHTML = cartHTML;
                    cartTotalAmount.textContent = 'Rs ' + total.toFixed(2);
                    totalAmountInput.value = total;
                    checkoutBtn.disabled = false;
                    
                    // Add event listeners to cart item controls
                    document.querySelectorAll('.decrease-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const index = parseInt(this.dataset.index);
                            if (cart[index].quantity > 1) {
                                cart[index].quantity--;
                                updateCart();
                            }
                        });
                    });
                    
                    document.querySelectorAll('.increase-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const index = parseInt(this.dataset.index);
                            if (cart[index].quantity < cart[index].maxStock) {
                                cart[index].quantity++;
                                updateCart();
                            } else {
                                alert(`Sorry, only ${cart[index].maxStock} items available in stock.`);
                            }
                        });
                    });
                    
                    document.querySelectorAll('.quantity-input').forEach(input => {
                        input.addEventListener('change', function() {
                            const index = parseInt(this.dataset.index);
                            const value = parseInt(this.value);
                            const maxStock = cart[index].maxStock;
                            
                            if (value > 0 && value <= maxStock) {
                                cart[index].quantity = value;
                            } else if (value > maxStock) {
                                this.value = maxStock;
                                cart[index].quantity = maxStock;
                                alert(`Sorry, only ${maxStock} items available in stock.`);
                            } else {
                                this.value = 1;
                                cart[index].quantity = 1;
                            }
                            
                            updateCart();
                        });
                    });
                    
                    document.querySelectorAll('.remove-item').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const index = parseInt(this.dataset.index);
                            cart.splice(index, 1);
                            updateCart();
                        });
                    });
                }
            }
            
            // Product search
            productSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                productCards.forEach(card => {
                    const name = card.dataset.name.toLowerCase();
                    const category = card.dataset.category.toLowerCase();
                    const isVisible = name.includes(searchTerm) || category.includes(searchTerm);
                    
                    card.style.display = isVisible ? 'block' : 'none';
                });
            });
            
            // Category filter
            categoryBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const category = this.dataset.category;
                    
                    // Update active button
                    categoryBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Filter products
                    productCards.forEach(card => {
                        if (category === 'all') {
                            card.style.display = 'block';
                        } else {
                            card.style.display = card.dataset.category === category ? 'block' : 'none';
                        }
                    });
                });
            });
            
            // Member search
            memberSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                if (searchTerm.length < 2) {
                    memberSearchResults.classList.remove('show');
                    return;
                }
                
                const filteredMembers = members.filter(member => 
                    member.name.toLowerCase().includes(searchTerm) || 
                    member.memberId.toLowerCase().includes(searchTerm)
                );
                
                if (filteredMembers.length > 0) {
                    let resultsHTML = '';
                    
                    filteredMembers.forEach(member => {
                        resultsHTML += `
                            <div class="member-search-item" data-id="${member.id}" data-member-id="${member.memberId}" data-name="${member.name}" data-email="${member.email}" data-phone="${member.phone}">
                                <strong>${member.name}</strong> (${member.memberId})
                            </div>
                        `;
                    });
                    
                    memberSearchResults.innerHTML = resultsHTML;
                    memberSearchResults.classList.add('show');
                    
                    // Add click event to search results
                    document.querySelectorAll('.member-search-item').forEach(item => {
                        item.addEventListener('click', function() {
                            selectMember({
                                id: this.dataset.id,
                                memberId: this.dataset.memberId,
                                name: this.dataset.name,
                                email: this.dataset.email,
                                phone: this.dataset.phone
                            });
                        });
                    });
                } else {
                    memberSearchResults.innerHTML = '<div class="member-search-item">No members found</div>';
                    memberSearchResults.classList.add('show');
                }
            });
            
            // Select member function
            function selectMember(member) {
                memberIdInput.value = member.id;
                memberSearch.value = member.name;
                memberSearchResults.classList.remove('show');
                
                // Show member info
                memberNameEl.textContent = member.name;
                memberIdEl.textContent = member.memberId;
                memberContactEl.innerHTML = `
                    <div><i class="bi bi-envelope me-1"></i> ${member.email}</div>
                    <div><i class="bi bi-telephone me-1"></i> ${member.phone}</div>
                `;
                memberInfo.classList.add('show');
            }
            
            // Hide search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!memberSearch.contains(e.target) && !memberSearchResults.contains(e.target)) {
                    memberSearchResults.classList.remove('show');
                }
            });
            
            // QR/Barcode scanner
            scannerBtn.addEventListener('click', function() {
                scannerContainer.style.display = 'block';
                
                codeReader = new ZXing.BrowserMultiFormatReader();
                codeReader.decodeFromVideoDevice(null, 'scanner-preview', (result, err) => {
                    if (result) {
                        // Assuming the QR code contains the member ID
                        const scannedId = result.text;
                        
                        // Find the member by ID
                        const member = members.find(m => m.memberId === scannedId);
                        
                        if (member) {
                            selectMember(member);
                            
                            // Stop scanning
                            codeReader.reset();
                            scannerContainer.style.display = 'none';
                        } else {
                            alert('Member not found with ID: ' + scannedId);
                        }
                    }
                });
            });
            
            // Cancel scan button
            cancelScanBtn.addEventListener('click', function() {
                if (codeReader) {
                    codeReader.reset();
                }
                scannerContainer.style.display = 'none';
            });
            
            // Quick order templates
            templateBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const template = this.dataset.template;
                    
                    // Clear current cart
                    cart = [];
                    
                    // Add template items
                    switch (template) {
                        case 'breakfast':
                            addTemplateItemsByCategory('beverage', 1);
                            addTemplateItemsByCategory('food', 2);
                            break;
                        case 'protein':
                            addTemplateItemsByCategory('supplement', 2);
                            addTemplateItemsByCategory('beverage', 1);
                            break;
                        case 'popular':
                            // Add 3 random products
                            const allProducts = Array.from(productCards);
                            for (let i = 0; i < 3 && i < allProducts.length; i++) {
                                const randomIndex = Math.floor(Math.random() * allProducts.length);
                                const product = allProducts[randomIndex];
                                
                                cart.push({
                                    id: parseInt(product.dataset.id),
                                    name: product.dataset.name,
                                    price: parseFloat(product.dataset.price),
                                    quantity: 1,
                                    maxStock: parseInt(product.dataset.stock)
                                });
                                
                                // Remove from array to avoid duplicates
                                allProducts.splice(randomIndex, 1);
                            }
                            break;
                    }
                    
                    updateCart();
                });
            });
            
            // Add template items helper function
            function addTemplateItemsByCategory(category, count) {
                const categoryProducts = Array.from(productCards).filter(card => 
                    card.dataset.category.toLowerCase() === category.toLowerCase()
                );
                
                for (let i = 0; i < count && i < categoryProducts.length; i++) {
                    const randomIndex = Math.floor(Math.random() * categoryProducts.length);
                    const product = categoryProducts[randomIndex];
                    
                    cart.push({
                        id: parseInt(product.dataset.id),
                        name: product.dataset.name,
                        price: parseFloat(product.dataset.price),
                        quantity: 1,
                        maxStock: parseInt(product.dataset.stock)
                    });
                    
                    // Remove from array to avoid duplicates
                    categoryProducts.splice(randomIndex, 1);
                }
            }
            
            // Tab switching
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    
                    // Update active tab
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show active content
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === tabId + '-tab') {
                            content.classList.add('active');
                        }
                    });
                });
            });
            
            // Initialize cart
            updateCart();
        });
    </script>
</body>
</html>
