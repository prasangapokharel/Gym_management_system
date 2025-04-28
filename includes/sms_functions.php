<?php
// SMS Functions for Gym Management System

// Define constants only if not already defined
if (!defined('API_KEY')) {
    define('API_KEY', '3B853539856F3FD36823E959EF82ABF6');
}
if (!defined('API_URL')) {
    define('API_URL', 'https://user.birasms.com/api/smsapi');
}
if (!defined('ROUTE_ID')) {
    define('ROUTE_ID', 'SI_Alert');
}

/**
 * Send SMS using BIR SMS API
 * 
 * @param string $phoneNumbers Phone number(s) to send SMS to
 * @param string $message Message content
 * @return array Result with success status and message
 */
function sendSMS($phoneNumbers, $message) {
    // Clean phone numbers (remove spaces, dashes, etc.)
    if (!is_string($phoneNumbers)) {
        return ['success' => false, 'message' => 'Invalid phone number format'];
    }
    
    $phoneNumbers = preg_replace('/\s+/', '', $phoneNumbers); // Remove whitespace
    
    // Prepare POST parameters
    $postData = [
        'key' => API_KEY,
        'campaign' => 'Default',
        'routeid' => ROUTE_ID,
        'type' => 'text',
        'contacts' => $phoneNumbers, // Can be comma-separated list
        'msg' => $message, // Raw message - not URL encoded
        'responsetype' => 'json'
    ];
    
    // Initialize cURL session
    $ch = curl_init(API_URL);
    
    // Set cURL options for POST request
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Execute cURL session and get response
    $response = curl_exec($ch);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        $error = 'cURL Error: ' . curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'message' => $error];
    }
    
    // Close cURL session
    curl_close($ch);
    
    // Process response
    $result = json_decode($response, true);
    
    // Check if the API call was successful
    if (isset($result['status']) && $result['status'] == 'success') {
        return ['success' => true, 'message' => 'SMS sent successfully', 'data' => $result];
    } else {
        $errorMsg = isset($result['message']) ? $result['message'] : 'Unknown error occurred';
        return ['success' => false, 'message' => $errorMsg, 'data' => $result];
    }
}

/**
 * Log SMS messaging activity
 */
function logSmsActivity($conn, $member_id, $phone, $message, $status, $error_message = null) {
    $query = "INSERT INTO sms_logs (member_id, phone_number, message, status, error_message, sent_at) 
              VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issss", $member_id, $phone, $message, $status, $error_message);
    return $stmt->execute();
}

/**
 * Get SMS logs for a specific member
 */
function getMemberSmsLogs($conn, $member_id, $limit = 10) {
    $query = "SELECT * FROM sms_logs WHERE member_id = ? ORDER BY sent_at DESC LIMIT ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $member_id, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get all SMS logs with pagination
 */
function getAllSmsLogs($conn, $page = 1, $per_page = 20) {
    $offset = ($page - 1) * $per_page;
    
    $query = "SELECT sl.*, CONCAT(gm.first_name, ' ', gm.last_name) as member_name 
              FROM sms_logs sl
              LEFT JOIN gym_members gm ON sl.member_id = gm.id
              ORDER BY sl.sent_at DESC
              LIMIT ?, ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $offset, $per_page);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Count total SMS logs
 */
function countSmsLogs($conn) {
    $query = "SELECT COUNT(*) as total FROM sms_logs";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    return $row['total'];
}

/**
 * Get SMS templates
 */
function getSmsTemplates($conn, $active_only = true) {
    $query = "SELECT * FROM sms_templates";
    if ($active_only) {
        $query .= " WHERE is_active = 1";
    }
    $query .= " ORDER BY template_name";
    
    $result = $conn->query($query);
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Send bulk SMS to multiple members
 */
function sendBulkSMS($conn, $memberIds, $messageTemplate) {
    $sent = 0;
    $failed = 0;
    $errors = [];
    
    // Process each member
    foreach ($memberIds as $memberId) {
        // Get member details
        $query = "SELECT m.id, m.first_name, m.last_name, m.phone, mp.name as plan_name, m.membership_end_date 
                 FROM gym_members m 
                 LEFT JOIN membership_plans mp ON m.membership_id = mp.id 
                 WHERE m.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        $stmt->close();
        
        if (!$member || empty($member['phone'])) {
            $failed++;
            continue;
        }
        
        // Replace placeholders in message
        $memberName = $member['first_name'] . ' ' . $member['last_name'];
        $planName = $member['plan_name'] ?? 'N/A';
        $expiryDate = $member['membership_end_date'] ? date('d-m-Y', strtotime($member['membership_end_date'])) : 'N/A';
        
        $message = str_replace(
            ['{member_name}', '{plan_name}', '{expiry_date}'],
            [$memberName, $planName, $expiryDate],
            $messageTemplate
        );
        
        // Send SMS
        $result = sendSMS($member['phone'], $message);
        
        if ($result['success']) {
            $sent++;
            
            // Log the SMS
            $stmt = $conn->prepare("INSERT INTO sms_logs (member_id, phone_number, message, status) VALUES (?, ?, ?, 'sent')");
            $stmt->bind_param("iss", $memberId, $member['phone'], $message);
            $stmt->execute();
            $stmt->close();
        } else {
            $failed++;
            $errors[] = "Failed to send SMS to {$memberName}: {$result['message']}";
            
            // Log the failed SMS
            $errorMsg = $result['message'];
            $stmt = $conn->prepare("INSERT INTO sms_logs (member_id, phone_number, message, status, error_message) VALUES (?, ?, ?, 'failed', ?)");
            $stmt->bind_param("isss", $memberId, $member['phone'], $message, $errorMsg);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    return [
        'success' => $sent > 0,
        'sent' => $sent,
        'failed' => $failed,
        'errors' => $errors,
        'message' => $failed > 0 ? implode("; ", $errors) : ''
    ];
}
?>
