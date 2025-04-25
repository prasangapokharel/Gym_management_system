<?php
// admin/payments.php

// This is a placeholder file.  A real payments page would have much more content.
// This example demonstrates the requested currency symbol replacement.

// Assume we have some data:
$total_earnings = 12345.67;
$total_records = 100;
$payments = [
    ['id' => 1, 'amount' => 123.45],
    ['id' => 2, 'amount' => 67.89],
];

?>

<h1>Payments</h1>

<p>Total Earnings:</p>
<h3 class="text-success">Rs<?php echo number_format($total_earnings, 2); ?></h3>

<p>Average Payment:</p>
<h3 class="text-success">Rs<?php echo $total_records > 0 ? number_format($total_earnings / $total_records, 2) : '0.00'; ?></h3>

<p>Payment Details:</p>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td><?php echo $payment['id']; ?></td>
                <td>Rs<?php echo number_format($payment['amount'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

