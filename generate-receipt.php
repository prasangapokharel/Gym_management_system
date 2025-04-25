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

// Include TCPDF library
require_once('tcpdf/tcpdf.php');

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Gym Management System');
$pdf->SetAuthor('Gym Management');
$pdf->SetTitle('Payment Receipt');
$pdf->SetSubject('Payment Receipt');
$pdf->SetKeywords('Payment, Receipt, Gym, Membership');

// Remove header and footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(15, 15, 15);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font
$pdf->SetFont('helvetica', '', 10);

// Add a page
$pdf->AddPage();

// Get the current date and time
$current_date = date('Y-m-d H:i:s');

// Create the receipt content
$html = '
<style>
    .receipt-title {
        font-size: 20px;
        font-weight: bold;
        color: #333;
        text-align: center;
        margin-bottom: 20px;
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
    .receipt-info {
        margin-bottom: 20px;
    }
    .receipt-info table {
        width: 100%;
        border-collapse: collapse;
    }
    .receipt-info td {
        padding: 5px;
    }
    .receipt-info .label {
        font-weight: bold;
        width: 40%;
    }
    .member-info {
        margin-bottom: 20px;
    }
    .payment-details {
        margin-bottom: 20px;
    }
    .payment-details table {
        width: 100%;
        border-collapse: collapse;
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
    .footer {
        margin-top: 30px;
        text-align: center;
        font-size: 12px;
        color: #666;
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
</style>

<div class="gym-name">Strength Gym</div>
<div class="gym-address">123 Fitness Street, Healthyville, FT 12345<br>Phone: (123) 456-7890 | Email: info@fitnessgym.com</div>

<div class="receipt-title">PAYMENT RECEIPT</div>

<div class="receipt-info">
    <table>
        <tr>
            <td class="label">Receipt Number:</td>
            <td>' . htmlspecialchars($payment['receipt_number']) . '</td>
            <td class="label">Date:</td>
            <td>' . date('M d, Y', strtotime($payment['payment_date'])) . '</td>
        </tr>
    </table>
</div>

<div class="member-info">
    <table>
        <tr>
            <td class="label">Member Name:</td>
            <td>' . htmlspecialchars($payment['member_name']) . '</td>
        </tr>
        <tr>
            <td class="label">Member ID:</td>
            <td>' . htmlspecialchars($payment['member_code']) . '</td>
        </tr>
        <tr>
            <td class="label">Email:</td>
            <td>' . htmlspecialchars($payment['member_email']) . '</td>
        </tr>
        <tr>
            <td class="label">Phone:</td>
            <td>' . htmlspecialchars($payment['member_phone']) . '</td>
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
            <td>' . htmlspecialchars($payment['description']) . '</td>
            <td>' . htmlspecialchars($payment['plan_duration'] ? $payment['plan_duration'] . ' days' : 'N/A') . '</td>
            <td>$' . number_format($payment['amount'], 2) . '</td>
        </tr>
        <tr class="total-row">
            <td colspan="2" align="right">Total:</td>
            <td>$' . number_format($payment['amount'], 2) . '</td>
        </tr>
    </table>
</div>

<div class="payment-info">
    <table>
        <tr>
            <td class="label">Payment Method:</td>
            <td>' . ucwords(str_replace('_', ' ', $payment['payment_method'])) . '</td>
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
    <div>' . htmlspecialchars($payment['admin_name']) . '</div>
</div>

<div class="thank-you">Thank you for choosing Fitness Gym!</div>

<div class="footer">
    This is a computer-generated receipt and does not require a physical signature.<br>
    Generated on: ' . date('Y-m-d H:i:s') . '
</div>
';

// Print content
$pdf->writeHTML($html, true, false, true, false, '');

// Close and output PDF document
$pdf->Output('receipt_' . $payment['receipt_number'] . '.pdf', 'I');

