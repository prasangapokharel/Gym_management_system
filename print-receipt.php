<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: index.php");
    exit;
}

// Check if payment ID is provided
if (!isset($_GET['payment_id']) || !is_numeric($_GET['payment_id'])) {
    header("Location: admin/dashboard.php");
    exit;
}

$payment_id = (int)$_GET['payment_id'];

// Get payment details
try {
    $stmt = $conn->prepare("SELECT p.*, 
                           CONCAT(m.first_name, ' ', m.last_name) as member_name,
                           m.member_id as member_code,
                           m.email as member_email,
                           m.phone as member_phone,
                           mp.name as plan_name,
                           mp.duration as plan_duration,
                           a.fullname as admin_name
                           FROM payments p
                           JOIN gym_members m ON p.member_id = m.id
                           LEFT JOIN membership_plans mp ON m.membership_id = mp.id
                           JOIN admins a ON p.created_by = a.id
                           WHERE p.id = ?");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header("Location: admin/dashboard.php");
        exit;
    }
    
    $payment = $result->fetch_assoc();
    $stmt->close();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo htmlspecialchars($payment['receipt_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #333;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .gym-name {
            font-size: 24px;
            font-weight: bold;
            color: #0d6efd;
            text-align: center;
            margin-bottom: 5px;
        }
        
        .gym-address {
            font-size: 12px;
            color: #666;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .receipt-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 10px;
        }
        
        .receipt-info table,
        .member-info table,
        .payment-info table {
            width: 100%;
            margin-bottom: 20px;
        }
        
        .receipt-info td,
        .member-info td,
        .payment-info td {
            padding: 5px;
        }
        
        .label {
            font-weight: bold;
            width: 40%;
        }
        
        .payment-details table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .payment-details th {
            background-color: #f2f2f2;
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .payment-details td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        
        .total-row td {
            font-weight: bold;
            border-top: 2px solid #ddd;
        }
        
        .signature {
            margin-top: 50px;
            text-align: right;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            margin-left: auto;
            margin-bottom: 5px;
        }
        
        .thank-you {
            margin-top: 30px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            color: #0d6efd;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .print-hide {
            margin-top: 20px;
            text-align: center;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .receipt-container {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 15px;
            }
            
            .print-hide {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="gym-name">Strength Gym</div>
        <div class="gym-address">123 Fitness Street, Healthyville, FT 12345<br>Phone: (123) 456-7890 | Email: info@fitnessgym.com</div>
        
        <div class="receipt-title">PAYMENT RECEIPT</div>
        
        <div class="receipt-info">
            <table>
                <tr>
                    <td class="label">Receipt Number:</td>
                    <td><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
                    <td class="label">Date:</td>
                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="member-info">
            <table>
                <tr>
                    <td class="label">Member Name:</td>
                    <td><?php echo htmlspecialchars($payment['member_name']); ?></td>
                </tr>
                <tr>
                    <td class="label">Member ID:</td>
                    <td><?php echo htmlspecialchars($payment['member_code']); ?></td>
                </tr>
                <tr>
                    <td class="label">Email:</td>
                    <td><?php echo htmlspecialchars($payment['member_email']); ?></td>
                </tr>
                <tr>
                    <td class="label">Phone:</td>
                    <td><?php echo htmlspecialchars($payment['member_phone']); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="payment-details">
            <table>
                <tr>
                    <th>Description</th>
                    <th>Duration</th>
                    <th>Amount</th>
                </tr>
                <tr>
                    <td><?php echo htmlspecialchars($payment['description']); ?></td>
                    <td><?php echo htmlspecialchars($payment['plan_duration'] ? $payment['plan_duration'] . ' days' : 'N/A'); ?></td>
                    <td>Rs<?php echo number_format($payment['amount'], 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="2" align="right">Total:</td>
                    <td>Rs<?php echo number_format($payment['amount'], 2); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="payment-info">
            <table>
                <tr>
                    <td class="label">Payment Method:</td>
                    <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                </tr>
                <tr>
                    <td class="label">Payment Status:</td>
                    <td><strong>PAID</strong></td>
                </tr>
            </table>
        </div>
        
        <div class="signature">
            <div class="signature-line"></div>
            <div>Authorized Signature</div>
            <div><?php echo htmlspecialchars($payment['admin_name']); ?></div>
        </div>
        
        <div class="thank-you">Thank you for choosing Strength Gym!</div>
        
        <div class="footer">
            This is a computer-generated receipt and does not require a physical signature.<br>
            Generated on: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
        
        <div class="print-hide">
            <button class="btn btn-primary" onclick="window.print()">Print Receipt</button>
            <a href="admin/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>
    
    <script>
        // Auto-print when the page loads (optional)
        window.onload = function() {
            // Uncomment the line below to automatically open print dialog when receipt is viewed
            // window.print();
        };
    </script>
</body>
</html>

