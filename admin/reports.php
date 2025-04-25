<?php
// admin/reports.php

// Include necessary files and start the session
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

include('../config.php');

// Function to fetch revenue data
function getRevenueData($conn, $start_date, $end_date) {
    $sql = "SELECT DATE(order_date) AS date, SUM(total_amount) AS revenue FROM orders WHERE order_date BETWEEN '$start_date' AND '$end_date' GROUP BY DATE(order_date) ORDER BY DATE(order_date)";
    $result = $conn->query($sql);
    $data = array();
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
}

// Function to fetch total revenue
function getTotalRevenue($conn, $start_date, $end_date) {
    $sql = "SELECT SUM(total_amount) AS total_revenue FROM orders WHERE order_date BETWEEN '$start_date' AND '$end_date'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['total_revenue'];
    }
    return 0;
}

// Set default date range to last 7 days
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-7 days'));

// Handle date range selection
if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];
}

// Fetch revenue data
$revenue_data = getRevenueData($conn, $start_date, $end_date);

// Calculate total revenue
$total_revenue = getTotalRevenue($conn, $start_date, $end_date);

// Prepare data for chart
$labels = array();
$revenue = array();
foreach ($revenue_data as $row) {
    $labels[] = $row['date'];
    $revenue[] = $row['revenue'];
}

$labels_json = json_encode($labels);
$revenue_json = json_encode($revenue);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container mt-5">
        <h2>Admin Reports</h2>
        <a href="dashboard.php" class="btn btn-secondary mb-3">Back to Dashboard</a>

        <!-- Date Range Selection -->
        <form method="GET" class="mb-3">
            <div class="form-row">
                <div class="col">
                    <label for="start_date">Start Date:</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="col">
                    <label for="end_date">End Date:</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-2">Apply Date Range</button>
        </form>

        <!-- Revenue Report Table -->
        <h3>Revenue Report</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($revenue_data as $row): ?>
                    <tr>
                        <td><?php echo $row['date']; ?></td>
                        <td>Rs <?php echo number_format($row['revenue'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Revenue Chart -->
        <h3>Revenue Chart</h3>
        <div style="width: 80%; margin: auto;">
            <canvas id="revenueChart"></canvas>
        </div>

        <!-- Summary Statistics -->
        <div class="card mt-3">
            <div class="card-body">
                <h5 class="card-title">Total Revenue (<?php echo $start_date; ?> - <?php echo $end_date; ?>)</h5>
                <h3 class="card-title">Rs <?php echo number_format($total_revenue, 2); ?></h3>
            </div>
        </div>
    </div>

    <script>
        // Chart.js script
        var ctx = document.getElementById('revenueChart').getContext('2d');
        var myChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo $labels_json; ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo $revenue_json; ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return 'Rs ' + value;
                            }
                        }
                    }
                }
            }
        });
    </script>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.3/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
