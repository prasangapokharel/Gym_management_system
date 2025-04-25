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
$report_type = isset($_GET['report']) ? $_GET['report'] : 'sales';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // First day of current month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'); // Today
$category = isset($_GET['category']) ? $_GET['category'] : '';
$report_data = [];
$chart_data = [];

// Check if cafe tables exist
$table_exists = $conn->query("SHOW TABLES LIKE 'cafe_orders'");
if ($table_exists->num_rows == 0) {
    // If table doesn't exist, redirect to products page to create tables
    header("Location: cafe-products.php");
    exit;
}

// Generate report based on type
try {
    switch ($report_type) {
        case 'sales':
            // Sales report - total sales by date
            $sql = "SELECT DATE(o.created_at) as sale_date, 
                   COUNT(o.id) as order_count, 
                   SUM(o.total_amount) as total_sales
                   FROM cafe_orders o
                   WHERE o.status = 'completed'
                   AND DATE(o.created_at) BETWEEN ? AND ?
                   GROUP BY DATE(o.created_at)
                   ORDER BY sale_date";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $report_data = $stmt->get_result();
            
            // Prepare chart data
            $dates = [];
            $sales = [];
            
            if ($report_data->num_rows > 0) {
                $report_data->data_seek(0);
                while ($row = $report_data->fetch_assoc()) {
                    $dates[] = date('M d', strtotime($row['sale_date']));
                    $sales[] = $row['total_sales'];
                }
            }
            
            $chart_data = [
                'labels' => $dates,
                'datasets' => [
                    [
                        'label' => 'Sales (Rs)',
                        'data' => $sales,
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'borderColor' => 'rgba(54, 162, 235, 1)',
                        'borderWidth' => 1
                    ]
                ]
            ];
            break;
            
        case 'products':
            // Product sales report
            $sql = "SELECT p.name, p.category, 
                   SUM(oi.quantity) as total_quantity, 
                   SUM(oi.subtotal) as total_sales,
                   AVG(oi.unit_price) as avg_price
                   FROM cafe_order_items oi
                   JOIN cafe_products p ON oi.product_id = p.id
                   JOIN cafe_orders o ON oi.order_id = o.id
                   WHERE o.status = 'completed'
                   AND DATE(o.created_at) BETWEEN ? AND ?";
            
            if (!empty($category)) {
                $sql .= " AND p.category = ?";
            }
            
            $sql .= " GROUP BY p.id
                     ORDER BY total_sales DESC";
            
            $stmt = $conn->prepare($sql);
            
            if (!empty($category)) {
                $stmt->bind_param("sss", $date_from, $date_to, $category);
            } else {
                $stmt->bind_param("ss", $date_from, $date_to);
            }
            
            $stmt->execute();
            $report_data = $stmt->get_result();
            
            // Prepare chart data
            $products = [];
            $quantities = [];
            
            if ($report_data->num_rows > 0) {
                $report_data->data_seek(0);
                $count = 0;
                while (($row = $report_data->fetch_assoc()) && $count < 10) {
                    $products[] = $row['name'];
                    $quantities[] = $row['total_quantity'];
                    $count++;
                }
            }
            
            $chart_data = [
                'labels' => $products,
                'datasets' => [
                    [
                        'label' => 'Quantity Sold',
                        'data' => $quantities,
                        'backgroundColor' => [
                            'rgba(255, 99, 132, 0.2)',
                            'rgba(54, 162, 235, 0.2)',
                            'rgba(255, 206, 86, 0.2)',
                            'rgba(75, 192, 192, 0.2)',
                            'rgba(153, 102, 255, 0.2)',
                            'rgba(255, 159, 64, 0.2)',
                            'rgba(199, 199, 199, 0.2)',
                            'rgba(83, 102, 255, 0.2)',
                            'rgba(40, 159, 64, 0.2)',
                            'rgba(210, 199, 199, 0.2)'
                        ],
                        'borderColor' => [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(199, 199, 199, 1)',
                            'rgba(83, 102, 255, 1)',
                            'rgba(40, 159, 64, 1)',
                            'rgba(210, 199, 199, 1)'
                        ],
                        'borderWidth' => 1
                    ]
                ]
            ];
            break;
            
        case 'categories':
            // Category sales report
            $sql = "SELECT p.category, 
                   COUNT(DISTINCT oi.order_id) as order_count,
                   SUM(oi.quantity) as total_quantity, 
                   SUM(oi.subtotal) as total_sales
                   FROM cafe_order_items oi
                   JOIN cafe_products p ON oi.product_id = p.id
                   JOIN cafe_orders o ON oi.order_id = o.id
                   WHERE o.status = 'completed'
                   AND DATE(o.created_at) BETWEEN ? AND ?
                   GROUP BY p.category
                   ORDER BY total_sales DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $report_data = $stmt->get_result();
            
            // Prepare chart data
            $categories = [];
            $sales = [];
            
            if ($report_data->num_rows > 0) {
                $report_data->data_seek(0);
                while ($row = $report_data->fetch_assoc()) {
                    $categories[] = ucfirst($row['category']);
                    $sales[] = $row['total_sales'];
                }
            }
            
            $chart_data = [
                'labels' => $categories,
                'datasets' => [
                    [
                        'label' => 'Sales by Category (Rs)',
                        'data' => $sales,
                        'backgroundColor' => [
                            'rgba(255, 99, 132, 0.2)',
                            'rgba(54, 162, 235, 0.2)',
                            'rgba(255, 206, 86, 0.2)',
                            'rgba(75, 192, 192, 0.2)'
                        ],
                        'borderColor' => [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)'
                        ],
                        'borderWidth' => 1
                    ]
                ]
            ];
            break;
            
        case 'profit':
            // Profit report
            $sql = "SELECT DATE(o.created_at) as sale_date,
                   SUM(oi.subtotal) as total_sales,
                   SUM(oi.quantity * p.cost_price) as total_cost,
                   SUM(oi.subtotal) - SUM(oi.quantity * p.cost_price) as profit
                   FROM cafe_orders o
                   JOIN cafe_order_items oi ON o.id = oi.order_id
                   JOIN cafe_products p ON oi.product_id = p.id
                   WHERE o.status = 'completed'
                   AND DATE(o.created_at) BETWEEN ? AND ?
                   GROUP BY DATE(o.created_at)
                   ORDER BY sale_date";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $report_data = $stmt->get_result();
            
            // Prepare chart data
            $dates = [];
            $profits = [];
            
            if ($report_data->num_rows > 0) {
                $report_data->data_seek(0);
                while ($row = $report_data->fetch_assoc()) {
                    $dates[] = date('M d', strtotime($row['sale_date']));
                    $profits[] = $row['profit'];
                }
            }
            
            $chart_data = [
                'labels' => $dates,
                'datasets' => [
                    [
                        'label' => 'Profit (Rs)',
                        'data' => $profits,
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'borderColor' => 'rgba(75, 192, 192, 1)',
                        'borderWidth' => 1
                    ]
                ]
            ];
            break;
            
        case 'members':
            // Member purchases report
            $sql = "SELECT m.id, m.member_id as member_number, CONCAT(m.first_name, ' ', m.last_name) as member_name,
                   COUNT(o.id) as order_count,
                   SUM(o.total_amount) as total_spent
                   FROM cafe_orders o
                   JOIN gym_members m ON o.member_id = m.id
                   WHERE o.status = 'completed'
                   AND DATE(o.created_at) BETWEEN ? AND ?
                   GROUP BY m.id
                   ORDER BY total_spent DESC
                   LIMIT 10";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $report_data = $stmt->get_result();
            
            // Prepare chart data
            $members = [];
            $spent = [];
            
            if ($report_data->num_rows > 0) {
                $report_data->data_seek(0);
                while ($row = $report_data->fetch_assoc()) {
                    $members[] = $row['member_name'];
                    $spent[] = $row['total_spent'];
                }
            }
            
            $chart_data = [
                'labels' => $members,
                'datasets' => [
                    [
                        'label' => 'Amount Spent (Rs)',
                        'data' => $spent,
                        'backgroundColor' => 'rgba(153, 102, 255, 0.2)',
                        'borderColor' => 'rgba(153, 102, 255, 1)',
                        'borderWidth' => 1
                    ]
                ]
            ];
            break;
    }
} catch (Exception $e) {
    $error = "Error generating report: " . $e->getMessage();
}

// Get product categories for filter
$categories = [];
try {
    $result = $conn->query("SELECT DISTINCT category FROM cafe_products ORDER BY category");
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
} catch (Exception $e) {
    // Ignore error
}

// Calculate summary statistics
$summary = [
    'total_sales' => 0,
    'total_orders' => 0,
    'avg_order_value' => 0,
    'total_profit' => 0
];

try {
    // Total sales and orders
    $sql = "SELECT COUNT(id) as total_orders, SUM(total_amount) as total_sales
            FROM cafe_orders
            WHERE status = 'completed'
            AND DATE(created_at) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $summary['total_sales'] = $row['total_sales'] ?? 0;
        $summary['total_orders'] = $row['total_orders'] ?? 0;
        $summary['avg_order_value'] = $summary['total_orders'] > 0 ? 
            $summary['total_sales'] / $summary['total_orders'] : 0;
    }
    
    // Total profit
    $sql = "SELECT SUM(oi.subtotal) - SUM(oi.quantity * p.cost_price) as total_profit
            FROM cafe_orders o
            JOIN cafe_order_items oi ON o.id = oi.order_id
            JOIN cafe_products p ON oi.product_id = p.id
            WHERE o.status = 'completed'
            AND DATE(o.created_at) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $summary['total_profit'] = $row['total_profit'] ?? 0;
    }
} catch (Exception $e) {
    // Ignore error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cafe Reports - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h5 class="text-white">Gym Management</h5>
                        <p class="text-white-50">Admin Panel</p>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="members.php">
                                <i class="bi bi-people me-2"></i>
                                Members
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="memberships.php">
                                <i class="bi bi-card-checklist me-2"></i>
                                Memberships
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="payments.php">
                                <i class="bi bi-cash-stack me-2"></i>
                                Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="cafe-products.php">
                                <i class="bi bi-cup-hot me-2"></i>
                                Cafe Products
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="cafe-orders.php">
                                <i class="bi bi-cart-check me-2"></i>
                                Cafe Orders
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="cafe-reports.php">
                                <i class="bi bi-graph-up me-2"></i>
                                Cafe Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profile.php">
                                <i class="bi bi-person me-2"></i>
                                Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>
                                Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Cafe Reports</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print Report
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Report Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Report Filters</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="report" class="form-label">Report Type</label>
                                <select class="form-select" id="report" name="report">
                                    <option value="sales" <?php echo $report_type === 'sales' ? 'selected' : ''; ?>>Sales Report</option>
                                    <option value="products" <?php echo $report_type === 'products' ? 'selected' : ''; ?>>Product Sales</option>
                                    <option value="categories" <?php echo $report_type === 'categories' ? 'selected' : ''; ?>>Category Sales</option>
                                    <option value="profit" <?php echo $report_type === 'profit' ? 'selected' : ''; ?>>Profit Report</option>
                                    <option value="members" <?php echo $report_type === 'members' ? 'selected' : ''; ?>>Member Purchases</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="date_from" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="date_to" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                            </div>
                            <div class="col-md-3 category-filter" <?php echo $report_type !== 'products' ? 'style="display: none;"' : ''; ?>>
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($cat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                                <a href="cafe-reports.php" class="btn btn-secondary">Reset Filters</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-4">
                        <div class="card bg-primary text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Sales</h6>
                                        <h2 class="display-6">Rs <?php echo number_format($summary['total_sales'], 2); ?></h2>
                                    </div>
                                    <i class="bi bi-cash-stack display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Orders</h6>
                                        <h2 class="display-6"><?php echo $summary['total_orders']; ?></h2>
                                    </div>
                                    <i class="bi bi-cart-check display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="card bg-info text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Avg Order Value</h6>
                                        <h2 class="display-6">Rs <?php echo number_format($summary['avg_order_value'], 2); ?></h2>
                                    </div>
                                    <i class="bi bi-calculator display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="card bg-warning text-white h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-uppercase">Total Profit</h6>
                                        <h2 class="display-6">Rs <?php echo number_format($summary['total_profit'], 2); ?></h2>
                                    </div>
                                    <i class="bi bi-graph-up-arrow display-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Chart -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <?php
                            switch ($report_type) {
                                case 'sales':
                                    echo 'Sales Report';
                                    break;
                                case 'products':
                                    echo 'Product Sales';
                                    break;
                                case 'categories':
                                    echo 'Category Sales';
                                    break;
                                case 'profit':
                                    echo 'Profit Report';
                                    break;
                                case 'members':
                                    echo 'Top Member Purchases';
                                    break;
                            }
                            ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="position: relative; height:400px; width:100%">
                            <canvas id="reportChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Report Data Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Report Data</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <?php if ($report_type === 'sales'): ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Orders</th>
                                            <th class="text-end">Total Sales</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($report_data) && $report_data->num_rows > 0): ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()) {
                                                $dates[] = date('M d', strtotime($row['sale_date']));
                                                $sales[] = $row['total_sales'];
                                            } ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($row['sale_date'])); ?></td>
                                                    <td><?php echo $row['order_count']; ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['total_sales'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No data found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            <?php elseif ($report_type === 'products'): ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th class="text-end">Quantity Sold</th>
                                            <th class="text-end">Avg Price</th>
                                            <th class="text-end">Total Sales</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($report_data) && $report_data->num_rows > 0): ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while (($row = $report_data->fetch_assoc()) && $count < 10) {
                                                $products[] = $row['name'];
                                                $quantities[] = $row['total_quantity'];
                                                $count++;
                                            } ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                    <td><?php echo ucfirst($row['category']); ?></td>
                                                    <td class="text-end"><?php echo $row['total_quantity']; ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['avg_price'], 2); ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['total_sales'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No data found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            <?php elseif ($report_type === 'categories'): ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Orders</th>
                                            <th class="text-end">Quantity Sold</th>
                                            <th class="text-end">Total Sales</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($report_data) && $report_data->num_rows > 0): ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()) {
                                                $categories[] = ucfirst($row['category']);
                                                $sales[] = $row['total_sales'];
                                            } ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo ucfirst($row['category']); ?></td>
                                                    <td><?php echo $row['order_count']; ?></td>
                                                    <td class="text-end"><?php echo $row['total_quantity']; ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['total_sales'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center">No data found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            <?php elseif ($report_type === 'profit'): ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Total Sales</th>
                                            <th class="text-end">Total Cost</th>
                                            <th class="text-end">Profit</th>
                                            <th class="text-end">Profit Margin</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($report_data) && $report_data->num_rows > 0): ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()) {
                                                $dates[] = date('M d', strtotime($row['sale_date']));
                                                $profits[] = $row['profit'];
                                            } ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($row['sale_date'])); ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['total_sales'], 2); ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['total_cost'], 2); ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['profit'], 2); ?></td>
                                                    <td class="text-end">
                                                        <?php 
                                                        $margin = $row['total_sales'] > 0 ? ($row['profit'] / $row['total_sales']) * 100 : 0;
                                                        echo number_format($margin, 2) . '%';
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center">No data found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            <?php elseif ($report_type === 'members'): ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Member ID</th>
                                            <th class="text-end">Orders</th>
                                            <th class="text-end">Total Spent</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (is_object($report_data) && $report_data->num_rows > 0): ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()) {
                                                $members[] = $row['member_name'];
                                                $spent[] = $row['total_spent'];
                                            } ?>
                                            <?php $report_data->data_seek(0); ?>
                                            <?php while ($row = $report_data->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['member_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['member_number']); ?></td>
                                                    <td class="text-end"><?php echo $row['order_count']; ?></td>
                                                    <td class="text-end">Rs <?php echo number_format($row['total_spent'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center">No data found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show/hide category filter based on report type
            const reportSelect = document.getElementById('report');
            const categoryFilter = document.querySelector('.category-filter');
            
            reportSelect.addEventListener('change', function() {
                if (this.value === 'products') {
                    categoryFilter.style.display = 'block';
                } else {
                    categoryFilter.style.display = 'none';
                }
            });
            
            // Initialize chart
            const ctx = document.getElementById('reportChart').getContext('2d');
            const chartData = <?php echo json_encode($chart_data); ?>;
            const chartType = '<?php echo $report_type === 'products' || $report_type === 'categories' || $report_type === 'members' ? 'bar' : 'line'; ?>';
            
            const chart = new Chart(ctx, {
                type: chartType,
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'Rs ' + value;
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: false,
                            text: 'Chart Title'
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
