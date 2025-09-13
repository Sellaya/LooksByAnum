<?php
// =============================================================================
// CUSTOM QUOTE EMAIL SYSTEM - Compatible with existing function calls
// =============================================================================

/**
 * Get environment variable - completely self-contained
 */
function getCustomQuoteEnv($key, $default = null) {
    // Try getenv first
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    
    // Try $_ENV
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    
    // Try $_SERVER
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    
    // Try reading .env file directly
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        if ($envContent && preg_match("/^{$key}\s*=\s*(.+)$/m", $envContent, $matches)) {
            return trim($matches[1], ' "\'');
        }
    }
    
    return $default;
}

/**
 * Initialize email system - EXACT function name you're calling
 */
function initializeCustomQuoteEmailSystem() {
    $smtpUsername = getCustomQuoteEnv('SMTP_USERNAME');
    $smtpPassword = getCustomQuoteEnv('SMTP_PASSWORD');
    
    if (empty($smtpUsername) || empty($smtpPassword)) {
        error_log("Custom Quote Email: SMTP credentials not found in environment");
        return false;
    }
    
    return true;
}

/**
 * Send admin notification - matching the naming pattern
 */
function sendCustomQuoteAdminNotification($bookingData, $contractPath = null) {
    // Validate input
    if (empty($bookingData) || !is_array($bookingData)) {
        error_log("Custom Quote Email: Invalid booking data");
        return false;
    }
    
    // Get SMTP credentials
    $smtpUsername = getCustomQuoteEnv('SMTP_USERNAME');
    $smtpPassword = getCustomQuoteEnv('SMTP_PASSWORD');
    
    if (empty($smtpUsername) || empty($smtpPassword)) {
        error_log("Custom Quote Email: Missing SMTP credentials");
        return sendFallbackEmail($bookingData); // Fallback to basic PHP mail
    }
    
    // Check if PHPMailer is available
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("Custom Quote Email: PHPMailer not available, using fallback");
        return sendFallbackEmail($bookingData);
    }
    
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // SSL settings for shared hosting
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Email setup
        $adminEmail = 'info@looksbyanum.com';
        $clientName = getBookingValue($bookingData, 'client_name', 'name', 'Unknown Client');
        $bookingId = getBookingValue($bookingData, 'booking_id', 'unique_id', 'Unknown ID');
        $serviceType = getBookingValue($bookingData, 'service_type', null, 'Bridal Service');
        
        $mail->setFrom($smtpUsername, 'Looks By Anum Booking');
        $mail->addAddress($adminEmail, 'Admin Team');
        $mail->addReplyTo('info@looksbyanum.com', 'Looks By Anum');
        
        $mail->isHTML(true);
        $mail->Subject = "New Booking Confirmed - {$clientName} ({$serviceType}) - #{$bookingId}";
        $mail->Body = generateCustomQuoteEmailTemplate($bookingData, $contractPath);
        
        // Add contract if available
        if ($contractPath && file_exists($contractPath)) {
            $mail->addAttachment($contractPath, basename($contractPath));
        }
        
        $mail->send();
        error_log("Custom Quote Email: PHPMailer success for booking {$bookingId}");
        return true;
        
    } catch (Exception $e) {
        error_log("Custom Quote Email: PHPMailer failed - " . $e->getMessage());
        return sendFallbackEmail($bookingData);
    }
}

/**
 * Handle booking confirmation - wrapper function for easy integration
 */
function handleCustomQuoteBookingConfirmation($bookingData, $contractPath = null) {
    if (!initializeCustomQuoteEmailSystem()) {
        error_log("Custom Quote: Email system initialization failed");
        return false;
    }
    
    return sendCustomQuoteAdminNotification($bookingData, $contractPath);
}

/**
 * Fallback email using basic PHP mail()
 */
function sendFallbackEmail($bookingData) {
    $adminEmail = 'info@looksbyanum.com';
    $clientName = getBookingValue($bookingData, 'client_name', 'name', 'Unknown Client');
    $bookingId = getBookingValue($bookingData, 'booking_id', 'unique_id', 'Unknown ID');
    $serviceType = getBookingValue($bookingData, 'service_type', null, 'Bridal Service');
    
    $subject = "New Booking Confirmed - {$clientName} ({$serviceType}) - #{$bookingId}";
    $message = generateCustomQuoteEmailTemplate($bookingData);
    
    $headers = "From: Looks By Anum <noreply@looksbyanum.com>\r\n";
    $headers .= "Reply-To: info@looksbyanum.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $result = mail($adminEmail, $subject, $message, $headers);
    
    if ($result) {
        error_log("Custom Quote Email: Basic mail success for booking {$bookingId}");
        return true;
    } else {
        error_log("Custom Quote Email: Basic mail also failed for booking {$bookingId}");
        return false;
    }
}

/**
 * Get value from booking data with fallback keys
 */
function getBookingValue($bookingData, $primary, $secondary = null, $default = '') {
    if (isset($bookingData[$primary]) && !empty($bookingData[$primary])) {
        return $bookingData[$primary];
    }
    
    if ($secondary && isset($bookingData[$secondary]) && !empty($bookingData[$secondary])) {
        return $bookingData[$secondary];
    }
    
    return $default;
}

/**
 * Generate email template - EXACT function name you're using
 */
function generateCustomQuoteEmailTemplate($bookingData, $contractPath = null) {
    // Extract booking information
    $clientName = getBookingValue($bookingData, 'client_name', 'name', 'Unknown Client');
    $clientEmail = getBookingValue($bookingData, 'client_email', 'email', 'Unknown Email');
    $clientPhone = getBookingValue($bookingData, 'client_phone', 'phone', 'Unknown Phone');
    $bookingId = getBookingValue($bookingData, 'booking_id', 'unique_id', 'Unknown ID');
    $serviceType = getBookingValue($bookingData, 'service_type', null, 'Bridal Service');
    $artistType = getBookingValue($bookingData, 'artist_type', 'artist', 'Team');
    
    // Financial information
    $total = floatval(getBookingValue($bookingData, 'quote_total', 'price', 0));
    $depositPaid = floatval(getBookingValue($bookingData, 'deposit_amount', null, 0));
    $remainingBalance = $total - $depositPaid;
    
    // Date and time information
    $eventDate = getBookingValue($bookingData, 'event_date', null, '');
    $readyTime = getBookingValue($bookingData, 'ready_time', null, '');
    
    // Format event date
    $eventDateFormatted = '';
    if ($eventDate) {
        try {
            $date = new DateTime($eventDate);
            $eventDateFormatted = $date->format('l, F j, Y');
        } catch (Exception $e) {
            $eventDateFormatted = $eventDate;
        }
    }
    
    // Format ready time and calculate appointment time
    $readyTimeFormatted = '';
    $appointmentTime = '';
    if ($readyTime) {
        try {
            $ready = new DateTime($readyTime);
            $readyTimeFormatted = $ready->format('g:i A');
            
            $appointment = clone $ready;
            $appointment->sub(new DateInterval('PT2H'));
            $appointmentTime = $appointment->format('g:i A');
        } catch (Exception $e) {
            $readyTimeFormatted = $readyTime;
            $appointmentTime = 'TBD';
        }
    }
    
    // Service location
    $locationParts = array_filter([
        getBookingValue($bookingData, 'street_address', null, ''),
        getBookingValue($bookingData, 'city', null, ''),
        getBookingValue($bookingData, 'province', null, ''),
        getBookingValue($bookingData, 'postal_code', null, '')
    ]);
    $serviceLocation = !empty($locationParts) ? implode(', ', $locationParts) : 'TBD';
    
    // Build HTML email
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking Confirmation</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            line-height: 1.6;
            color: #111111;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #fafafa;
        }
        .container {
            background: #ffffff;
            border-radius: 12px;
            padding: 40px;
            border: 2px solid #e6e6e6;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e6e6e6;
        }
        .header h1 {
            color: #111111;
            margin: 0 0 8px 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.4px;
        }
        .status-alert {
            background: #28a745;
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .section {
            margin-bottom: 32px;
        }
        .section-title {
            background: #111111;
            color: #ffffff;
            padding: 12px 20px;
            margin: 0 0 1px 0;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border: 1px solid #e6e6e6;
        }
        .info-table th,
        .info-table td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #e6e6e6;
        }
        .info-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #111111;
            width: 35%;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .info-table td {
            color: #111111;
            font-size: 14px;
        }
        .highlight-row {
            background: #fff3cd !important;
            font-weight: 600;
        }
        .next-steps {
            background: #fff3cd;
            border-left: 4px solid #111111;
            padding: 16px 20px;
            margin: 24px 0;
            border-radius: 0 6px 6px 0;
        }
        .footer {
            margin-top: 40px;
            padding-top: 24px;
            border-top: 2px solid #e6e6e6;
            text-align: center;
            color: #666666;
            font-size: 13px;
        }
        .contract-info {
            background: #e7f3ff;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Booking Confirmed</h1>
            <p style="color: #666666; margin: 0;">Looks By Anum Professional Services</p>
            <p style="color: #666666; margin: 8px 0 0 0; font-size: 13px;">
                Received on ' . date('F j, Y \a\t g:i A') . '
            </p>
        </div>

        <div class="status-alert">
            PAYMENT SUCCESSFUL &amp; BOOKING CONFIRMED
        </div>

        <!-- Booking Information -->
        <div class="section">
            <h3 class="section-title">Booking Information</h3>
            <table class="info-table">
                <tr><th>Booking ID</th><td><strong>' . htmlspecialchars($bookingId) . '</strong></td></tr>
                <tr><th>Service Type</th><td><strong>' . htmlspecialchars($serviceType) . '</strong></td></tr>
                <tr><th>Artist</th><td><strong>' . htmlspecialchars($artistType) . '</strong></td></tr>
                <tr><th>Status</th><td><span style="background: #28a745; color: white; padding: 4px 12px; border-radius: 16px; font-size: 11px; font-weight: 600; text-transform: uppercase;">CONFIRMED</span></td></tr>
            </table>
        </div>

        <!-- Client Details -->
        <div class="section">
            <h3 class="section-title">Client Details</h3>
            <table class="info-table">
                <tr><th>Name</th><td><strong>' . htmlspecialchars($clientName) . '</strong></td></tr>
                <tr><th>Email</th><td><a href="mailto:' . htmlspecialchars($clientEmail) . '" style="color: #111111;">' . htmlspecialchars($clientEmail) . '</a></td></tr>
                <tr><th>Phone</th><td><a href="tel:' . htmlspecialchars($clientPhone) . '" style="color: #111111;">' . htmlspecialchars($clientPhone) . '</a></td></tr>
            </table>
        </div>';

    // Schedule & Location section (only if we have data)
    if ($eventDateFormatted || $appointmentTime || $readyTimeFormatted || $serviceLocation !== 'TBD') {
        $html .= '
        <!-- Schedule & Location -->
        <div class="section">
            <h3 class="section-title">Schedule &amp; Location</h3>
            <table class="info-table">';
        
        if ($eventDateFormatted) {
            $html .= '<tr><th>Event Date</th><td><strong>' . htmlspecialchars($eventDateFormatted) . '</strong></td></tr>';
        }
        
        if ($appointmentTime) {
            $html .= '<tr><th>Appointment Start</th><td><strong>' . htmlspecialchars($appointmentTime) . '</strong></td></tr>';
        }
        
        if ($readyTimeFormatted) {
            $html .= '<tr><th>Ready Time</th><td><strong>' . htmlspecialchars($readyTimeFormatted) . '</strong></td></tr>';
        }
        
        if ($serviceLocation !== 'TBD') {
            $html .= '<tr><th>Service Location</th><td>' . htmlspecialchars($serviceLocation) . '</td></tr>';
        }
        
        $html .= '
            </table>
        </div>';
    }

    // Financial Summary
    $html .= '
        <!-- Financial Summary -->
        <div class="section">
            <h3 class="section-title">Financial Summary</h3>
            <table class="info-table">
                <tr><th>Total Amount</th><td><strong>$' . number_format($total, 2) . ' CAD</strong></td></tr>
                <tr class="highlight-row"><th>Deposit Paid</th><td><strong>$' . number_format($depositPaid, 2) . ' CAD</strong></td></tr>';

    if ($remainingBalance > 0) {
        $html .= '<tr><th>Remaining Balance</th><td><strong>$' . number_format($remainingBalance, 2) . ' CAD</strong> <small>(due on event day)</small></td></tr>';
    } else {
        $html .= '<tr><th>Payment Status</th><td><strong style="color: #28a745;">PAID IN FULL</strong></td></tr>';
    }

    $html .= '
            </table>
        </div>';

    // Contract section
    if ($contractPath) {
        $html .= '
        <div class="contract-info">
            <h3 style="margin: 0 0 12px 0; color: #28a745;">Contract Generated</h3>
            <p style="margin: 0; font-size: 14px;">
                Professional contract with digital signature attached.<br>
                <strong>All terms and conditions included.</strong>
            </p>
        </div>';
    }

    $html .= '
        <!-- Next Steps -->
        <div class="next-steps">
            <h3 style="margin: 0 0 12px 0; font-size: 16px;">Next Steps</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px;">
                <li><strong>Contact client within 24 hours</strong></li>
                <li><strong>Add event to calendar</strong></li>
                <li><strong>Confirm service location and parking</strong></li>
                <li><strong>Review special requirements</strong></li>
            </ul>
        </div>

        <div class="footer">
            <p><strong>Booking System Notification</strong></p>
            <p style="margin: 12px 0 0 0;">
                This is an automated notification from the Looks By Anum.<br>
                Generated on ' . date('F j, Y \a\t g:i A T') . '
            </p>
        </div>
    </div>
</body>
</html>';

    return $html;
}

/**
 * Test function - add ?test_custom_quote_email=1 to your URL
 */
function testCustomQuoteEmailSystem() {
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Custom Quote Email Test</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; padding: 20px; background: #fafafa; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; border: 1px solid #e6e6e6; }
        .success { color: #28a745; background: #d4edda; padding: 12px; border-radius: 6px; margin: 10px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 12px; border-radius: 6px; margin: 10px 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 12px; border-radius: 6px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Custom Quote Email Test</h2>';
    
    // Test environment variables
    $smtpUsername = getCustomQuoteEnv('SMTP_USERNAME');
    $smtpPassword = getCustomQuoteEnv('SMTP_PASSWORD');
    
    if (empty($smtpUsername) || empty($smtpPassword)) {
        echo '<div class="error">SMTP credentials missing</div>';
        echo '<div class="info">Add SMTP_USERNAME and SMTP_PASSWORD to .env file</div>';
    } else {
        echo '<div class="success">SMTP credentials found</div>';
        
        if (initializeCustomQuoteEmailSystem()) {
            echo '<div class="success">Email system initialized</div>';
        } else {
            echo '<div class="error">Email system initialization failed</div>';
        }
    }
    
    echo '<p>Test completed. Check error logs for details.</p>';
    echo '</div></body></html>';
}

// Handle test request
if (isset($_GET['test_custom_quote_email'])) {
    testCustomQuoteEmailSystem();
    exit;
}

?>