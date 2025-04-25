<?php
/**
 * SMS Notification Functions
 * 
 * This file contains functions for sending SMS notifications to gym members
 */

/**
 * Send SMS using configured API
 * 
 * @param string $phone_number The recipient's phone number
 * @param string $message The message to send
 * @param int $member_id The member ID (optional)
 * @param int $template_id The template ID (optional)
 * @return array Status of the SMS sending operation
 */
function sendSMS($conn, $phone_number, $message, $member_id = null, $template_id = null) {
    try {
        // Get SMS configuration
        $config_query = "SELECT * FROM sms_config WHERE is_active = 1 LIMIT 1";
        $config_result = $conn->query($config_query);
        
        if ($config_result->num_rows == 0) {
            throw new Exception("SMS configuration not found or inactive");
        }
        
        $config = $config_result->fetch_assoc();
        
        // Log the SMS attempt
        $stmt = $conn->prepare("INSERT INTO sms_logs (member_id, phone_number, message, template_id, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->bind_param("issi", $member_id, $phone_number, $message, $template_id);
        $stmt->execute();
        $log_id = $stmt->insert_id;
        $stmt->close();
        
        // Format phone number (remove spaces, ensure it starts with country code)
        $phone_number = formatPhoneNumber($phone_number);
        
        // Send SMS via API
        $response = callSMSAPI($config, $phone_number, $message);
        
        // Update log with response
        if ($response['success']) {
            $stmt = $conn->prepare("UPDATE sms_logs SET status = 'sent' WHERE id = ?");
            $stmt->bind_param("i", $log_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $error = $response['message'];
            $stmt = $conn->prepare("UPDATE sms_logs SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->bind_param("si", $error, $log_id);
            $stmt->execute();
            $stmt->close();
        }
        
        return $response;
    } catch (Exception $e) {
        // Log error
        error_log("SMS sending error: " . $e->getMessage());
        
        // Update log if it was created
        if (isset($log_id)) {
            $error = $e->getMessage();
            $stmt = $conn->prepare("UPDATE sms_logs SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->bind_param("si", $error, $log_id);
            $stmt->execute();
            $stmt->close();
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Call the SMS API based on configuration
 * 
 * @param array $config The SMS API configuration
 * @param string $phone_number The recipient's phone number
 * @param string $message The message to send
 * @return array Response from the API
 */
function callSMSAPI($config, $phone_number, $message) {
    try {
        $api_provider = $config['api_provider'];
        $api_endpoint = $config['api_endpoint'];
        $api_key = $config['api_key'];
        $sender_id = $config['sender_id'];
        
        // Initialize cURL
        $ch = curl_init();
        
        // Set up request based on provider
        switch ($api_provider) {
            case 'textlocal':
                // Example for TextLocal API
                $data = array(
                    'apikey' => $api_key,
                    'numbers' => $phone_number,
                    'sender' => $sender_id,
                    'message' => $message
                );
                break;
                
            case 'twilio':
                // Example for Twilio API
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $api_key . ":" . $config['api_secret']);
                $data = array(
                    'From' => $sender_id,
                    'To' => $phone_number,
                    'Body' => $message
                );
                break;
                
            case 'msg91':
                // Example for MSG91 API
                $data = array(
                    'authkey' => $api_key,
                    'mobiles' => $phone_number,
                    'message' => $message,
                    'sender' => $sender_id,
                    'route' => '4',
                    'country' => '91'
                );
                break;
                
            default:
                // Generic API implementation
                $data = array(
                    'apikey' => $api_key,
                    'to' => $phone_number,
                    'message' => $message,
                    'sender' => $sender_id
                );
                break;
        }
        
        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $api_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // Execute the request
        $response = curl_exec($ch);
        $err = curl_error($ch);
        
        curl_close($ch);
        
        if ($err) {
            return [
                'success' => false,
                'message' => "cURL Error: " . $err
            ];
        }
        
        // Process response based on provider
        switch ($api_provider) {
            case 'textlocal':
                $result = json_decode($response, true);
                return [
                    'success' => isset($result['status']) && $result['status'] === 'success',
                    'message' => isset($result['status']) ? $result['status'] : 'Unknown response'
                ];
                
            case 'twilio':
                $result = json_decode($response, true);
                return [
                    'success' => isset($result['sid']),
                    'message' => isset($result['sid']) ? 'Message sent with SID: ' . $result['sid'] : 'Failed to send message'
                ];
                
            default:
                // Generic response handling
                return [
                    'success' => true,
                    'message' => 'Message sent successfully'
                ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Format phone number for SMS sending
 * 
 * @param string $phone_number The phone number to format
 * @return string Formatted phone number
 */
function formatPhoneNumber($phone_number) {
    // Remove any non-numeric characters
    $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
    
    // Ensure it has country code (assuming India +91)
    if (strlen($phone_number) == 10) {
        $phone_number = '91' . $phone_number;
    }
    
    return $phone_number;
}

/**
 * Send membership activation SMS
 * 
 * @param object $conn Database connection
 * @param int $member_id The member ID
 * @return array Status of the SMS sending operation
 */
function sendMembershipActivationSMS($conn, $member_id) {
    try {
        // Get member details
        $stmt = $conn->prepare("
            SELECT m.*, mp.name as plan_name, mp.duration
            FROM gym_members m
            LEFT JOIN membership_plans mp ON m.membership_id = mp.id
            WHERE m.id = ?
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows == 0) {
            throw new Exception("Member not found");
        }
        
        $member = $result->fetch_assoc();
        
        // Get activation template
        $template_query = "SELECT * FROM sms_templates WHERE template_type = 'activation' AND is_active = 1 LIMIT 1";
        $template_result = $conn->query($template_query);
        
        if ($template_result->num_rows == 0) {
            throw new Exception("Activation template not found or inactive");
        }
        
        $template = $template_result->fetch_assoc();
        
        // Replace placeholders in template
        $message = $template['template_content'];
        $message = str_replace('{member_name}', $member['first_name'] . ' ' . $member['last_name'], $message);
        $message = str_replace('{plan_name}', $member['plan_name'], $message);
        $message = str_replace('{expiry_date}', date('d-m-Y', strtotime($member['membership_end_date'])), $message);
        
        // Send SMS
        return sendSMS($conn, $member['phone'], $message, $member_id, $template['id']);
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send membership renewal reminder SMS
 * 
 * @param object $conn Database connection
 * @param int $member_id The member ID
 * @return array Status of the SMS sending operation
 */
function sendMembershipRenewalSMS($conn, $member_id) {
    try {
        // Get member details
        $stmt = $conn->prepare("
            SELECT m.*, mp.name as plan_name
            FROM gym_members m
            LEFT JOIN membership_plans mp ON m.membership_id = mp.id
            WHERE m.id = ?
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows == 0) {
            throw new Exception("Member not found");
        }
        
        $member = $result->fetch_assoc();
        
        // Get renewal template
        $template_query = "SELECT * FROM sms_templates WHERE template_type = 'renewal' AND is_active = 1 LIMIT 1";
        $template_result = $conn->query($template_query);
        
        if ($template_result->num_rows == 0) {
            throw new Exception("Renewal template not found or inactive");
        }
        
        $template = $template_result->fetch_assoc();
        
        // Replace placeholders in template
        $message = $template['template_content'];
        $message = str_replace('{member_name}', $member['first_name'] . ' ' . $member['last_name'], $message);
        $message = str_replace('{plan_name}', $member['plan_name'], $message);
        $message = str_replace('{expiry_date}', date('d-m-Y', strtotime($member['membership_end_date'])), $message);
        
        // Send SMS
        return sendSMS($conn, $member['phone'], $message, $member_id, $template['id']);
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Check for expiring memberships and send reminders
 * 
 * @param object $conn Database connection
 * @return array Results of the operation
 */
function checkAndSendRenewalReminders($conn) {
    try {
        // Find members whose membership expires in 7 days
        $expiry_date = date('Y-m-d', strtotime('+7 days'));
        
        $query = "
            SELECT id 
            FROM gym_members 
            WHERE membership_end_date = ? 
            AND status = 'active'
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $expiry_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        $sent_count = 0;
        $failed_count = 0;
        
        // Send reminders to each expiring member
        while ($member = $result->fetch_assoc()) {
            $response = sendMembershipRenewalSMS($conn, $member['id']);
            
            if ($response['success']) {
                $sent_count++;
            } else {
                $failed_count++;
            }
        }
        
        return [
            'success' => true,
            'sent' => $sent_count,
            'failed' => $failed_count,
            'total' => $sent_count + $failed_count
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send custom SMS to a member
 * 
 * @param object $conn Database connection
 * @param int $member_id The member ID
 * @param string $message The custom message
 * @return array Status of the SMS sending operation
 */
function sendCustomSMS($conn, $member_id, $message) {
    try {
        // Get member details
        $stmt = $conn->prepare("SELECT phone FROM gym_members WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows == 0) {
            throw new Exception("Member not found");
        }
        
        $member = $result->fetch_assoc();
        
        // Send SMS
        return sendSMS($conn, $member['phone'], $message, $member_id, null);
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send bulk SMS to multiple members
 * 
 * @param object $conn Database connection
 * @param array $member_ids Array of member IDs
 * @param string $message The message to send
 * @return array Results of the operation
 */
function sendBulkSMS($conn, $member_ids, $message) {
    $results = [
        'success' => true,
        'sent' => 0,
        'failed' => 0,
        'total' => count($member_ids)
    ];
    
    foreach ($member_ids as $member_id) {
        $response = sendCustomSMS($conn, $member_id, $message);
        
        if ($response['success']) {
            $results['sent']++;
        } else {
            $results['failed']++;
        }
    }
    
    return $results;
}
