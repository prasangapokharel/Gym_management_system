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

// Initialize variables with default values
$total_members = 0;
$active_members = 0;
$new_members_this_month = 0;
$expiring_memberships = [];
$recent_payments = [];
$total_earnings = 0;
$monthly_earnings = 0;
$yearly_earnings = 0;

// Check if the required tables and columns exist
$tables_exist = true;
$required_tables = ['gym_members', 'membership_plans', 'payments', 'notifications'];

foreach ($required_tables as $table) {
  $check = $conn->query("SHOW TABLES LIKE '$table'");
  if ($check->num_rows == 0) {
      $tables_exist = false;
      break;
  }
}

// Check if membership_end_date column exists in gym_members table
$column_exists = false;
if ($tables_exist) {
  $check = $conn->query("SHOW COLUMNS FROM gym_members LIKE 'membership_end_date'");
  $column_exists = ($check->num_rows > 0);
}

// Get gym statistics
try {
  // Get total members count
  $result = $conn->query("SELECT COUNT(*) as total FROM gym_members");
  if ($result) {
      $total_members = $result->fetch_assoc()['total'];
  }
  
  // Get active members count
  $result = $conn->query("SELECT COUNT(*) as active FROM gym_members WHERE status = 'active'");
  if ($result) {
      $active_members = $result->fetch_assoc()['active'];
  }
  
  // Get new members this month
  $first_day_of_month = date('Y-m-01');
  $stmt = $conn->prepare("SELECT COUNT(*) as new_members FROM gym_members WHERE created_at >= ?");
  if ($stmt) {
      $stmt->bind_param("s", $first_day_of_month);
      $stmt->execute();
      $result = $stmt->get_result();
      $new_members_this_month = $result->fetch_assoc()['new_members'];
      $stmt->close();
  }
  
  // Get total earnings
  $result = $conn->query("SELECT SUM(amount) as total FROM payments");
  if ($result) {
      $row = $result->fetch_assoc();
      $total_earnings = $row['total'] ? $row['total'] : 0;
  }
  
  // Get monthly earnings
  $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE payment_date >= ?");
  if ($stmt) {
      $stmt->bind_param("s", $first_day_of_month);
      $stmt->execute();
      $result = $stmt->get_result();
      $row = $result->fetch_assoc();
      $monthly_earnings = $row['total'] ? $row['total'] : 0;
      $stmt->close();
  }
  
  // Get yearly earnings
  $first_day_of_year = date('Y-01-01');
  $stmt = $conn->prepare("SELECT SUM(amount) as total FROM payments WHERE payment_date >= ?");
  if ($stmt) {
      $stmt->bind_param("s", $first_day_of_year);
      $stmt->execute();
      $result = $stmt->get_result();
      $row = $result->fetch_assoc();
      $yearly_earnings = $row['total'] ? $row['total'] : 0;
      $stmt->close();
  }
  
  // Get memberships expiring in the next 7 days (only if the column exists)
  if ($column_exists) {
      $today = date('Y-m-d');
      $next_week = date('Y-m-d', strtotime('+7 days'));
      
      // Check if first_name and last_name columns exist
      $check_name_columns = $conn->query("SHOW COLUMNS FROM gym_members LIKE 'first_name'");
      $name_columns_exist = ($check_name_columns->num_rows > 0);
      
      if ($name_columns_exist) {
          $stmt = $conn->prepare("SELECT m.id, m.member_id, CONCAT(m.first_name, ' ', m.last_name) as name, 
                                 m.membership_end_date, mp.name as membership_name,
                                 DATEDIFF(m.membership_end_date, CURDATE()) as days_remaining
                                 FROM gym_members m
                                 JOIN membership_plans mp ON m.membership_id = mp.id
                                 WHERE m.status = 'active' 
                                 AND m.membership_end_date BETWEEN ? AND ?
                                 ORDER BY m.membership_end_date ASC
                                 LIMIT 5");
      } else {
          $stmt = $conn->prepare("SELECT m.id, m.id as member_id, m.name, 
                                 m.membership_end_date, mp.name as membership_name,
                                 DATEDIFF(m.membership_end_date, CURDATE()) as days_remaining
                                 FROM gym_members m
                                 JOIN membership_plans mp ON m.membership_id = mp.id
                                 WHERE m.status = 'active' 
                                 AND m.membership_end_date BETWEEN ? AND ?
                                 ORDER BY m.membership_end_date ASC
                                 LIMIT 5");
      }
      
      if ($stmt) {
          $stmt->bind_param("ss", $today, $next_week);
          $stmt->execute();
          $expiring_memberships = $stmt->get_result();
          $stmt->close();
      }
  }
  
  // Get recent payments (only if the table exists)
  if ($conn->query("SHOW TABLES LIKE 'payments'")->num_rows > 0) {
      // Check if first_name and last_name columns exist
      $check_name_columns = $conn->query("SHOW COLUMNS FROM gym_members LIKE 'first_name'");
      $name_columns_exist = ($check_name_columns->num_rows > 0);
      
      if ($name_columns_exist) {
          $stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_date, p.payment_method,
                                 CONCAT(m.first_name, ' ', m.last_name) as member_name
                                 FROM payments p
                                 JOIN gym_members m ON p.member_id = m.id
                                 ORDER BY p.payment_date DESC
                                 LIMIT 5");
      } else {
          $stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_date, p.payment_method,
                                 m.name as member_name
                                 FROM payments p
                                 JOIN gym_members m ON p.member_id = m.id
                                 ORDER BY p.payment_date DESC
                                 LIMIT 5");
      }
      
      if ($stmt) {
          $stmt->execute();
          $recent_payments = $stmt->get_result();
          $stmt->close();
      }
  }
  
  // Get recent activity logs
  $activity_logs = [];
  $result = $conn->query("SHOW TABLES LIKE 'activity_logs'");
  if ($result->num_rows > 0) {
      $result = $conn->query("SELECT al.action, al.created_at, a.username 
                             FROM activity_logs al 
                             JOIN admins a ON al.admin_id = a.id 
                             ORDER BY al.created_at DESC LIMIT 10");
      if ($result) {
          $activity_logs = $result;
      }
  }
  
  // Create notifications for expired memberships (only if the tables exist)
  if ($column_exists && $conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0) {
      $conn->query("INSERT INTO notifications (type, member_id, message)
                   SELECT 'expired_membership', id, CONCAT('Membership has expired on ', membership_end_date)
                   FROM gym_members
                   WHERE status = 'active' AND membership_end_date < CURDATE()
                   AND id NOT IN (
                       SELECT member_id FROM notifications 
                       WHERE type = 'expired_membership' 
                       AND DATE(created_at) = CURDATE()
                   )");
      
      // Create notifications for memberships expiring in 7 days
      $conn->query("INSERT INTO notifications (type, member_id, message)
                   SELECT 'expiring_membership', id, CONCAT('Membership will expire on ', membership_end_date)
                   FROM gym_members
                   WHERE status = 'active' 
                   AND membership_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                   AND id NOT IN (
                       SELECT member_id FROM notifications 
                       WHERE type = 'expiring_membership' 
                       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   )");
  }
  
  // Get unread notifications count
  $unread_notifications = 0;
  if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0) {
      $result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0");
      $unread_notifications = $result ? $result->fetch_assoc()['count'] : 0;
  }
  
} catch (Exception $e) {
  error_log("Error in dashboard: " . $e->getMessage());
  // Continue with default values
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard - Gym Management System</title>
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
                  <h1 class="h2">Dashboard</h1>
                  <div class="btn-toolbar mb-2 mb-md-0">
                      <?php if ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0): ?>
                          <div class="position-relative me-3">
                              <a href="notifications.php" class="btn btn-sm btn-outline-secondary">
                                  <i class="bi bi-bell me-1"></i> Notifications
                                  <?php if ($unread_notifications > 0): ?>
                                      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                          <?php echo $unread_notifications; ?>
                                          <span class="visually-hidden">unread notifications</span>
                                      </span>
                                  <?php endif; ?>
                              </a>
                          </div>
                      <?php endif; ?>
                      <div class="dropdown">
                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                              <i class="bi bi-person-circle me-1"></i>
                              <?php echo htmlspecialchars($_SESSION['username']); ?>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                              <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                              <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                          </ul>
                      </div>
                  </div>
              </div>
              
              <?php if (!$tables_exist || !$column_exists): ?>
                  <div class="alert alert-warning">
                      <h4 class="alert-heading">Database Update Required</h4>
                      <p>Your database schema needs to be updated to use all features of the Gym Management System.</p>
                      <hr>
                      <p class="mb-0">Please run the <code>database_update.sql</code> script to update your database schema.</p>
                  </div>
              <?php endif; ?>
              
              <!-- Dashboard content -->
              <div class="row">
                  <div class="col-md-3 mb-4">
                      <div class="card bg-primary text-white h-100">
                          <div class="card-body">
                              <div class="d-flex justify-content-between align-items-center">
                                  <div>
                                      <h6 class="text-uppercase">Total Members</h6>
                                      <h1 class="display-4"><?php echo $total_members; ?></h1>
                                  </div>
                                  <i class="bi bi-people-fill display-4"></i>
                              </div>
                          </div>
                          <div class="card-footer d-flex align-items-center justify-content-between">
                              <a href="members.php" class="text-white text-decoration-none">View Details</a>
                              <i class="bi bi-arrow-right text-white"></i>
                          </div>
                      </div>
                  </div>
                  
                  <div class="col-md-3 mb-4">
                      <div class="card bg-success text-white h-100">
                          <div class="card-body">
                              <div class="d-flex justify-content-between align-items-center">
                                  <div>
                                      <h6 class="text-uppercase">Active Members</h6>
                                      <h1 class="display-4"><?php echo $active_members; ?></h1>
                                  </div>
                                  <i class="bi bi-person-check-fill display-4"></i>
                              </div>
                          </div>
                          <div class="card-footer d-flex align-items-center justify-content-between">
                              <a href="members.php?status=active" class="text-white text-decoration-none">View Details</a>
                              <i class="bi bi-arrow-right text-white"></i>
                          </div>
                      </div>
                  </div>
                  
                  <div class="col-md-3 mb-4">
                      <div class="card bg-info text-white h-100">
                          <div class="card-body">
                              <div class="d-flex justify-content-between align-items-center">
                                  <div>
                                      <h6 class="text-uppercase">New Members This Month</h6>
                                      <h1 class="display-4"><?php echo $new_members_this_month; ?></h1>
                                  </div>
                                  <i class="bi bi-person-plus-fill display-4"></i>
                              </div>
                          </div>
                          <div class="card-footer d-flex align-items-center justify-content-between">
                              <a href="members.php?filter=new" class="text-white text-decoration-none">View Details</a>
                              <i class="bi bi-arrow-right text-white"></i>
                          </div>
                      </div>
                  </div>
                  
                  <div class="col-md-3 mb-4">
                      <div class="card bg-warning text-white h-100">
                          <div class="card-body">
                              <div class="d-flex justify-content-between align-items-center">
                                  <div>
                                      <h6 class="text-uppercase">Total Earnings</h6>
                                      <h1 class="display-4">Rs<?php echo number_format($total_earnings, 2); ?></h1>
                                  </div>
                                  <i class="bi bi-cash-coin display-4"></i>
                              </div>
                          </div>
                          <div class="card-footer d-flex align-items-center justify-content-between">
                              <a href="payments.php" class="text-white text-decoration-none">View Details</a>
                              <i class="bi bi-arrow-right text-white"></i>
                          </div>
                      </div>
                  </div>
              </div>
              
              <!-- Financial Overview -->
              <div class="row mb-4">
                  <div class="col-md-12">
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Financial Overview</h5>
                          </div>
                          <div class="card-body">
                              <div class="row">
                                  <div class="col-md-4">
                                      <div class="card bg-light mb-3">
                                          <div class="card-body text-center">
                                              <h5 class="card-title">Monthly Earnings</h5>
                                              <h3 class="text-success">Rs<?php echo number_format($monthly_earnings, 2); ?></h3>
                                              <p class="text-muted"><?php echo date('F Y'); ?></p>
                                          </div>
                                      </div>
                                  </div>
                                  <div class="col-md-4">
                                      <div class="card bg-light mb-3">
                                          <div class="card-body text-center">
                                              <h5 class="card-title">Yearly Earnings</h5>
                                              <h3 class="text-success">Rs<?php echo number_format($yearly_earnings, 2); ?></h3>
                                              <p class="text-muted"><?php echo date('Y'); ?></p>
                                          </div>
                                      </div>
                                  </div>
                                  <div class="col-md-4">
                                      <div class="card bg-light mb-3">
                                          <div class="card-body text-center">
                                              <h5 class="card-title">All-Time Earnings</h5>
                                              <h3 class="text-success">Rs<?php echo number_format($total_earnings, 2); ?></h3>
                                              <p class="text-muted">Total Revenue</p>
                                          </div>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
              
              <div class="row">
                  <div class="col-md-6">
                      <?php if ($column_exists): ?>
                          <div class="card mb-4">
                              <div class="card-header d-flex justify-content-between align-items-center">
                                  <h5 class="card-title mb-0">Expiring Memberships</h5>
                                  <a href="members.php?filter=expiring" class="btn btn-sm btn-outline-primary">View All</a>
                              </div>
                              <div class="card-body">
                                  <div class="table-responsive">
                                      <table class="table table-striped table-sm">
                                          <thead>
                                              <tr>
                                                  <th>Member ID</th>
                                                  <th>Name</th>
                                                  <th>Membership</th>
                                                  <th>Expiry Date</th>
                                                  <th>Days Left</th>
                                              </tr>
                                          </thead>
                                          <tbody>
                                              <?php if (is_object($expiring_memberships) && $expiring_memberships->num_rows > 0): ?>
                                                  <?php while ($member = $expiring_memberships->fetch_assoc()): ?>
                                                      <tr>
                                                          <td><?php echo htmlspecialchars($member['member_id']); ?></td>
                                                          <td>
                                                              <a href="view-member.php?id=<?php echo $member['id']; ?>" class="text-decoration-none">
                                                                  <?php echo htmlspecialchars($member['name']); ?>
                                                              </a>
                                                          </td>
                                                          <td><?php echo htmlspecialchars($member['membership_name']); ?></td>
                                                          <td><?php echo date('M d, Y', strtotime($member['membership_end_date'])); ?></td>
                                                          <td>
                                                              <span class="badge <?php echo $member['days_remaining'] <= 3 ? 'bg-danger' : 'bg-warning'; ?>">
                                                                  <?php echo $member['days_remaining']; ?> days
                                                              </span>
                                                          </td>
                                                      </tr>
                                                  <?php endwhile; ?>
                                              <?php else: ?>
                                                  <tr>
                                                      <td colspan="5" class="text-center">No expiring memberships found</td>
                                                  </tr>
                                              <?php endif; ?>
                                          </tbody>
                                      </table>
                                  </div>
                              </div>
                          </div>
                      <?php endif; ?>
                      
                      <div class="card mb-4">
                          <div class="card-header d-flex justify-content-between align-items-center">
                              <h5 class="card-title mb-0">Recent Activity</h5>
                          </div>
                          <div class="card-body">
                              <div class="table-responsive">
                                  <table class="table table-striped table-sm">
                                      <thead>
                                          <tr>
                                              <th>Admin</th>
                                              <th>Action</th>
                                              <th>Date & Time</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php if (is_object($activity_logs) && $activity_logs->num_rows > 0): ?>
                                              <?php while ($log = $activity_logs->fetch_assoc()): ?>
                                                  <tr>
                                                      <td><?php echo htmlspecialchars($log['username']); ?></td>
                                                      <td><?php echo htmlspecialchars($log['action']); ?></td>
                                                      <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                                                  </tr>
                                              <?php endwhile; ?>
                                          <?php else: ?>
                                              <tr>
                                                  <td colspan="3" class="text-center">No activity logs found</td>
                                              </tr>
                                          <?php endif; ?>
                                      </tbody>
                                  </table>
                              </div>
                          </div>
                      </div>
                  </div>
                  
                  <div class="col-md-6">
                      <?php if ($conn->query("SHOW TABLES LIKE 'payments'")->num_rows > 0): ?>
                          <div class="card mb-4">
                              <div class="card-header d-flex justify-content-between align-items-center">
                                  <h5 class="card-title mb-0">Recent Payments</h5>
                                  <a href="payments.php" class="btn btn-sm btn-outline-primary">View All</a>
                              </div>
                              <div class="card-body">
                                  <div class="table-responsive">
                                      <table class="table table-striped table-sm">
                                          <thead>
                                              <tr>
                                                  <th>Member</th>
                                                  <th>Amount</th>
                                                  <th>Date</th>
                                                  <th>Method</th>
                                                  <th>Actions</th>
                                              </tr>
                                          </thead>
                                          <tbody>
                                              <?php if (is_object($recent_payments) && $recent_payments->num_rows > 0): ?>
                                                  <?php while ($payment = $recent_payments->fetch_assoc()): ?>
                                                      <tr>
                                                          <td><?php echo htmlspecialchars($payment['member_name']); ?></td>
                                                          <td>Rs<?php echo number_format($payment['amount'], 2); ?></td>
                                                          <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                                          <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                                          <td>
                                                              <a href="../print-receipt.php?payment_id=<?php echo $payment['id']; ?>" class="btn btn-sm btn-success" target="_blank">
                                                                  <i class="bi bi-printer"></i> Print
                                                              </a>
                                                          </td>
                                                      </tr>
                                                  <?php endwhile; ?>
                                              <?php else: ?>
                                                  <tr>
                                                      <td colspan="5" class="text-center">No recent payments found</td>
                                                  </tr>
                                              <?php endif; ?>
                                          </tbody>
                                      </table>
                                  </div>
                              </div>
                          </div>
                      <?php endif; ?>
                      
                      <div class="card mb-4">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Quick Actions</h5>
                          </div>
                          <div class="card-body">
                              <div class="d-grid gap-2">
                                  <a href="add-member.php" class="btn btn-primary">
                                      <i class="bi bi-person-plus me-2"></i> Add New Member
                                  </a>
                                  <a href="add-payment.php" class="btn btn-success">
                                      <i class="bi bi-cash me-2"></i> Record Payment
                                  </a>
                                  <a href="reports.php" class="btn btn-info">
                                      <i class="bi bi-file-earmark-bar-graph me-2"></i> Generate Reports
                                  </a>
                                  <a href="memberships.php" class="btn btn-secondary">
                                      <i class="bi bi-card-checklist me-2"></i> Manage Membership Plans
                                  </a>
                              </div>
                          </div>
                      </div>
                      
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">System Info</h5>
                          </div>
                          <div class="card-body">
                              <ul class="list-group list-group-flush">
                                  <li class="list-group-item d-flex justify-content-between align-items-center">
                                      PHP Version
                                      <span class="badge bg-primary rounded-pill"><?php echo phpversion(); ?></span>
                                  </li>
                                  <li class="list-group-item d-flex justify-content-between align-items-center">
                                      MySQL Version
                                      <span class="badge bg-primary rounded-pill"><?php echo $conn->server_info; ?></span>
                                  </li>
                                  <li class="list-group-item d-flex justify-content-between align-items-center">
                                      Server Time
                                      <span class="badge bg-primary rounded-pill"><?php echo date('Y-m-d H:i:s'); ?></span>
                                  </li>
                              </ul>
                          </div>
                      </div>
                  </div>
              </div>
          </main>
      </div>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/js/admin.js"></script>
</body>
</html>

