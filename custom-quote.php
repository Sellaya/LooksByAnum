<?php

require_once __DIR__ . '/hubspot-integration.php';
require_once __DIR__ . '/custom-quote-email.php';
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

initializeCustomQuoteEmailSystem();

// Database configuration
$db = [
  'host'   => '127.0.0.1',
  'name'   => 'u194250183_WAsbP',
  'user'   => 'u194250183_80NGy',
  'pass'   => '6OuLxGYTII',
  'prefix' => 'wp_'
];

$bookingTable = $db['prefix'] . 'bridal_bookings';

// Stripe configuration (TEST KEYS - Replace with LIVE keys for production)
$stripeConfig = [
    'secret_key' => 'sk_test_51QiHDUJrTCduWRVoexs91VYQMHOG6sPaDyOvLtAXW2tUEekuyeRQbCwhcnFS9coB6ISolMDaDXhm6OyuP3RI7vmZ00Ou2WFXgS',
    'publishable_key' => 'pk_test_51QiHDUJrTCduWRVogWF9NMbaC44Xzj4i34u9pt4uOde4WKLEzSGwP4N5ecstg9scA9tlpW9D4WSG3ZybOVUiQnzt00lylmL6a8'
];


// =============================================================================
// DATABASE COLUMN INSPECTION
// =============================================================================

if (isset($_GET['check_columns'])) {
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Get actual column structure
        $result = $pdo->query("DESCRIBE {$bookingTable}");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<!DOCTYPE html><html><head><title>Database Columns</title></head><body>';
        echo '<h2>Actual Database Columns in ' . $bookingTable . '</h2>';
        echo '<table border="1" style="border-collapse: collapse; margin: 20px;">';
        echo '<tr><th>Column Name</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';
        
        foreach ($columns as $column) {
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($column['Field']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($column['Type']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Null']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Key']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Default']) . '</td>';
            echo '<td>' . htmlspecialchars($column['Extra']) . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        
        // Also show sample data
        echo '<h2>Sample Data (first 2 rows)</h2>';
        $sampleResult = $pdo->query("SELECT * FROM {$bookingTable} LIMIT 2");
        $sampleData = $sampleResult->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($sampleData)) {
            echo '<table border="1" style="border-collapse: collapse; margin: 20px; font-size: 12px;">';
            echo '<tr>';
            foreach (array_keys($sampleData[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
            
            foreach ($sampleData as $row) {
                echo '<tr>';
                foreach ($row as $value) {
                    echo '<td>' . htmlspecialchars(substr($value, 0, 50)) . (strlen($value) > 50 ? '...' : '') . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p>No sample data found.</p>';
        }
        
        echo '</body></html>';
        
    } catch (Exception $e) {
        echo '<h2>Error</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    exit;
}
// Function to log with timestamp
function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] PAYMENT_DEBUG: $message");
}

// START SESSION EARLY
session_start();
debugLog("Session started. Session ID: " . session_id());

// =============================================================================
// FPDF AUTO-DOWNLOAD AND PDF GENERATION
// =============================================================================

// Function to download FPDF if not present
function ensureFPDFExists() {
    $fpdfPath = __DIR__ . '/fpdf/fpdf.php';
    $fpdfDir = dirname($fpdfPath);
    
    if (!file_exists($fpdfPath)) {
        debugLog("FPDF not found, downloading...");
        
        // Create fpdf directory
        if (!is_dir($fpdfDir)) {
            if (!mkdir($fpdfDir, 0755, true)) {
                debugLog("Failed to create FPDF directory");
                return false;
            }
        }
        
        // Download FPDF
        $fpdfUrl = 'http://www.fpdf.org/en/dl.php?v=185&f=zip';
        $zipPath = $fpdfDir . '/fpdf.zip';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fpdfUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $zipContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $zipContent) {
            file_put_contents($zipPath, $zipContent);
            
            // Extract ZIP
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive;
                if ($zip->open($zipPath) === TRUE) {
                    $zip->extractTo($fpdfDir);
                    $zip->close();
                    unlink($zipPath);
                    debugLog("FPDF downloaded and extracted successfully");
                    return true;
                }
            }
        }
        
        debugLog("Failed to download FPDF, using fallback method");
        return false;
    }
    
    return true;
}

// Generate contract content matching the original template exactly
function generateContractHTML($bookingData) {
    // Calculate appointment time (2 hours before ready time)
    $appointmentTime = '';
    $readyTimeFormatted = '';
    
    if (!empty($bookingData['ready_time'])) {
        $readyTime = new DateTime($bookingData['ready_time']);
        $appointmentDateTime = clone $readyTime;
        $appointmentDateTime->sub(new DateInterval('PT2H'));
        
        $appointmentTime = $appointmentDateTime->format('g:i A');
        $readyTimeFormatted = $readyTime->format('g:i A');
    }
    
    // Format event date
    $eventDateFormatted = '';
    if (!empty($bookingData['event_date'])) {
        $eventDate = new DateTime($bookingData['event_date']);
        $eventDateFormatted = $eventDate->format('d/m/Y');
    }
    
    // Calculate service breakdown from quote total with proper travel charges
    $total = floatval($bookingData['quote_total'] ?? 0);
    
    // Calculate travel charges based on region (matching quote calculation logic)
    $travelCharges = 0;
    $region = $bookingData['region'] ?? '';
    if ($region === 'Toronto/GTA') {
        $travelCharges = 25;
    } elseif (strpos($region, 'Further Out') !== false || strpos($region, '1 Hour Plus') !== false) {
        $travelCharges = 120;
    } elseif (strpos($region, 'Moderate Distance') !== false || strpos($region, '30 Minutes to 1 Hour') !== false) {
        $travelCharges = 80;
    } elseif (strpos($region, 'Immediate Neighbors') !== false || strpos($region, '15-30 Minutes') !== false) {
        $travelCharges = 40;
    } elseif ($region === 'Outside GTA') {
        $travelCharges = 80;
    } elseif (!empty($region) && $region !== 'Toronto/GTA' && $region !== 'Destination Wedding') {
        $travelCharges = 80;
    }
    
    // Calculate service charges (total minus HST minus travel)
    $totalBeforeHST = $total / 1.13;
    $serviceCharges = $totalBeforeHST - $travelCharges;
    
    // Determine service type and deposit rate
    $serviceType = $bookingData['service_type'] ?? 'Bridal';
    $depositRate = (stripos($serviceType, 'non-bridal') !== false) ? '50%' : '30%';
    
    // Count number of services (simplified - you can make this more detailed)
    $numberOfServices = 1;
    if (!empty($bookingData['trial_date'])) {
        $numberOfServices = 2; // Trial + Event
    }
    
    // Full address
    $fullAddress = trim(implode(', ', array_filter([
        $bookingData['street_address'] ?? '',
        $bookingData['city'] ?? '',
        $bookingData['province'] ?? '',
        $bookingData['postal_code'] ?? ''
    ])));
    
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract for Makeup and Hair Services</title>
    <style>
        @page { margin: 0.5in; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; line-height: 1.6; color: #333; font-size: 12px; }
        .header { text-align: center; margin-bottom: 30px; }
        .logo { font-size: 36px; font-style: italic; color: #666; margin-bottom: 10px; font-family: "Times New Roman", serif; }
        .tagline { font-size: 10px; color: #888; letter-spacing: 2px; }
        h1 { font-size: 24px; margin: 30px 0 20px 0; text-align: left; }
        h2 { font-size: 18px; margin: 20px 0 10px 0; color: #444; }
        .contract-info { margin-bottom: 20px; }
        .contract-info p { margin: 5px 0; }
        .terms ul { padding-left: 20px; margin: 10px 0; }
        .terms li { margin-bottom: 8px; }
        .signature-section { margin-top: 40px; border-top: 1px solid #ccc; padding-top: 20px; }
        .sig-line { border-bottom: 1px solid #333; width: 200px; display: inline-block; margin: 0 20px 0 10px; }
        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">looks</div>
        <div class="tagline">BY ANUM</div>
    </div>

    <h1>Contract for Makeup and Hair Services</h1>

    <div class="contract-info">
        <p><strong>Date:</strong> ' . date('d/m/Y') . '</p>
        <p><strong>Client Name:</strong> ' . htmlspecialchars($bookingData['client_name'] ?? '') . '</p>
        <p><strong>Client Address:</strong> ' . htmlspecialchars($fullAddress) . '</p>
        <p><strong>Phone:</strong> ' . htmlspecialchars($bookingData['client_phone'] ?? '') . '</p>
    </div>

    <p>Dear ' . htmlspecialchars($bookingData['client_name'] ?? '') . ',</p>

    <p>Congratulations on your upcoming event! We are thrilled to have the opportunity to provide our professional makeup and hair services for you. This contract outlines the terms and conditions of our agreement to ensure a successful and enjoyable experience. Please carefully read through the following details and sign at the bottom to indicate your acceptance.</p>

    <h2>Package Details</h2>
    
    <p><strong>Service:</strong> ' . htmlspecialchars($serviceType) . '</p>
    <p><strong>Number of Services:</strong> ' . $numberOfServices . '</p>
    <p><strong>Date of Event:</strong> ' . htmlspecialchars($eventDateFormatted) . '</p>
    <p><strong>Start Time:</strong> ' . htmlspecialchars($appointmentTime) . '</p>
    <p><strong>To Be Ready Time:</strong> ' . htmlspecialchars($readyTimeFormatted) . '</p>
    <p><strong>Charges:</strong> ' . number_format($serviceCharges, 2) . ' CAD</p>
    <p><strong>Travel Charges:</strong> ' . number_format($travelCharges, 2) . ' CAD</p>
    <p><strong>Total:</strong> ' . number_format($total, 2) . ' CAD</p>

    <h2>Price &amp; Booking</h2>
    <p>The total price for the makeup and hair services is <strong>' . number_format($total, 2) . ' CAD</strong>. A non-refundable deposit of <strong>' . $depositRate . '</strong> is required to secure your booking. The remaining balance will be due on the day of the event.</p>
    
    <p>Once we receive the deposit, your booking will be confirmed, and the date will be reserved exclusively for you. Please note that availability cannot be guaranteed until the deposit is received.</p>

    <h2>Client Responsibilities</h2>
    <div class="terms">
        <ul>
            <li>Provide accurate and detailed information regarding the desired makeup and hair services.</li>
            <li>Ensure a suitable location with proper lighting and access to an electrical outlet.</li>
            <li>Arrive with clean, dry hair and a clean face, free of makeup or hair products.</li>
            <li>Client is responsible for any parking fees incurred at the event location.</li>
        </ul>
    </div>

    <h2>Cancellation Policy</h2>
    <div class="terms">
        <ul>
            <li>The deposit is non-refundable if the client cancels.</li>
            <li>If the event is canceled less than 3 days before the scheduled date, the full remaining balance will still be due.</li>
        </ul>
    </div>

    <h2>Liability</h2>
    <div class="terms">
        <ul>
            <li><strong>Looks By Anum</strong> is not responsible for allergic reactions or injuries resulting from the services provided.</li>
            <li>The client must inform the artist of any allergies or sensitivities before the service begins.</li>
            <li>The client agrees to hold <strong>Looks By Anum</strong> harmless from any claims related to the services rendered.</li>
        </ul>
    </div>

    <h2>Agreement</h2>
    <p>By signing this contract, the client acknowledges that they have read, understood, and agree to all the terms and conditions outlined above.</p>

    <div class="signature-section">
        <p><strong>Client Signature:</strong> <span class="sig-line"></span></p>
        ' . (!empty($bookingData['signature_date']) ? '<p style="font-size: 10px; color: #666;">Digitally signed on ' . date('M j, Y \a\t g:i A', strtotime($bookingData['signature_date'])) . '</p>' : '') . '
        <br><br>
        <p><strong>Looks By Anum Representative:</strong> <span class="sig-line"></span></p>
    </div>

    ' . (!empty($bookingData['client_signature']) ? 
        '<div style="margin-top: 20px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; font-size: 14px;">Digital Signature:</h3>
            <img src="' . htmlspecialchars($bookingData['client_signature']) . '" style="max-width: 200px; height: auto; border: 1px solid #ddd;" alt="Client Signature" />
            <p style="font-size: 10px; color: #666; margin: 5px 0 0 0;">
                Signature captured digitally on ' . (!empty($bookingData['signature_date']) ? date('M j, Y \a\t g:i A', strtotime($bookingData['signature_date'])) : 'booking completion') . '
            </p>
        </div>' : '') . '

    <div class="footer">
        <!-- Contract generation info removed from footer for cleaner look -->
    </div>
</body>
</html>';
}

// Generate contract (try PDF first, fallback to HTML)
// Generate contract (try PDF first, fallback to HTML)
function generateContractPDF($bookingData, $contractPath) {
    try {
        // Try to ensure FPDF exists
        if (ensureFPDFExists()) {
            $fpdfPath = __DIR__ . '/fpdf/fpdf.php';
            if (file_exists($fpdfPath)) {
                require_once($fpdfPath);
                
                // Generate PDF using FPDF
                $pdf = new FPDF();
                $pdf->AddPage();
                $pdf->SetMargins(20, 20, 20);
                
                
                // GET DISCOUNT INFORMATION FROM BOOKING DATA
                $discountAmount = floatval($bookingData['discount_amount'] ?? 0);
                $promoCode = $bookingData['promo_code'] ?? '';
                $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
                
                // Calculate totals with discount handling
                $currentTotal = floatval($bookingData['quote_total'] ?? 0);
                $originalTotal = $hasDiscount ? floatval($bookingData['original_total'] ?? ($currentTotal + $discountAmount)) : $currentTotal;
                $total = $currentTotal; // Use current (discounted) total
                
                // Get service details
                $artistType = $bookingData['artist_type'] ?? 'team';
                $serviceType = $bookingData['service_type'] ?? 'Bridal';
                $isAnum = stripos($artistType, 'anum') !== false;
                
                // Header with centered logo
                // Header with remote logo (direct method)
                $logoUrl = 'https://looksbyanum.com/wp-content/uploads/2025/05/Untitled-design-11.png';
                
                try {
                    // Check if URL is accessible
                    $headers = @get_headers($logoUrl);
                    if ($headers && strpos($headers[0], '200')) {
                        // Logo URL is accessible
                        $logoWidth = 60;  // Adjust logo width (in mm)
                        $logoHeight = 20; // Adjust logo height (in mm)
                        $pageWidth = $pdf->GetPageWidth();
                        $centerX = ($pageWidth - $logoWidth) / 2;
                        
                        $pdf->Image($logoUrl, $centerX, $pdf->GetY(), $logoWidth, $logoHeight);
                        $pdf->Ln($logoHeight + 10); // Space after logo
                        
                        debugLog("PDF: Using remote logo directly");
                    } else {
                        throw new Exception("Logo URL not accessible");
                    }
                } catch (Exception $e) {
                    // Fallback to text if logo fails
                    debugLog("PDF: Remote logo failed, using text fallback - " . $e->getMessage());
                    $pdf->SetFont('Arial', 'I', 24);
                    $pdf->Cell(0, 15, 'looks', 0, 1, 'C');
                    $pdf->SetFont('Arial', '', 8);
                    $pdf->Cell(0, 5, 'BY ANUM', 0, 1, 'C');
                    $pdf->Ln(10);
                }
                
                // Title
                $pdf->SetFont('Arial', 'B', 18);
                $pdf->Cell(0, 10, 'Contract for Makeup and Hair Services', 0, 1, 'L');
                $pdf->Ln(8);
                
                // Calculate service breakdown from quote total with proper travel charges
                $total = floatval($bookingData['quote_total'] ?? 0);
                
                // Calculate travel charges based on region (matching quote calculation logic)
                $travelCharges = 0;
                $region = $bookingData['region'] ?? '';
                if ($region === 'Toronto/GTA') {
                    $travelCharges = 25;
                } elseif (strpos($region, 'Further Out') !== false || strpos($region, '1 Hour Plus') !== false) {
                    $travelCharges = 120;
                } elseif (strpos($region, 'Moderate Distance') !== false || strpos($region, '30 Minutes to 1 Hour') !== false) {
                    $travelCharges = 80;
                } elseif (strpos($region, 'Immediate Neighbors') !== false || strpos($region, '15-30 Minutes') !== false) {
                    $travelCharges = 40;
                } elseif ($region === 'Outside GTA') {
                    $travelCharges = 80;
                } elseif (!empty($region) && $region !== 'Toronto/GTA' && $region !== 'Destination Wedding') {
                    $travelCharges = 80;
                }
                
                // Calculate service charges (total minus HST minus travel)
                $totalBeforeHST = $total / 1.13;
                $serviceCharges = $totalBeforeHST - $travelCharges;
                
                // Determine service type and deposit rate
                $serviceType = $bookingData['service_type'] ?? 'Bridal';
                $depositRate = (stripos($serviceType, 'non-bridal') !== false) ? '50%' : '30%';
                
                // Count number of services
                $numberOfServices = 1;
                if (!empty($bookingData['trial_date'])) {
                    $numberOfServices = 2; // Trial + Event
                }
                
                // Calculate appointment time (2 hours before ready time)
                $appointmentTime = '';
                $readyTimeFormatted = '';
                
                if (!empty($bookingData['ready_time'])) {
                    try {
                        $readyTime = new DateTime($bookingData['ready_time']);
                        $appointmentDateTime = clone $readyTime;
                        $appointmentDateTime->sub(new DateInterval('PT2H'));
                        
                        $appointmentTime = $appointmentDateTime->format('g:i A');
                        $readyTimeFormatted = $readyTime->format('g:i A');
                    } catch (Exception $e) {
                        debugLog("Time calculation error: " . $e->getMessage());
                    }
                }
                
                // Format event date
                $eventDateFormatted = '';
                if (!empty($bookingData['event_date'])) {
                    try {
                        $eventDate = new DateTime($bookingData['event_date']);
                        $eventDateFormatted = $eventDate->format('d/m/Y');
                    } catch (Exception $e) {
                        debugLog("Date formatting error: " . $e->getMessage());
                    }
                }
                
                // Full address
                $fullAddress = trim(implode(', ', array_filter([
                    $bookingData['street_address'] ?? '',
                    $bookingData['city'] ?? '',
                    $bookingData['province'] ?? '',
                    $bookingData['postal_code'] ?? ''
                ])));
                
                // Basic info (date auto-generated)
                $pdf->SetFont('Arial', '', 10);
                // Define column widths
                // Client Information Table
                $pdf->SetFont('Arial','B',14);
                $pdf->Cell(0, 10, 'Client Information', 0, 1, 'L');
                $pdf->Ln(5);
                
                // Table header
                $pdf->SetFont('Arial','B',10);
                $pdf->SetFillColor(248, 249, 250);
                $pdf->Cell(60, 8, 'Category', 1, 0, 'L', true);
                $pdf->Cell(120, 8, 'Details', 1, 1, 'L', true);
                
                // Table rows
                $pdf->SetFont('Arial','',10);
                $pdf->SetFillColor(255, 255, 255);
                
                // Date
                $pdf->Cell(60, 8, 'Date', 1, 0, 'L', true);
                $pdf->Cell(120, 8, date('m/d/Y'), 1, 1, 'L', true);
                
                // Client Name
                $pdf->Cell(60, 8, 'Client Name', 1, 0, 'L', true);
                $clientName = $bookingData['client_name'] ?? ($bookingData['name'] ?? '');
                $pdf->Cell(120, 8, $clientName, 1, 1, 'L', true);
                
                // Client Address
                $pdf->Cell(60, 8, 'Client Address', 1, 0, 'L', true);
                $address = '';
                if (!empty($bookingData['street_address'])) {
                    $address = $bookingData['street_address'];
                    if (!empty($bookingData['city'])) {
                        $address .= ', ' . $bookingData['city'];
                    }
                    if (!empty($bookingData['province'])) {
                        $address .= ', ' . $bookingData['province'];
                    }
                    if (!empty($bookingData['postal_code'])) {
                        $address .= ', ' . $bookingData['postal_code'];
                    }
                }
                // Handle long addresses with multi-line if needed
                if (strlen($address) > 45) {
                    $pdf->Cell(120, 8, substr($address, 0, 45), 1, 1, 'L', true);
                    if (strlen($address) > 45) {
                        $pdf->Cell(60, 8, '', 1, 0, 'L', true);
                        $pdf->Cell(120, 8, substr($address, 45), 1, 1, 'L', true);
                    }
                } else {
                    $pdf->Cell(120, 8, $address, 1, 1, 'L', true);
                }
                
                // Phone
                $pdf->Cell(60, 8, 'Phone', 1, 0, 'L', true);
                $phone = $bookingData['client_phone'] ?? ($bookingData['phone'] ?? '');
                $pdf->Cell(120, 8, $phone, 1, 1, 'L', true);
                
                $pdf->Ln(10);
                
                // Introduction text
                $pdf->SetFont('Arial','',10);
                $pdf->Cell(0,6,'Dear ' . ($bookingData['client_name'] ?? '') . ',',0,1);
                $pdf->Ln(3);
                
                // Introduction paragraph
                $pdf->SetFont('Arial','',9);
                $introText = 'Congratulations on your upcoming event! We are thrilled to have the opportunity to provide our professional makeup and hair services for you. This contract outlines the terms and conditions of our agreement to ensure a successful and enjoyable experience. Please carefully read through the following details and sign at the bottom to indicate your acceptance.';
                
                // Split long text into multiple lines
                $words = explode(' ', $introText);
                $line = '';
                foreach ($words as $word) {
                    $testLine = $line . $word . ' ';
                    if ($pdf->GetStringWidth($testLine) > 170) {
                        $pdf->Cell(0,5,trim($line),0,1);
                        $line = $word . ' ';
                    } else {
                        $line = $testLine;
                    }
                }
                if (!empty($line)) {
                    $pdf->Cell(0,5,trim($line),0,1);
                }
                $pdf->Ln(8);
                
                // Package Details
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Package Details',0,1);
                $pdf->SetFont('Arial','',10);
                
                // Table header
                $pdf->SetFont('Arial','B',10);
                $pdf->SetFillColor(248, 249, 250);
                $pdf->Cell(80, 8, 'Service Details', 1, 0, 'L', true);
                $pdf->Cell(100, 8, 'Information', 1, 1, 'L', true);
                
                // Table rows
                $pdf->SetFont('Arial','',10);
                $pdf->SetFillColor(255, 255, 255);
                
                // Service
                $pdf->Cell(80, 8, 'Service', 1, 0, 'L', true);
                $serviceType = $bookingData['service_type'] ?? 'Bridal';
                $pdf->Cell(100, 8, $serviceType, 1, 1, 'L', true);
                
                // Number of Services
                $pdf->Cell(80, 8, 'Number of Services', 1, 0, 'L', true);
                $pdf->Cell(100, 8, '1', 1, 1, 'L', true);
                
                // Date of Event
                $pdf->Cell(80, 8, 'Date of Event', 1, 0, 'L', true);
                $eventDate = 'TBD';
                if (!empty($bookingData['event_date'])) {
                    $eventDate = date('m/d/Y', strtotime($bookingData['event_date']));
                } elseif (!empty($bookingData['trial_date'])) {
                    $eventDate = date('m/d/Y', strtotime($bookingData['trial_date']));
                }
                $pdf->Cell(100, 8, $eventDate, 1, 1, 'L', true);
                
                // Start Time
                $pdf->Cell(80, 8, 'Start Time', 1, 0, 'L', true);
                $startTime = $bookingData['appointment_time'] ?? 'TBD';
                $pdf->Cell(100, 8, $startTime, 1, 1, 'L', true);
                
                // To Be Ready Time
                $pdf->Cell(80, 8, 'To Be Ready Time', 1, 0, 'L', true);
                $readyTime = 'TBD';
                if (!empty($bookingData['ready_time'])) {
                    $readyTime = date('g:i A', strtotime($bookingData['ready_time']));
                }
                $pdf->Cell(100, 8, $readyTime, 1, 1, 'L', true);
                
                // Add discount fields if applicable
                if ($hasDiscount) {
                    // Promotional Code
                    // $pdf->SetFillColor(255, 243, 205); // Light yellow background
                    // $pdf->Cell(80, 8, 'Promotional Code', 1, 0, 'L', true);
                    // $pdf->Cell(100, 8, $promoCode, 1, 1, 'L', true);
                    
                    // $pdf->SetFillColor(255, 255, 255);
                    
                    // Original Total
                    $pdf->Cell(80, 8, 'Original Total', 1, 0, 'L', true);
                    $pdf->Cell(100, 8, '$' . number_format($originalTotal, 2), 1, 1, 'L', true);
                    
                    // Discount Amount
                    $pdf->SetFillColor(220, 252, 231); // Light green background
                    $pdf->Cell(80, 8, 'Discount Amount', 1, 0, 'L', true);
                    $pdf->Cell(100, 8, '-$' . number_format($discountAmount, 2), 1, 1, 'L', true);
                    
                    $pdf->SetFillColor(255, 255, 255);
                }
                
                // Service Charges (calculated total of all services before HST and travel)
                $serviceChargesTotal = ($total / 1.13) - $travelCharges; // Subtract travel from subtotal
                $pdf->Cell(80, 8, 'Service Charges', 1, 0, 'L', true);
                $pdf->Cell(100, 8, '$' . number_format($serviceChargesTotal, 2), 1, 1, 'L', true);
                
                // Travel Charges
                $pdf->Cell(80, 8, 'Travel Charges', 1, 0, 'L', true);
                $pdf->Cell(100, 8, '$' . number_format($travelCharges, 2), 1, 1, 'L', true);
                
                // Total (highlighted row)
                $pdf->SetFont('Arial','B',10);
                $pdf->SetFillColor(248, 249, 250);
                $totalLabel = $hasDiscount ? 'Total (After Discount)' : 'Total';
                $pdf->Cell(80, 10, $totalLabel, 1, 0, 'L', true);
                $pdf->Cell(100, 10, '$' . number_format($total, 2), 1, 1, 'L', true);
                
                $pdf->Ln(10);
                
                // Price & Booking
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Price & Booking',0,1);
                $pdf->SetFont('Arial','',9);
                
                $priceText1 = 'The total price for the makeup and hair services is $' . number_format($total, 2) .'. A non-refundable deposit of ' . $depositRate . ' is required to secure your booking. The remaining balance will be due on the day of the event.';
                $priceText2 = 'Once we receive the deposit, your booking will be confirmed, and the date will be reserved exclusively for you. Please note that availability cannot be guaranteed until the deposit is received.';
                
                // Split text into lines
                foreach ([$priceText1, $priceText2] as $text) {
                    $words = explode(' ', $text);
                    $line = '';
                    foreach ($words as $word) {
                        $testLine = $line . $word . ' ';
                        if ($pdf->GetStringWidth($testLine) > 170) {
                            $pdf->Cell(0,5,trim($line),0,1);
                            $line = $word . ' ';
                        } else {
                            $line = $testLine;
                        }
                    }
                    if (!empty($line)) {
                        $pdf->Cell(0,5,trim($line),0,1);
                    }
                    $pdf->Ln(3);
                }
                $pdf->Ln(5);
                
                // Client Responsibilities
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Client Responsibilities',0,1);
                $pdf->SetFont('Arial','',9);
                
                $responsibilities = [
                    'Provide accurate and detailed information regarding the desired makeup and hair services.',
                    'Ensure a suitable location with proper lighting and access to an electrical outlet.',
                    'Arrive with clean, dry hair and a clean face, free of makeup or hair products.',
                    'Client is responsible for any parking fees incurred at the event location.'
                ];
                
                foreach ($responsibilities as $responsibility) {
                    $pdf->Cell(5, 5, chr(149), 0, 0); // Bullet point
                    $words = explode(' ', $responsibility);
                    $line = '';
                    foreach ($words as $word) {
                        $testLine = $line . $word . ' ';
                        if ($pdf->GetStringWidth($testLine) > 165) {
                            $pdf->Cell(0,5,trim($line),0,1);
                            $pdf->Cell(5, 5, '', 0, 0); // Indent continuation
                            $line = $word . ' ';
                        } else {
                            $line = $testLine;
                        }
                    }
                    if (!empty($line)) {
                        $pdf->Cell(0,5,trim($line),0,1);
                    }
                }
                $pdf->Ln(5);
                
                // Cancellation Policy
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Cancellation Policy',0,1);
                $pdf->SetFont('Arial','',9);
                
                $cancellations = [
                    'The deposit is non-refundable if the client cancels.',
                    'If the event is canceled less than 3 days before the scheduled date, the full remaining balance will still be due.'
                ];
                
                foreach ($cancellations as $cancellation) {
                    $pdf->Cell(5, 5, chr(149), 0, 0);
                    $words = explode(' ', $cancellation);
                    $line = '';
                    foreach ($words as $word) {
                        $testLine = $line . $word . ' ';
                        if ($pdf->GetStringWidth($testLine) > 165) {
                            $pdf->Cell(0,5,trim($line),0,1);
                            $pdf->Cell(5, 5, '', 0, 0);
                            $line = $word . ' ';
                        } else {
                            $line = $testLine;
                        }
                    }
                    if (!empty($line)) {
                        $pdf->Cell(0,5,trim($line),0,1);
                    }
                }
                $pdf->Ln(5);
                
                // Liability
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Liability',0,1);
                $pdf->SetFont('Arial','',9);
                
                $liabilities = [
                    'Looks By Anum is not responsible for allergic reactions or injuries resulting from the services provided.',
                    'The client must inform the artist of any allergies or sensitivities before the service begins.',
                    'The client agrees to hold Looks By Anum harmless from any claims related to the services rendered.'
                ];
                
                foreach ($liabilities as $liability) {
                    $pdf->Cell(5, 5, chr(149), 0, 0);
                    $words = explode(' ', $liability);
                    $line = '';
                    foreach ($words as $word) {
                        $testLine = $line . $word . ' ';
                        if ($pdf->GetStringWidth($testLine) > 165) {
                            $pdf->Cell(0,5,trim($line),0,1);
                            $pdf->Cell(5, 5, '', 0, 0); // Indent continuation
                            $line = $word . ' ';
                        } else {
                            $line = $testLine;
                        }
                    }
                    if (!empty($line)) {
                        $pdf->Cell(0,5,trim($line),0,1);
                    }
                }
                $pdf->Ln(8);
                
                // Agreement
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Agreement',0,1);
                $pdf->SetFont('Arial','',9);
                $agreementText = 'By signing this contract, the client acknowledges that they have read, understood, and agree to all the terms and conditions outlined above.';
                $words = explode(' ', $agreementText);
                $line = '';
                foreach ($words as $word) {
                    $testLine = $line . $word . ' ';
                    if ($pdf->GetStringWidth($testLine) > 170) {
                        $pdf->Cell(0,5,trim($line),0,1);
                        $line = $word . ' ';
                    } else {
                        $line = $testLine;
                    }
                }
                if (!empty($line)) {
                    $pdf->Cell(0,5,trim($line),0,1);
                }
                $pdf->Ln(15);
                
                
                // Signatures - UPDATED VERSION
                // Remove the old "Client Signature" line and just show digital signature
                
                // Try to include signature image if available
                if (!empty($bookingData['client_signature'])) {
                    try {
                        // Decode base64 signature
                        $signatureData = $bookingData['client_signature'];
                        if (strpos($signatureData, 'data:image/png;base64,') === 0) {
                            $signatureData = str_replace('data:image/png;base64,', '', $signatureData);
                            $signatureImage = base64_decode($signatureData);
                            
                            // Save temporary signature file
                            $tempDir = sys_get_temp_dir();
                            $signatureFile = $tempDir . '/signature_' . uniqid() . '.png';
                            
                            if (file_put_contents($signatureFile, $signatureImage)) {
                                // Add signature to PDF
                                $pdf->SetFont('Arial','B',12);
                                $pdf->Cell(0, 8, 'Client Signature:', 0, 1);
                                $pdf->Ln(5);
                                
                                // Add the signature image (scaled to fit nicely)
                                $pdf->Image($signatureFile, $pdf->GetX(), $pdf->GetY(), 60, 20);
                                $pdf->Ln(25);
                                
                                // Add date below signature
                                if (!empty($bookingData['signature_date'])) {
                                    $pdf->SetFont('Arial','',9);
                                    $pdf->Cell(0, 6, 'Digitally signed on ' . date('M j, Y \a\t g:i A', strtotime($bookingData['signature_date'])), 0, 1);
                                }
                                
                                // Clean up temp file
                                unlink($signatureFile);
                                
                                debugLog("Signature image included in PDF contract");
                            }
                        }
                    } catch (Exception $e) {
                        debugLog("Could not include signature image in PDF: " . $e->getMessage());
                        // Fallback: Just show client signature heading with date
                        $pdf->SetFont('Arial','B',12);
                        $pdf->Cell(0, 8, 'Client Signature:', 0, 1);
                        $pdf->Ln(10);
                        
                        if (!empty($bookingData['signature_date'])) {
                            $pdf->SetFont('Arial','',9);
                            $pdf->Cell(0, 6, 'Digitally signed on ' . date('M j, Y \a\t g:i A', strtotime($bookingData['signature_date'])), 0, 1);
                        }
                    }
                } else {
                    // No signature available - just show heading
                    $pdf->SetFont('Arial','B',12);
                    $pdf->Cell(0, 8, 'Client Signature: ________________________', 0, 1);
                    $pdf->Ln(10);
                }

// Remove the "Looks By Anum Representative" line completely
                // Save PDF
                $pdf->Output($contractPath, 'F');
                debugLog("Complete PDF contract generated successfully using FPDF");
                return true;
            }
        }
        
        // Fallback: Generate HTML contract
        debugLog("Using HTML fallback for contract generation");
        $contractHtml = generateContractHTML($bookingData);
        
        // Change extension to .html for fallback
        $htmlPath = str_replace('.pdf', '.html', $contractPath);
        $success = file_put_contents($htmlPath, $contractHtml);
        
        if ($success) {
            // Update the contract path to point to HTML file
            return $htmlPath;
        }
        
        return false;
        
    } catch (Exception $e) {
        debugLog("Contract generation error: " . $e->getMessage());
        
        // Final fallback: Simple HTML
        try {
            $simpleHtml = generateContractHTML($bookingData);
            $htmlPath = str_replace('.pdf', '.html', $contractPath);
            $success = file_put_contents($htmlPath, $simpleHtml);
            return $success ? $htmlPath : false;
        } catch (Exception $e2) {
            debugLog("Final fallback also failed: " . $e2->getMessage());
            return false;
        }
    }
}




// Quick column check (add this before the payment success handler)
if (isset($_GET['payment_success'])) {
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Check if essential columns exist
        $result = $pdo->query("SHOW COLUMNS FROM {$bookingTable}");
        $columns = $result->fetchAll(PDO::FETCH_COLUMN);
        $requiredColumns = ['client_name', 'client_email', 'artist_type', 'booking_type', 'quote_total', 'deposit_amount'];
        
        foreach ($requiredColumns as $col) {
            if (!in_array($col, $columns)) {
                debugLog("❌ MISSING COLUMN: " . $col);
            }
        }
    } catch (Exception $e) {
        debugLog("Column check error: " . $e->getMessage());
    }
}


// =============================================================================
// ENHANCED PAYMENT SUCCESS HANDLER WITH CONTRACT GENERATION
// =============================================================================


// Fixed payment success handler for wp_bridal_bookings table
// Replace the existing payment success handler with this version

if (isset($_GET['payment_success']) && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    $bookingId = $_GET['booking_id'] ?? '';
    
    debugLog("=== PAYMENT SUCCESS HANDLER (wp_bridal_bookings) ===");
    debugLog("Session ID: " . $sessionId);
    debugLog("Booking ID: " . $bookingId);
    
    // Check if we're in test mode
    $isTestMode = strpos($stripeConfig['secret_key'], 'sk_test_') === 0;
    debugLog("Test mode: " . ($isTestMode ? 'YES' : 'NO'));
    
    if (empty($bookingId)) {
        debugLog("ERROR: No booking ID provided");
        echo '<!DOCTYPE html><html><head><title>Payment Error</title></head><body style="font-family:Arial;padding:40px;text-align:center"><h1>Payment Error</h1><p>Missing booking information. Please contact support.</p></body></html>';
        exit;
    }
    
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
            $db['user'], 
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        debugLog("Database connection established");
        
        // STEP 1: Find existing record in wp_bridal_bookings
        $tableName = 'wp_bridal_bookings';
        debugLog("Searching in table: " . $tableName);
        
        $findSQL = "SELECT * FROM {$tableName} WHERE unique_id = ? LIMIT 1";
        $findStmt = $pdo->prepare($findSQL);
        $findStmt->execute([$bookingId]);
        $existingRecord = $findStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingRecord) {
            debugLog("Record not found with unique_id, trying other search methods...");
            
            // Try searching by other possible fields
            $alternativeSearches = [
                "SELECT * FROM {$tableName} WHERE id = ? LIMIT 1",
                "SELECT * FROM {$tableName} WHERE name LIKE ? LIMIT 1",
                "SELECT * FROM {$tableName} WHERE email LIKE ? LIMIT 1"
            ];
            
            foreach ($alternativeSearches as $altSQL) {
                $altStmt = $pdo->prepare($altSQL);
                if (strpos($altSQL, 'LIKE') !== false) {
                    $altStmt->execute(['%' . $bookingId . '%']);
                } else {
                    $altStmt->execute([$bookingId]);
                }
                $existingRecord = $altStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingRecord) {
                    debugLog("Found record using alternative search: " . $altSQL);
                    break;
                }
            }
        } else {
            debugLog("Record found with unique_id: " . $bookingId);
        }
        
        if (!$existingRecord) {
            debugLog("ERROR: No existing record found for booking ID: " . $bookingId);
            $success = false;
            $operationType = 'record_not_found';
        } else {
            debugLog("Record found - ID: " . $existingRecord['id']);
            
            // STEP 2: Get session data
            $sessionKey = 'pending_booking_' . $bookingId;
            $bookingData = $_SESSION[$sessionKey] ?? null;
            debugLog("Session data available: " . ($bookingData ? 'YES' : 'NO'));
            
            debugLog("Retrieving Stripe session data for discount information...");

            $discountAmount = 0;
            $promoCode = '';
            $amountPaid = 0;
            
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . $sessionId . '?expand[]=total_details.breakdown');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $stripeConfig['secret_key']
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $stripeSession = json_decode($response, true);
                    $amountPaid = ($stripeSession['amount_total'] ?? 0) / 100;
                    
                    // Extract discount information
                    if (isset($stripeSession['total_details']['breakdown']['discounts']) && 
                        is_array($stripeSession['total_details']['breakdown']['discounts']) && 
                        count($stripeSession['total_details']['breakdown']['discounts']) > 0) {
                        
                        $firstDiscount = $stripeSession['total_details']['breakdown']['discounts'][0];
                        
                        if (isset($firstDiscount['amount'])) {
                            $discountAmount = $firstDiscount['amount'] / 100;
                        }
                        
                        if (isset($firstDiscount['discount']['promotion_code'])) {
                            $promoCode = $firstDiscount['discount']['promotion_code'];
                        }
                        
                        debugLog("Discount extracted - Amount: $discountAmount, Code: $promoCode");
                    }
                }
            } catch (Exception $e) {
                debugLog("Error retrieving Stripe session: " . $e->getMessage());
            }
            
            // MERGE PHP session data with Stripe discount data
            if ($bookingData) {
                // Add discount information to existing session data
                $bookingData['discount_amount'] = $discountAmount;
                $bookingData['promo_code'] = $promoCode;
                $bookingData['amount_paid'] = $amountPaid;
                
                // Calculate original total if discount was applied
                if ($discountAmount > 0) {
                    $currentTotal = floatval($bookingData['quote_total'] ?? 0);
                    $bookingData['original_total'] = $currentTotal + $discountAmount;
                }
                
                debugLog("Booking data enhanced with discount info");
            }

            
            
            // STEP 3: Generate contract if session data exists
            $contractRelativePath = null;
            if ($bookingData) {
                debugLog("Generating contract...");
                $contractsDir = __DIR__ . '/contracts';
                if (!is_dir($contractsDir)) {
                    mkdir($contractsDir, 0755, true);
                }
                
                $contractFileName = 'Services_Contract_LBA_' . $bookingId . '_' . date('Y-m-d_H-i-s') . '.pdf';
                $contractPath = $contractsDir . '/' . $contractFileName;
                $contractGenerated = generateContractPDF($bookingData, $contractPath);
                
                if ($contractGenerated && is_string($contractGenerated)) {
                    $contractRelativePath = 'contracts/' . basename($contractGenerated);
                    debugLog("Contract generated as HTML: " . $contractRelativePath);
                } elseif ($contractGenerated) {
                    $contractRelativePath = 'contracts/' . $contractFileName;
                    debugLog("Contract generated as PDF: " . $contractRelativePath);
                } else {
                    debugLog("Contract generation failed");
                }
                // ADD CONTRACT URL TO BOOKING DATA FOR HUBSPOT
                if (isset($contractRelativePath) && $contractRelativePath) {
                    // Generate full public URL for HubSpot
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $domain = $_SERVER['HTTP_HOST'];
                    $scriptPath = dirname($_SERVER['PHP_SELF']);
                    $basePath = rtrim($scriptPath, '/');
                    
                    $contractPublicUrl = $protocol . '://' . $domain . $basePath . '/' . $contractRelativePath;
                    
                    // Add to booking data for HubSpot
                    $bookingData['contract_pdf'] = $contractPublicUrl;
                    $bookingData['contract_pdf_url'] = $contractPublicUrl;
                    
                    debugLog("✅ CONTRACT URL ADDED TO BOOKING DATA: " . $contractPublicUrl);
                } else {
                    debugLog("⚠️ No contract path available for HubSpot");
                }
            }
            
            // STEP 4: Prepare update query
            $updateFields = [];
            $updateValues = [];
            
            // Always update status and session
            $updateFields[] = "status = ?";
            $updateValues[] = 'paid';
            
            // Check if stripe_session_id column exists
            $columnsResult = $pdo->query("SHOW COLUMNS FROM {$tableName}");
            $columns = $columnsResult->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('stripe_session_id', $columns)) {
                $updateFields[] = "stripe_session_id = ?";
                $updateValues[] = $sessionId;
            }
            
            if ($contractRelativePath && in_array('contract_path', $columns)) {
                $updateFields[] = "contract_path = ?";
                $updateValues[] = $contractRelativePath;
            }
            
            // Add session data fields if available
            if ($bookingData) {
                $sessionMappings = [
                    'artist' => $bookingData['artist_type'] ?? null,
                    'ready_time' => $bookingData['ready_time'] ?? null,
                    'street_address' => $bookingData['street_address'] ?? null,
                    'city' => $bookingData['city'] ?? null,
                    'province' => $bookingData['province'] ?? null,
                    'postal_code' => $bookingData['postal_code'] ?? null,
                    'quote_total' => isset($bookingData['quote_total']) ? floatval($bookingData['quote_total']) : null,
                    'deposit_amount' => isset($bookingData['deposit_amount']) ? floatval($bookingData['deposit_amount']) : null,
                    'service_type' => $bookingData['service_type'] ?? null,
                    'client_signature' => $bookingData['client_signature'] ?? null,
                    'terms_accepted' => isset($bookingData['terms_accepted']) ? ($bookingData['terms_accepted'] ? 1 : 0) : null,
                    'signature_date' => $bookingData['signature_date'] ?? null
                ];
                
                foreach ($sessionMappings as $column => $value) {
                    if ($value !== null && in_array($column, $columns)) {
                        $updateFields[] = "{$column} = ?";
                        $updateValues[] = $value;
                    }
                }
            }
            
            // Add WHERE clause value
            $updateValues[] = $existingRecord['id'];
            
            // STEP 5: Execute update
            $updateSQL = "UPDATE {$tableName} SET " . implode(', ', $updateFields) . " WHERE id = ?";
            debugLog("Update SQL: " . $updateSQL);
            debugLog("Update values count: " . count($updateValues));
            
            try {
                $updateStmt = $pdo->prepare($updateSQL);
                $updateResult = $updateStmt->execute($updateValues);
                $rowsAffected = $updateStmt->rowCount();
                
                // Force debug to specific file
               
                if ($updateResult && $rowsAffected > 0) {
                    debugLog("RECORD UPDATED SUCCESSFULLY");
                    try {
                        $hubspotData = [
                            'booking_id' => $bookingId,
                            'client_name' => $bookingData['client_name'] ?? '',
                            'client_email' => $bookingData['client_email'] ?? '',
                            'client_phone' => $bookingData['client_phone'] ?? '',
                            'artist_type' => $bookingData['artist_type'] ?? '',
                            'service_type' => $bookingData['service_type'] ?? '',
                            'region' => $bookingData['region'] ?? '',
                            'event_date' => $bookingData['event_date'] ?? '',
                            'ready_time' => $bookingData['ready_time'] ?? '',
                            'appointment_time' => $bookingData['appointment_time'] ?? '',
                            'trial_date' => $bookingData['trial_date'] ?? '',
                            
                            // Address fields
                            'street_address' => $bookingData['street_address'] ?? '',
                            'city' => $bookingData['city'] ?? '',
                            'province' => $bookingData['province'] ?? '',
                            'postal_code' => $bookingData['postal_code'] ?? '',
                            
                            // Financial data
                            'quote_total' => $bookingData['quote_total'] ?? 0,
                            'deposit_amount' => $bookingData['deposit_amount'] ?? 0,
                            'contract_pdf' => $bookingData['contract_pdf'] ?? '',
                            'status' => 'paid'
                        ];
                        
                         file_put_contents(__DIR__ . '/hubspot_debug.txt', 
                            date('Y-m-d H:i:s') . " - Ready Time: " . ($hubspotData['ready_time'] ?? 'EMPTY') . "\n" .
                            date('Y-m-d H:i:s') . " - Appointment Time: " . ($hubspotData['appointment_time'] ?? 'EMPTY') . "\n" .
                            date('Y-m-d H:i:s') . " - Full Data: " . json_encode($hubspotData) . "\n\n", 
                            FILE_APPEND
                        );
                        
                        debugLog("HubSpot Contract PDF URL being sent: " . ($hubspotData['contract_pdf'] ?? 'EMPTY'));

                        $hubspotResult = sendQuoteToHubSpot($hubspotData);
                        
                        if ($hubspotResult) {
                            debugLog("HubSpot: Successfully sent booking " . $bookingId);
                        } else {
                            debugLog("HubSpot: Failed to send booking " . $bookingId);
                        }
                    } catch (Exception $e) {
                        debugLog("HubSpot: Error - " . $e->getMessage());
                    }
                    $success = true;
                    $operationType = 'updated_with_contract';
                    $finalContractPath = $contractRelativePath;
                    $newRecordId = $existingRecord['id'];
                    
                    // Clean up session
                    if ($bookingData) {
                        unset($_SESSION[$sessionKey]);
                        debugLog("Session cleanup completed");
                    }
                } else {
                    debugLog("UPDATE FAILED - No rows affected");
                    $success = false;
                }
                
            } catch (PDOException $e) {
                debugLog("UPDATE ERROR: " . $e->getMessage());
                $success = false;
            }
        }
        
        // STEP 6: Final verification
        if (isset($success) && $success && isset($newRecordId)) {
            debugLog("Final verification...");
            $verifyStmt = $pdo->prepare("SELECT id, status FROM {$tableName} WHERE id = ?");
            $verifyStmt->execute([$newRecordId]);
            $finalRecord = $verifyStmt->fetch();
            
            if ($finalRecord && $finalRecord['status'] === 'paid') {
                debugLog("FINAL VERIFICATION PASSED");
                $statusOK = true;
            } else {
                debugLog("FINAL VERIFICATION FAILED");
                $statusOK = false;
            }
        }
        
        debugLog("=== PAYMENT HANDLER SUMMARY ===");
        debugLog("Success: " . (isset($success) && $success ? 'YES' : 'NO'));
        debugLog("Contract: " . (isset($finalContractPath) && $finalContractPath ? 'Generated' : 'None'));
        
    } catch (PDOException $e) {
        debugLog("DATABASE ERROR: " . $e->getMessage());
        $success = false;
    } 
    try {
        // Generate the full path for the contract (for email attachment)
        $contractFullPath = null;
        if (isset($finalContractPath) && $finalContractPath) {
            $contractFullPath = __DIR__ . '/' . $finalContractPath;
        }
        
        // Prepare complete booking data for email
        $emailBookingData = $bookingData ?? [];
        
        // If we have existing record data, merge it
        if (isset($existingRecord) && $existingRecord) {
            $emailBookingData = array_merge($existingRecord, $emailBookingData);
        }
        
        // Send admin notification email
        // Send admin notification
            $emailSent = handleCustomQuoteBookingConfirmation($bookingData, $contractFullPath);
            
            if ($emailSent) {
                error_log("Custom Quote: Admin notification sent for booking " . $bookingData['booking_id']);
            } 
        
    } catch (Exception $e) {
        debugLog("Admin email error: " . $e->getMessage());
        // Don't fail the whole process for email issues
    }
    
    
    
    // Display result page
    $contractType = 'PDF';
    $contractIcon = 'Document';
    if (isset($finalContractPath) && strpos($finalContractPath, '.html') !== false) {
        $contractType = 'Document';
    }
    
function generateBookingDetailsForSuccess($bookingData) {
    if (!$bookingData) {
        return '';
    }
    
    // Extract booking information
    $bookingId = $bookingData['booking_id'] ?? '';
    $clientName = $bookingData['client_name'] ?? '';
    $clientEmail = $bookingData['client_email'] ?? '';
    $clientPhone = $bookingData['client_phone'] ?? '';
    $artistType = $bookingData['artist_type'] ?? 'anum';
    $serviceType = $bookingData['service_type'] ?? 'Bridal';
    $eventDate = $bookingData['event_date'] ?? '';
    $readyTime = $bookingData['ready_time'] ?? '';
    
    // GET DISCOUNT INFORMATION (same as index.php)
    $discountAmount = floatval($bookingData['discount_amount'] ?? 0);
    $promoCode = $bookingData['promo_code'] ?? '';
    $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
    
    // Calculate totals with discount handling
    $currentTotal = floatval($bookingData['quote_total'] ?? 0);
    $originalTotal = $hasDiscount ? floatval($bookingData['original_total'] ?? ($currentTotal + $discountAmount)) : $currentTotal;
    
    // Format service location
    $serviceLocation = trim(implode(', ', array_filter([
        $bookingData['street_address'] ?? '',
        $bookingData['city'] ?? '',
        $bookingData['province'] ?? '',
        $bookingData['postal_code'] ?? ''
    ])));
    
    // Format dates and times
    $eventDateFormatted = '';
    if ($eventDate) {
        try {
            $eventDateFormatted = date('Y-m-d', strtotime($eventDate));
        } catch (Exception $e) {
            $eventDateFormatted = $eventDate;
        }
    }
    
    $readyTimeFormatted = '';
    if ($readyTime) {
        try {
            $readyTimeFormatted = date('g:i A', strtotime($readyTime));
        } catch (Exception $e) {
            $readyTimeFormatted = $readyTime;
        }
    }
    
    $html = '<div class="booking-details">
               <h3>Booking Details</h3>';
               
    // // ADD DISCOUNT SECTION IF APPLICABLE (like index.php)
    // if ($hasDiscount) {
    //     $savings = $originalTotal - $currentTotal;
    //     $savingsPercentage = $originalTotal > 0 ? round(($savings / $originalTotal) * 100, 1) : 0;
        
    //     $html .= '<div style="background: #d1ecf1; padding: 15px; border-radius: 6px; margin: 15px 0; text-align: center; color: #0c5460;">
    //                 <h4 style="margin: 0 0 8px 0;">🎉 Promotional Discount Applied!</h4>
    //                 <div style="margin: 8px 0;">
    //                     <strong>Coupon Code Used:</strong><br>
    //                     <span style="background: #ffc107; color: #212529; padding: 4px 8px; border-radius: 4px; font-weight: bold;">' . htmlspecialchars($promoCode) . '</span>
    //                 </div>
    //                 <div style="margin: 8px 0;">
    //                     <div>Original Total: <span style="text-decoration: line-through;">$' . number_format($originalTotal, 2) . ' CAD</span></div>
    //                     <div style="color: #dc3545; font-weight: bold;">Discount Applied: -$' . number_format($discountAmount, 2) . ' CAD</div>
    //                     <div style="font-size: 18px; font-weight: bold;">Final Total: $' . number_format($currentTotal, 2) . ' CAD</div>
    //                 </div>
    //                 <div style="color: #28a745; font-weight: bold;">
    //                     💰 You saved $' . number_format($discountAmount, 2) . ' with your promotional code!
    //                 </div>
    //               </div>';
    // }
    
    $html .= '<div class="detail-row">
               <span class="detail-label">Booking ID:</span>
               <span class="detail-value">' . htmlspecialchars($bookingId) . '</span>
             </div>
             
             <div class="detail-row">
               <span class="detail-label">Name:</span>
               <span class="detail-value">' . htmlspecialchars($clientName ?: 'Not specified') . '</span>
             </div>
             
             <div class="detail-row">
               <span class="detail-label">Email:</span>
               <span class="detail-value">' . htmlspecialchars($clientEmail ?: 'Not specified') . '</span>
             </div>
             
             <div class="detail-row">
               <span class="detail-label">Phone:</span>
               <span class="detail-value">' . htmlspecialchars($clientPhone ?: 'Not specified') . '</span>
             </div>
             
             <div class="detail-row">
               <span class="detail-label">Artist:</span>
               <span class="detail-value">' . htmlspecialchars($artistType) . '</span>
             </div>
             
             <div class="detail-row">
               <span class="detail-label">Service Type:</span>
               <span class="detail-value">' . htmlspecialchars($serviceType) . '</span>
             </div>
             
             <div class="detail-row">
               <span class="detail-label">Status:</span>
               <span class="status-badge">PAID & CONFIRMED</span>
             </div>';

    if ($eventDateFormatted) {
        $html .= '<div class="detail-row">
                    <span class="detail-label">Event Date:</span>
                    <span class="detail-value">' . htmlspecialchars($eventDateFormatted) . '</span>
                  </div>';
    }
    
    if ($readyTimeFormatted) {
        $html .= '<div class="detail-row">
                    <span class="detail-label">Ready Time:</span>
                    <span class="detail-value">' . htmlspecialchars($readyTimeFormatted) . '</span>
                  </div>';
    }
    
    if ($serviceLocation) {
        $html .= '<div class="detail-row">
                    <span class="detail-label">Service Location:</span>
                    <span class="detail-value">' . htmlspecialchars($serviceLocation) . '</span>
                  </div>';
    }
    
    // $html .= '<div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; color: #28a745; border-top: 1px solid #28a745; padding-top: 8px;">
    //         <span>Total Amount:</span>
    //         <span>$' . number_format($currentTotal, 2) . '</span>
    //     </div>';
            
    $html .= '</div>';
    
    return $html;
}
function generateServicesBreakdownForSuccess($bookingData) {
    if (!$bookingData) return '';
    
    // Get discount information (keep existing variable names)
    $discountAmount = floatval($bookingData['discount_amount'] ?? 0);
    $promoCode = $bookingData['promo_code'] ?? '';
    $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
    
    // Get service details
    $artistType = $bookingData['artist_type'] ?? 'team';
    $serviceType = $bookingData['service_type'] ?? 'Bridal';
    $isAnum = stripos($artistType, 'anum') !== false;
    
    // CORRECTED DEPOSIT RATE CALCULATION (keep existing logic)
    $depositRate = 0.3; // Default 30%
    $depositLabel = '30%';
    
    if (stripos($serviceType, 'non-bridal') !== false || stripos($serviceType, 'photoshoot') !== false) {
        $depositRate = 0.5; // 50% for non-bridal
        $depositLabel = '50%';
    }
    
// ADD THIS MISSING SECTION - Populate $services array with detailed breakdown
$services = [];

if (stripos($serviceType, 'non-bridal') !== false || stripos($serviceType, 'photoshoot') !== false) {
    // === NON-BRIDAL SERVICES DETAILED BREAKDOWN ===
    $nbBoth = intval($bookingData['nbBoth'] ?? $bookingData['nb_both'] ?? 0);
    $nbMakeup = intval($bookingData['nbMakeup'] ?? $bookingData['nb_makeup'] ?? 0);
    $nbHair = intval($bookingData['nbHair'] ?? $bookingData['nb_hair'] ?? 0);
    $nbExtensions = intval($bookingData['nbExtensions'] ?? $bookingData['nb_extensions'] ?? 0);
    $nbJewelry = intval($bookingData['nbJewelry'] ?? $bookingData['nb_jewelry'] ?? 0);
    $nbAirbrush = intval($bookingData['nbAirbrush'] ?? $bookingData['nb_airbrush'] ?? 0);
    $nbCount = intval($bookingData['nbCount'] ?? $bookingData['nb_count'] ?? 0);
    $nbEveryoneBoth = $bookingData['nbEveryoneBoth'] ?? $bookingData['nb_everyone_both'] ?? '';
    
    if ($nbEveryoneBoth === 'Yes' && $nbCount > 0) {
        $pricePerPerson = $isAnum ? 250 : 200;
        $totalPrice = $nbCount * $pricePerPerson;
        $services[] = ['name' => "Non-Bridal Hair & Makeup (" . ($isAnum ? 'Anum' : 'Team') . ") x {$nbCount}", 'price' => $totalPrice];
    } else {
        if ($nbBoth > 0) {
            $pricePerPerson = $isAnum ? 250 : 200;
            $totalPrice = $nbBoth * $pricePerPerson;
            $services[] = ['name' => "Non-Bridal Hair & Makeup (" . ($isAnum ? 'Anum' : 'Team') . ") x {$nbBoth}", 'price' => $totalPrice];
        }
        if ($nbMakeup > 0) {
            $pricePerPerson = $isAnum ? 140 : 110;
            $totalPrice = $nbMakeup * $pricePerPerson;
            $services[] = ['name' => "Non-Bridal Makeup Only (" . ($isAnum ? 'Anum' : 'Team') . ") x {$nbMakeup}", 'price' => $totalPrice];
        }
        if ($nbHair > 0) {
            $pricePerPerson = $isAnum ? 130 : 110;
            $totalPrice = $nbHair * $pricePerPerson;
            $services[] = ['name' => "Non-Bridal Hair Only (" . ($isAnum ? 'Anum' : 'Team') . ") x {$nbHair}", 'price' => $totalPrice];
        }
    }
    
    // Non-bridal add-ons
    if ($nbExtensions > 0) {
        $services[] = ['name' => "Hair Extensions Installation x {$nbExtensions}", 'price' => $nbExtensions * 20];
    }
    if ($nbJewelry > 0) {
        $services[] = ['name' => "Jewelry/Dupatta Setting x {$nbJewelry}", 'price' => $nbJewelry * 20];
    }
    if ($nbAirbrush > 0) {
        $services[] = ['name' => "Airbrush Makeup x {$nbAirbrush}", 'price' => $nbAirbrush * 50];
    }
    
    // Fallback if no detailed data
    if (empty($services)) {
        $services[] = ['name' => 'Non-Bridal Makeup & Hair (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => ($isAnum ? 250 : 200)];
    }
    
} elseif (stripos($serviceType, 'semi') !== false) {
    // === SEMI BRIDAL SERVICES ===
    $brideService = $bookingData['bride_service'] ?? $bookingData['brideservice'] ?? 'Both Hair & Makeup';
    if (stripos($brideService, 'hair') !== false && stripos($brideService, 'makeup') !== false) {
        $price = $isAnum ? 350 : 280;
        $services[] = ['name' => 'Semi Bridal Makeup & Hair (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    } elseif (stripos($brideService, 'hair only') !== false) {
        $price = $isAnum ? 175 : 140;
        $services[] = ['name' => 'Semi Bridal Hair Only (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    } elseif (stripos($brideService, 'makeup only') !== false) {
        $price = $isAnum ? 225 : 180;
        $services[] = ['name' => 'Semi Bridal Makeup Only (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    } else {
        $price = $isAnum ? 350 : 280;
        $services[] = ['name' => 'Semi Bridal Makeup & Hair (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    }
    
    // Semi-bridal add-ons and bridal party services
    if (($bookingData['bride_veil_setting'] ?? $bookingData['brideveilsetting'] ?? '') === 'Yes') {
        $services[] = ['name' => 'Jewelry & Dupatta/Veil Setting', 'price' => 50];
    }
    if (($bookingData['bride_extensions'] ?? $bookingData['brideextensions'] ?? '') === 'Yes') {
        $services[] = ['name' => 'Hair Extensions Installation', 'price' => 30];
    }
    
    // Add bridal party services for semi-bridal
    $bpHM = intval($bookingData['bpHM'] ?? $bookingData['bp_hair_makeup'] ?? 0);
    $bpM = intval($bookingData['bpM'] ?? $bookingData['bp_makeup_only'] ?? 0);
    $bpH = intval($bookingData['bpH'] ?? $bookingData['bp_hair_only'] ?? 0);
    $bpSetting = intval($bookingData['bpSetting'] ?? $bookingData['bp_setting'] ?? 0);
    $bpHExtensions = intval($bookingData['bpHExtensions'] ?? $bookingData['bp_extensions'] ?? 0);
    $bpAirbrush = intval($bookingData['bpAirbrush'] ?? $bookingData['bp_airbrush'] ?? 0);
    
    if ($bpHM > 0) $services[] = ['name' => "Bridal Party Hair & Makeup x {$bpHM}", 'price' => $bpHM * 200];
    if ($bpM > 0) $services[] = ['name' => "Bridal Party Makeup Only x {$bpM}", 'price' => $bpM * 100];
    if ($bpH > 0) $services[] = ['name' => "Bridal Party Hair Only x {$bpH}", 'price' => $bpH * 100];
    if ($bpSetting > 0) $services[] = ['name' => "Bridal Party Dupatta/Veil Setting x {$bpSetting}", 'price' => $bpSetting * 20];
    if ($bpHExtensions > 0) $services[] = ['name' => "Bridal Party Hair Extensions x {$bpHExtensions}", 'price' => $bpHExtensions * 20];
    if ($bpAirbrush > 0) $services[] = ['name' => "Bridal Party Airbrush Makeup x {$bpAirbrush}", 'price' => $bpAirbrush * 50];
    
} else {
    // === BRIDAL SERVICES (DEFAULT) ===
    $brideService = $bookingData['bride_service'] ?? $bookingData['brideservice'] ?? 'Both Hair & Makeup';
    if (stripos($brideService, 'hair') !== false && stripos($brideService, 'makeup') !== false) {
        $price = $isAnum ? 450 : 360;
        $services[] = ['name' => 'Bridal Makeup & Hair (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    } elseif (stripos($brideService, 'hair only') !== false) {
        $price = $isAnum ? 200 : 160;
        $services[] = ['name' => 'Bridal Hair Only (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    } elseif (stripos($brideService, 'makeup only') !== false) {
        $price = $isAnum ? 275 : 220;
        $services[] = ['name' => 'Bridal Makeup Only (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    } else {
        $price = $isAnum ? 450 : 360;
        $services[] = ['name' => 'Bridal Makeup & Hair (' . ($isAnum ? 'Anum' : 'Team') . ')', 'price' => $price];
    }
    
    // Trial services
    $needsTrial = $bookingData['needs_trial'] ?? $bookingData['needstrial'] ?? '';
    $trialService = $bookingData['trial_service'] ?? $bookingData['trialservice'] ?? '';
    
    if ($needsTrial === 'Yes' && !empty($trialService)) {
        if (stripos($trialService, 'hair') !== false && stripos($trialService, 'makeup') !== false) {
            $price = $isAnum ? 250 : 200;
            $services[] = ['name' => 'Bridal Trial (Hair & Makeup)', 'price' => $price];
        } elseif (stripos($trialService, 'hair only') !== false) {
            $price = $isAnum ? 150 : 120;
            $services[] = ['name' => 'Bridal Trial (Hair Only)', 'price' => $price];
        } elseif (stripos($trialService, 'makeup only') !== false) {
            $price = $isAnum ? 150 : 120;
            $services[] = ['name' => 'Bridal Trial (Makeup Only)', 'price' => $price];
        }
    }
    
    // Bridal add-ons
    if (($bookingData['bride_veil_setting'] ?? $bookingData['brideveilsetting'] ?? '') === 'Yes') {
        $services[] = ['name' => 'Jewelry & Dupatta/Veil Setting', 'price' => 50];
    }
    if (($bookingData['bride_extensions'] ?? $bookingData['brideextensions'] ?? '') === 'Yes') {
        $services[] = ['name' => 'Hair Extensions Installation', 'price' => 30];
    }
    
    // Bridal party services
    $bpHM = intval($bookingData['bpHM'] ?? $bookingData['bp_hair_makeup'] ?? 0);
    $bpM = intval($bookingData['bpM'] ?? $bookingData['bp_makeup_only'] ?? 0);
    $bpH = intval($bookingData['bpH'] ?? $bookingData['bp_hair_only'] ?? 0);
    $bpSetting = intval($bookingData['bpSetting'] ?? $bookingData['bp_setting'] ?? 0);
    $bpHExtensions = intval($bookingData['bpHExtensions'] ?? $bookingData['bp_extensions'] ?? 0);
    $bpAirbrush = intval($bookingData['bpAirbrush'] ?? $bookingData['bp_airbrush'] ?? 0);
    
    if ($bpHM > 0) $services[] = ['name' => "Bridal Party Hair & Makeup x {$bpHM}", 'price' => $bpHM * 200];
    if ($bpM > 0) $services[] = ['name' => "Bridal Party Makeup Only x {$bpM}", 'price' => $bpM * 100];
    if ($bpH > 0) $services[] = ['name' => "Bridal Party Hair Only x {$bpH}", 'price' => $bpH * 100];
    if ($bpSetting > 0) $services[] = ['name' => "Bridal Party Dupatta/Veil Setting x {$bpSetting}", 'price' => $bpSetting * 20];
    if ($bpHExtensions > 0) $services[] = ['name' => "Bridal Party Hair Extensions x {$bpHExtensions}", 'price' => $bpHExtensions * 20];
    if ($bpAirbrush > 0) $services[] = ['name' => "Bridal Party Airbrush Makeup x {$bpAirbrush}", 'price' => $bpAirbrush * 50];
}

// Add travel fee
$region = $bookingData['region'] ?? '';
$travelFee = 25; // Default
if ($region === 'Toronto/GTA') {
    $travelFee = 25;
} elseif (strpos($region, 'Further Out') !== false) {
    $travelFee = 120;
} elseif (strpos($region, 'Moderate Distance') !== false) {
    $travelFee = 80;
} elseif (strpos($region, 'Immediate Neighbors') !== false) {
    $travelFee = 40;
}
$services[] = ['name' => 'Travel Fee', 'price' => $travelFee];

// This part stays the same as before
    
    // STEP 1: Calculate services subtotal (before HST, before discount)
    $servicesSubtotal = 0;
    foreach ($services as $service) {
        $servicesSubtotal += $service['price'];
    }
    
    // STEP 2: CORRECTED calculation using existing variable names
    if ($hasDiscount) {
        // Calculate what the total would have been with HST but before discount
        $originalTotal = $servicesSubtotal * 1.13; // Services + HST = "Total Before Discounts"
        
        // Apply discount to get current total
        $currentTotal = $originalTotal - $discountAmount; // Final total after discount
        
        // Back-calculate subtotal and HST from current total
        $newSubtotal = $currentTotal / 1.13;
        $newHst = $currentTotal - $newSubtotal;
    } else {
        // No discount case
        $currentTotal = $servicesSubtotal * 1.13; // Services + HST
        $originalTotal = $currentTotal; // Same as current total
        $newSubtotal = $servicesSubtotal;
        $newHst = $currentTotal - $newSubtotal;
    }
    
    // STEP 3: Calculate correct deposit and payment amounts (keep existing variable names)
    $correctDepositAmount = $currentTotal * $depositRate;
    $amountPaid = floatval($bookingData['amount_paid'] ?? $correctDepositAmount);
    
    $html = '<div class="services-breakdown">
               <h3>Services Breakdown</h3>';
    
    // Display individual services (existing logic)
    foreach ($services as $service) {
        $html .= '<div class="service-item">
                    <span class="service-name">' . htmlspecialchars($service['name']) . '</span>
                    <span class="service-price">$' . number_format($service['price'], 2) . '</span>
                  </div>';
    }
    
    $html .= '<hr style="margin: 12px 0; border: 1px solid #dee2e6;">';
    
    // CORRECTED breakdown using existing variable names
    if ($hasDiscount) {
        $html .= '
        <div class="service-item" style="padding: 4px 0;">
            <span class="service-name">Total Before Discounts:</span>
            <span class="service-price">$' . number_format($originalTotal, 2) . '</span>
        </div>
        <div class="service-item" style="padding: 4px 0; color: #dc3545; font-weight: 600;">
            <span class="service-name">You saved:</span>
            <span class="service-price">-$' . number_format($discountAmount, 2) . '</span>
        </div>
        <div class="service-item" style="padding: 4px 0;">
            <span class="service-name">Subtotal:</span>
            <span class="service-price">$' . number_format($newSubtotal, 2) . '</span>
        </div>
        <div class="service-item" style="padding: 4px 0;">
            <span class="service-name">HST (13%):</span>
            <span class="service-price">$' . number_format($newHst, 2) . '</span>
        </div>
        <div class="service-item total-row" style="border-top: 1px solid #28a745; padding-top: 8px; font-weight: 600; font-size: 16px;">
            <span class="service-name">Total After Discount:</span>
            <span class="service-price">$' . number_format($currentTotal, 2) . ' CAD</span>
        </div>';
    } else {
        // No discount - show normal breakdown using existing variable names
        $html .= '
        <div class="service-item" style="padding: 4px 0;">
            <span class="service-name">Subtotal:</span>
            <span class="service-price">$' . number_format($newSubtotal, 2) . '</span>
        </div>
        <div class="service-item" style="padding: 4px 0;">
            <span class="service-name">HST (13%):</span>
            <span class="service-price">$' . number_format($newHst, 2) . '</span>
        </div>
        <div class="service-item total-row" style="border-top: 1px solid #28a745; padding-top: 8px; font-weight: 600; font-size: 16px;">
            <span class="service-name">Total Amount:</span>
            <span class="service-price">$' . number_format($currentTotal, 2) . ' CAD</span>
        </div>';
    }
    
    // CORRECTED PAYMENT INFORMATION (keep existing variable names)
    if ($amountPaid > 0) {
        $html .= '<div class="service-item" style="background: #e7f3ff; border-top: 1px solid #28a745; font-weight: 600; color: #0056b3; padding: 8px 0;">
                    <span class="service-name">Amount Paid (' . $depositLabel . ' Deposit):</span>
                    <span class="service-price">$' . number_format($amountPaid, 2) . ' CAD</span>
                  </div>';
        
        // CORRECTED: Show savings on deposit if discount was applied
        if ($hasDiscount) {
            $originalExpectedDeposit = $originalTotal * $depositRate;  // What deposit would have been before discount
            $newExpectedDeposit = $currentTotal * $depositRate;       // What deposit should be after discount
            $savedOnDeposit = $originalExpectedDeposit - $newExpectedDeposit;  // CORRECT calculation
            
            if ($savedOnDeposit > 0) {
                $html .= '<div class="service-item" style="background: #d4edda; padding: 4px 0; color: #155724; font-weight: 500;">
                            <span class="service-name">You Saved on Deposit:</span>
                            <span class="service-price">$' . number_format($savedOnDeposit, 2) . ' CAD</span>
                          </div>';
            }
        }
        
        $remainingBalance = $currentTotal - $amountPaid;
        if ($remainingBalance > 0) {
            $html .= '<div class="service-item" style="color: #dc3545; font-weight: 500; padding: 4px 0;">
                        <span class="service-name">Remaining Balance (due on event day):</span>
                        <span class="service-price">$' . number_format($remainingBalance, 2) . ' CAD</span>
                      </div>';
        }
    }
    
    $html .= '</div>';
    
    return $html;
}
echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Payment Result - Looks By Anum</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
            .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
            .success-icon { font-size: 64px; margin-bottom: 20px; }
            .warning-icon { font-size: 64px; margin-bottom: 20px; }
            h1 { margin-bottom: 16px; }
            .success h1 { color: #28a745; }
            .error h1 { color: #dc3545; }
            p { color: #666; line-height: 1.6; margin-bottom: 24px; }
            .booking-id { background: #f8f9fa; padding: 12px; border-radius: 6px; font-family: monospace; margin: 20px 0; }
            .btn { display: inline-block; padding: 12px 24px; background: #111; color: white; text-decoration: none; border-radius: 6px; margin: 10px; transition: all 0.2s; }
            .btn:hover { background: #333; }
            .btn-success { background: #28a745; }
            .btn-success:hover { background: #218838; }
            .info-box { background: #e7f3ff; border: 1px solid #b8daff; border-radius: 6px; padding: 16px; margin: 20px 0; }
            .error-box { background: #fff5f5; border: 1px solid #fed7d7; border-radius: 6px; padding: 16px; margin: 20px 0; }
            .contract-section { background: #f8f9fa; border: 2px solid #28a745; border-radius: 8px; padding: 20px; margin: 20px 0; flex-direction: column; align-items: center;}
            .contract-icon { font-size: 48px; margin-bottom: 10px; }
            .services-breakdown { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
            .services-breakdown h3 { margin-top: 0; color: #333; text-align: center; }
            .service-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
            .service-item:last-child { border-bottom: none; }
            .service-item .service-name { color: #333; }
            .service-item .service-price { color: #555; font-weight: 600; }
            .total-row { border-top: 2px solid #28a745; margin-top: 10px; padding-top: 10px; font-weight: bold; font-size: 16px; }
            .booking-details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
            .booking-details h3 { margin-top: 0; color: #333; text-align: center; }
            .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
            .detail-row:last-child { border-bottom: none; }
            .detail-label { font-weight: 600; color: #333; }
            .detail-value { color: #555; }
            .status-badge { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="container">
            ' . (isset($success) && $success ? 
                '<div class="success"><div class="success-icon">&#127881;</div><h1>Booking Confirmed!</h1></div>' :
                '<div class="error"><div class="warning-icon">&#10060;</div><h1>Payment Processing Issue</h1></div>'
            ) . '
            
            ' . (isset($success) && $success ? 
                '<p><strong>Success!</strong> Thank you for booking with us. We will contact you within 24 hours to confirm all details.</p>' :
                '<p><strong>Payment Received</strong> but there was an issue updating your booking details in our system. Our technical team has been notified and will resolve this immediately.</p>'
            ) . '
            
            ' . '';

// ADD BOOKING DETAILS AND SERVICES BREAKDOWN SECTIONS HERE (only for successful payments)
if (isset($success) && $success && isset($bookingData) && $bookingData) {
    echo generateBookingDetailsForSuccess($bookingData);
    echo generateServicesBreakdownForSuccess($bookingData);
}

echo '            ' . (isset($success) && $success && isset($finalContractPath) && $finalContractPath ? 
                '<div class="contract-section">
                    <div class="contract-icon">&#128196;</div>
                    <h3 style="margin-top: 0; color: #28a745;">Your Booking Contract (' . $contractType . ')</h3>
                    <p>Your official contract has been generated. Please download and keep this for your records.</p>
                    <a href="' . htmlspecialchars($finalContractPath) . '" target="_blank" class="btn btn-success">
                        Download Contract
                    </a>
                    <p style="font-size: 12px; color: #666; margin-top: 10px;">
                        Contract will open in a new window. You can print or save it for your records.<br>
                        
                    </p>
                </div>' : ''
            ) . '
            
            ' . (isset($success) && $success ? 
                '<div class="info-box">
                    <strong>✅ Your booking is confirmed!<</strong><br>
                    • Payment processed successfully<br>
                    • Contract generated and ready for download<br>
                    • Confirmation email will be sent<br>
                    • We will contact you within 24 hours
                </div>' :
                '<div class="error-box">
                    <strong>Technical Issue Detected</strong><br>
                    • Your payment was processed successfully<br>
                    • Issue occurred updating booking details<br>
                    • Our team has been automatically notified<br>
                    • We will contact you within 2 hours to confirm<br>
                    • Your deposit secures your booking date
                </div>'
            ) . '
            
            ' . (!isset($success) || !$success ? 
                '<div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 12px; margin: 20px 0; font-size: 14px;">
                    <strong>Immediate Contact:</strong> If you need immediate assistance, please call us at <a href="tel:+14162751719">(416) 275-1719</a> with your booking ID.
                </div>' : ''
            ) . '
            
            
            <a href="https://looksbyanum.com" class="btn">Return to Website</a>
        </div>
    </body>
    </html>';
    exit;
}

// Handle payment cancellation
if (isset($_GET['payment_cancel'])) {
    $bookingId = $_GET['booking_id'] ?? '';
    
    if ($bookingId) {
        unset($_SESSION['pending_booking_' . $bookingId]);
        debugLog("Cleaned up session data for cancelled payment: " . $bookingId);
    }
    
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Payment Cancelled - Looks By Anum</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
            .cancel-icon { font-size: 64px; margin-bottom: 20px; }
            h1 { color: #dc3545; margin-bottom: 16px; }
            p { color: #666; line-height: 1.6; margin-bottom: 24px; }
            .btn { display: inline-block; padding: 12px 24px; background: #111; color: white; text-decoration: none; border-radius: 6px; margin: 10px; display: flex; align-items: center; flex-direction: column;}
            .btn-secondary { background: #6c757d; }
            .info-box { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 16px; margin: 20px 0; text-align: center;}
        </style>
    </head>
    <body>
        <div class="container">
            <div class="cancel-icon">❌</div>
            <h1>Payment Cancelled</h1>
            <p>Your payment was cancelled and your booking was not saved to our system.</p>
            <div class="info-box">
                <strong>⚠️ Booking Not Confirmed</strong><br>
                Since payment was not completed, your booking details were not saved. You will need to go through the booking process again to secure your date.
            </div>
            <p>No worries! You can restart the booking process or contact us for assistance.</p>
            <a href="tel:+14162751719 " class="btn">📞 Call Us</a>
            <a href="mailto:info@looksbyanum.com" class="btn btn-secondary">✉️ Email Us</a>
            <br>
            <a href="https://looksbyanum.com" class="btn btn-secondary">Return to Website</a>
        </div>
    </body>
    </html>';
    exit;
}

// Handle Stripe payment creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_stripe_payment') {
    header('Content-Type: application/json');
    
    try {
        $requiredFields = ['amount', 'currency', 'booking_id', 'client_email', 'client_name'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            echo json_encode(['success' => false, 'message' => 'Missing payment fields: ' . implode(', ', $missingFields)]);
            exit;
        }
        
        $paymentData = [
            'amount' => floatval($_POST['amount']),
            'currency' => strtolower($_POST['currency']),
            'booking_id' => $_POST['booking_id'],
            'client_email' => $_POST['client_email'],
            'client_name' => $_POST['client_name'],
            'description' => $_POST['description'] ?? 'Makeup Service Deposit'
        ];
        
        $currentDomain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $currentPath = dirname($_SERVER['REQUEST_URI']);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'payment_method_types[]' => 'card',
            'line_items[0][price_data][currency]' => $paymentData['currency'],
            'line_items[0][price_data][product_data][name]' => $paymentData['description'],
            'line_items[0][price_data][product_data][description]' => 'Booking ID: ' . $paymentData['booking_id'],
            'line_items[0][price_data][unit_amount]' => intval($paymentData['amount'] * 100),
            'line_items[0][quantity]' => 1,
            'mode' => 'payment',
            'allow_promotion_codes' => 'true',
            'success_url' => $currentDomain . $currentPath . '/custom-quote.php?payment_success=1&session_id={CHECKOUT_SESSION_ID}&booking_id=' . urlencode($paymentData['booking_id']),
            'cancel_url' => $currentDomain . $currentPath . '/custom-quote.php?payment_cancel=1&booking_id=' . urlencode($paymentData['booking_id']),
            'customer_email' => $paymentData['client_email'],
            'metadata[booking_id]' => $paymentData['booking_id'],
            'metadata[client_name]' => $paymentData['client_name']
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripeConfig['secret_key'],
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Curl error: ' . $error);
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            throw new Exception('Stripe API error: ' . ($errorData['error']['message'] ?? 'Unknown error'));
        }
        
        $sessionData = json_decode($response, true);
        
        if (!$sessionData || !isset($sessionData['url'])) {
            throw new Exception('Invalid response from Stripe API');
        }
        
        $isTestMode = strpos($stripeConfig['secret_key'], 'sk_test_') === 0;
        
        debugLog("Created Stripe checkout session: " . $sessionData['id'] . ($isTestMode ? ' (TEST MODE)' : ''));
        debugLog("Payment will trigger database save and contract generation on success for booking: " . $paymentData['booking_id']);
        
        echo json_encode([
            'success' => true,
            'payment_url' => $sessionData['url'],
            'session_id' => $sessionData['id'],
            'payment_data' => $paymentData,
            'test_mode' => $isTestMode,
            'note' => 'Database and contract will be generated on successful payment'
        ]);
        
    } catch (Exception $e) {
        debugLog("Stripe payment creation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Payment creation failed: ' . $e->getMessage()]);
    }
    exit;
}
// Add this for testing promotional codes in custom-quote.php
if (isset($_GET['test_promo']) && isset($_GET['code'])) {
    $testCode = $_GET['code'];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/promotion_codes?code=' . urlencode($testCode));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripeConfig['secret_key']
    ]);
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    
    echo '<pre>' . json_encode($data, JSON_PRETTY_PRINT) . '</pre>';
    exit;
}
// Handle booking preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_booking') {
    header('Content-Type: application/json');
    
    debugLog("Booking preparation request received at " . date('Y-m-d H:i:s'));
    debugLog("Will store in session, not database (until payment success with contract generation)");
    
    $requiredFields = ['client_name', 'client_email', 'artist_type', 'booking_id'];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        debugLog("Missing required fields: " . implode(', ', $missingFields));
        echo json_encode(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missingFields)]);
        exit;
    }
    
    try {
        $bookingData = [
            'booking_id' => $_POST['booking_id'] ?? '',
            'client_name' => $_POST['client_name'] ?? '',
            'client_email' => $_POST['client_email'] ?? '',
            'client_phone' => $_POST['client_phone'] ?? '',
            'artist_type' => $_POST['artist_type'] ?? '',
            'booking_type' => $_POST['booking_type'] ?? 'service',
            'event_date' => !empty($_POST['event_date']) ? $_POST['event_date'] : '',
            'trial_date' => !empty($_POST['trial_date']) ? $_POST['trial_date'] : '',
            'ready_time' => !empty($_POST['ready_time']) ? $_POST['ready_time'] : '',
            'street_address' => $_POST['street_address'] ?? '',
            'city' => $_POST['city'] ?? '',
            'province' => $_POST['province'] ?? '',
            'postal_code' => $_POST['postal_code'] ?? '',
            'service_type' => $_POST['service_type'] ?? '',
            'region' => $_POST['region'] ?? '',
            'quote_total' => floatval($_POST['quote_total'] ?? 0),
            'deposit_amount' => floatval($_POST['deposit_amount'] ?? 0),
            'client_signature' => $_POST['client_signature'] ?? null,
            'terms_accepted' => $_POST['terms_accepted'] ?? false,
            'signature_date' => $_POST['signature_date'] ?? null,
            
            // ADD THESE MISSING FIELDS FOR DETAILED BREAKDOWN:
            'bride_service' => $_POST['bride_service'] ?? '',
            'trial_service' => $_POST['trial_service'] ?? '',
            'bride_veil_setting' => $_POST['bride_veil_setting'] ?? '',
            'bride_extensions' => $_POST['bride_extensions'] ?? '',
            'needs_trial' => $_POST['needs_trial'] ?? '',
            
            // Bridal party services
            'bpHM' => intval($_POST['bpHM'] ?? 0),
            'bpM' => intval($_POST['bpM'] ?? 0),
            'bpH' => intval($_POST['bpH'] ?? 0),
            'bpSetting' => intval($_POST['bpSetting'] ?? 0),
            'bpHExtensions' => intval($_POST['bpHExtensions'] ?? 0),
            'bpAirbrush' => intval($_POST['bpAirbrush'] ?? 0),
            
            // Non-bridal services
            'nbCount' => intval($_POST['nbCount'] ?? 0),
            'nbBoth' => intval($_POST['nbBoth'] ?? 0),
            'nbMakeup' => intval($_POST['nbMakeup'] ?? 0),
            'nbHair' => intval($_POST['nbHair'] ?? 0),
            'nbExtensions' => intval($_POST['nbExtensions'] ?? 0),
            'nbJewelry' => intval($_POST['nbJewelry'] ?? 0),
            'nbAirbrush' => intval($_POST['nbAirbrush'] ?? 0),
        ];
        if (!empty($bookingData['ready_time'])) {
            try {
                $readyTime = new DateTime($bookingData['ready_time']);
                $appointmentDateTime = clone $readyTime;
                $appointmentDateTime->sub(new DateInterval('PT2H'));
                $bookingData['appointment_time'] = $appointmentDateTime->format('g:i A');
            } catch (Exception $e) {
                $bookingData['appointment_time'] = '';
            }
        } else {
            $bookingData['appointment_time'] = '';
        }
        
        $_SESSION['pending_booking_' . $bookingData['booking_id']] = $bookingData;
        
        debugLog("Booking data stored in session (not DB yet): " . $bookingData['booking_id']);
        debugLog("Session key: pending_booking_" . $bookingData['booking_id']);
        
        echo json_encode([
            'success' => true, 
            'message' => "Booking prepared for payment!", 
            'booking_id' => $bookingData['booking_id'],
            'operation' => 'prepared',
            'note' => 'Data will be saved to database and contract generated only after successful payment'
        ]);
        
    } catch (Exception $e) {
        debugLog("General error during booking submission: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================================================
// DATABASE STRUCTURE FIX (Keep existing functionality)
// =============================================================================

if (isset($_GET['fix_database']) && $_GET['fix_database'] === 'structure') {
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Check if columns exist and add missing ones (including contract_path)
        $alterStatements = [];
        
        $checkColumns = [
            'client_name' => "ADD COLUMN client_name VARCHAR(255) NOT NULL DEFAULT ''",
            'client_email' => "ADD COLUMN client_email VARCHAR(255) NOT NULL DEFAULT ''",
            'client_phone' => "ADD COLUMN client_phone VARCHAR(50) NOT NULL DEFAULT ''",
            'artist_type' => "ADD COLUMN artist_type VARCHAR(50) NOT NULL DEFAULT ''",
            'booking_type' => "ADD COLUMN booking_type VARCHAR(50) NOT NULL DEFAULT ''",
            'trial_date' => "ADD COLUMN trial_date DATE NULL",
            'street_address' => "ADD COLUMN street_address TEXT NULL",
            'city' => "ADD COLUMN city VARCHAR(100) NULL",
            'province' => "ADD COLUMN province VARCHAR(10) NULL",
            'postal_code' => "ADD COLUMN postal_code VARCHAR(10) NULL",
            'quote_total' => "ADD COLUMN quote_total DECIMAL(10,2) NULL",
            'deposit_amount' => "ADD COLUMN deposit_amount DECIMAL(10,2) NULL",
            'status' => "ADD COLUMN status VARCHAR(50) DEFAULT 'pending'",
            'stripe_session_id' => "ADD COLUMN stripe_session_id VARCHAR(255) NULL",
            'contract_path' => "ADD COLUMN contract_path VARCHAR(500) NULL",
            'client_signature' => "ADD COLUMN client_signature LONGTEXT NULL",
            'terms_accepted' => "ADD COLUMN terms_accepted BOOLEAN DEFAULT FALSE",
            'signature_date' => "ADD COLUMN signature_date DATETIME NULL"
        ];
        
        foreach ($checkColumns as $column => $alterSQL) {
            try {
                $checkStmt = $pdo->query("SHOW COLUMNS FROM {$bookingTable} LIKE '{$column}'");
                if ($checkStmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE {$bookingTable} {$alterSQL}");
                    $alterStatements[] = "Added column: {$column}";
                }
            } catch (PDOException $e) {
                $alterStatements[] = "Failed to add {$column}: " . $e->getMessage();
            }
        }
        
        echo '<!DOCTYPE html>
        <html>
        <head><title>Database Structure Fix</title>
        <style>body{font-family:Arial;padding:20px;}</style></head>
        <body>
        <h2>Database Structure Fix Results</h2>';
        
        if (empty($alterStatements)) {
            echo '<p style="color: green;">✅ Database structure is already up to date!</p>';
        } else {
            echo '<p>Database modifications:</p><ul>';
            foreach ($alterStatements as $statement) {
                echo '<li>' . htmlspecialchars($statement) . '</li>';
            }
            echo '</ul>';
        }
        
        echo '<h3>Enhanced Contract System Status:</h3>';
        echo '<ul>';
        echo '<li>✅ Contract Path Column: Ready for storing contract file paths</li>';
        echo '<li>✅ Client Signature Column: Stores digital signatures as base64 data</li>';
        echo '<li>✅ Terms Accepted Column: Tracks user consent to terms</li>';
        echo '<li>✅ Signature Date Column: Records when signature was captured</li>';
        echo '<li>✅ Auto FPDF Download: Will download PDF library automatically</li>';
        echo '<li>✅ HTML Fallback: If PDF fails, will generate HTML contract with signature</li>';
        echo '<li>✅ Professional Layout: Matches your contract template with digital signature</li>';
        echo '<li>✅ Secure Download: Only paid bookings can access contracts</li>';
        echo '<li>✅ Terms & Conditions: Built-in T&C with signature requirement</li>';
        echo '</ul>';
        echo '<p><strong>Next:</strong> Test a booking to see signature capture and contract generation in action!</p>';
        echo '</body></html>';
        
    } catch (PDOException $e) {
        echo '<h2>Database Fix Error</h2><p style="color: red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Bridal Booking Quote - Looks By Anum</title>
    <style>
        /* Black & white, clean, user-friendly styles */
        :root{ --bg:#fafafa; --card:#ffffff; --text:#111111; --muted:#666666; --border:#e6e6e6 }
        html,body{height:100%;}
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;
            margin: 0;
            padding: 28px;
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing:antialiased;
            -moz-osx-font-smoothing:grayscale;
        }
        .container{
            max-width: 980px;
            margin: 0 auto;
            background: var(--card);
            padding: 28px;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        .header{
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1{
            margin:0 0 6px 0;
            font-size:28px;
            letter-spacing: -0.4px;
            color: var(--text);
        }
        .header p{ margin:0; color:var(--muted); }

        .date-row{ display:flex; gap:18px; justify-content:center; align-items:flex-start; margin-bottom:18px; }
        .date-check{ text-align:center; width:320px }
        .date-check label{ display:block; margin-bottom:8px; font-weight:600; font-size:18px; }
        .date-check input[type=date]{
            padding:10px 12px; font-size:15px; border:1px solid var(--border); border-radius:6px; background:transparent; color:var(--text); width:100%;
        }

        .warning{
            display:block;
            width:100%;
            box-sizing:border-box;
            margin-top:12px;
            padding:12px 14px;
            background:#fff;
            color:var(--text);
            border-left:4px solid var(--text);
            border-radius:6px;
            font-weight:600;
        }

        /* Quote cards */
        #cardsContainer{ display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:30px; }
        .quote-card{
            background:white;
            padding:20px;
            border-radius:12px;
            border:2px solid var(--border);
            min-height:150px;
        }
        .quote-card h3{ margin:0 0 8px 0; font-size:18px; }
        .quote-card .desc{ margin:0 0 12px 0; color:var(--muted); font-size:14px }
        .quote-card .items{ font-size:14px; color:var(--text); line-height:1.55; margin-bottom:12px }
        .quote-card .total{ font-weight:700; font-size:16px; }
        .quote-card .deposit{font-weight:700; font-size:15px; color:var(--text); }

        /* Action buttons */
        .action-buttons{ display:flex; justify-content: center; gap:16px; margin:20px 0; flex-wrap: wrap; }
        .action-btn{ 
            display:flex; 
            flex-direction:column; 
            align-items:center; 
            padding:12px 20px; 
            background:#fff; 
            border:1px solid var(--border); 
            border-radius:4px; 
            cursor:pointer; 
            text-decoration:none; 
            color:var(--text); 
            transition: all 0.2s ease;
            text-align: center;
            min-width: 200px;
        }
        .action-btn:hover{
            background:var(--text);
            color:#fff;
            text-decoration:none;
        }
        .action-btn h4{ margin:0 0 4px 0; font-size:14px; font-weight:600; }
        .action-btn p{ margin:0; font-size:12px; color:inherit; }

        /* Radio button options */
        .booking-type-options{ display: flex; justify-content: center; gap: 16px; margin: 16px 0; flex-wrap: nowrap; }
        .booking-type-options label{
            display: flex; 
            align-items: center; 
            cursor: pointer; 
            padding: 8px 12px; 
            border: 1px solid var(--border); 
            border-radius: 4px; 
            background: white; 
            transition: all 0.2s ease;
            flex-wrap: wrap;
            justify-content: center;
            width: 100%;
        }
        .booking-type-options label span{
            font-size: 14px; 
            color: var(--text);
        }
        .booking-type-options label:hover{
            background: var(--text);
            color: white;
        }
        .booking-type-options label:hover span{
            color: white;
        }
        .booking-type-options input[type="radio"]:checked + span{
            font-weight: 600;
        }
        /* Modern browsers with :has() support */
        @supports selector(:has(*)) {
            .booking-type-options label:has(input[type="radio"]:checked){
                background: var(--text);
                color: white;
            }
            .booking-type-options label:has(input[type="radio"]:checked) span{
                color: white;
            }
        }
        /* Fallback for older browsers */
        .booking-type-options label.selected{
            background: var(--text);
            color: white;
        }
        .booking-type-options label.selected span{
            color: white;
        }

        /* Artist selection (kept below pricing) */
        .artist-selection{ margin-top:10px; padding:16px; border-radius:8px; border:1px solid var(--border); background:transparent }
        .artist-selection h3{ margin:0 0 8px 0; font-size:16px }
        .artist-selection label{ margin-right:12px; color:var(--text) }
        .confirm-btn{ display:inline-block; margin-top:12px; padding:10px 16px; background:#111; color:#fff; border-radius:6px; border:none; cursor:pointer }
        .confirm-btn[disabled]{ opacity:0.5; cursor:not-allowed }

        /* Summary grid styling */
        .summary-grid {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .summary-grid .label {
            font-weight: 600;
            color: var(--text);
            margin-right: 12px;
        }

        .summary-grid .value {
            text-align: right;
            flex: 1;
        }

        /* City/Province grid */
        .city-province-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--text);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width:820px){ 
            .date-row{ flex-direction:column; align-items:center } 
            #cardsContainer{ grid-template-columns: 1fr } 
            .action-buttons{ grid-template-columns: 1fr; }
            .booking-type-options{ flex-direction: column; align-items: center; }
            #calendlyWidget .calendly-inline-widget{ height: 500px !important; }
            .container{ padding:18px }
            /* City/Province grid responsive */
            .city-province-grid{ grid-template-columns: 1fr !important; }
            /* Summary grid responsive */
            .summary-grid{ flex-direction: column; align-items: flex-start; }
            .summary-grid .value{ text-align: left; margin-top: 4px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Looks By Anum</h1>
            <p>View your personalised quote below and choose your next step.</p>
        </div>

        <div class="date-row">
            <div class="date-check">
                <label for="eventDate">Select your event date</label>
                <input type="date" id="eventDate" />
                <div id="dateMessage" aria-live="polite"></div>
            </div>
        </div>

        <div id="content"><!-- generated by JS --></div>
    </div>

    <script>
        console.log('=== PAGE LOADED DEBUG ===');
    console.log('Current URL:', window.location.href);
    
    // Add this simple test
console.log('=== DETAILED DEBUG ===');
const urlParams = new URLSearchParams(window.location.search);
console.log('bridalorsemi:', urlParams.get('bridalorsemi'));
console.log('servicetype:', urlParams.get('servicetype'));

// Test the service type detection logic manually
const st = urlParams.get('servicetype');
const bs = urlParams.get('bridalorsemi');
console.log('st value:', st);
console.log('bs value:', bs);

if (st==='nonbridal') {
    console.log('Service type would be: Non-Bridal / Photoshoot');
} else if (st==='semi' || bs==='semi') {
    console.log('Service type would be: Semi Bridal');
} else if (st==='bridal' || bs==='bridal') {
    console.log('Service type would be: Bridal');
} else {
    console.log('Service type would be: Bridal (default)');
}

console.log('========================');
        class QuotePageFromURL {
            constructor(){
                this.urlParams = new URLSearchParams(window.location.search);
                this.bookingData = this.parseURLData();
                this.formData = this.loadFormData(); // Load saved form data
                this.init();
                this.setupDateValidation();
            }

            // Load form data from sessionStorage (persists until page reload)
            loadFormData() {
                const saved = sessionStorage.getItem('bookingFormData');
                return saved ? JSON.parse(saved) : {
                    trialDate: '',
                    readyTime: '',
                    streetAddress: '',
                    city: '',
                    province: '',
                    postalCode: ''
                };
            }

            // Save form data to sessionStorage
            saveFormData() {
                sessionStorage.setItem('bookingFormData', JSON.stringify(this.formData));
            }

            parseURLData(){
                const safeDecodeParam = (p)=>{
                    if (!p) return '';
                    try{ let n = String(p).replace(/\+/g,' '); let d = decodeURIComponent(n); if (d.includes('%')) d = decodeURIComponent(d); return d }catch(e){ return String(p).replace(/\+/g,' ') }
                }
                return {
                    client:{
                        firstName: safeDecodeParam(this.urlParams.get('fname'))||'',
                        lastName: safeDecodeParam(this.urlParams.get('lname'))||'',
                        email: safeDecodeParam(this.urlParams.get('email'))||'',
                        phone: safeDecodeParam(this.urlParams.get('phone'))||'',
                        bookingId: this.urlParams.get('booking_id') || this.urlParams.get('pfid') || ''
                    },
                    event:{ region: safeDecodeParam(this.urlParams.get('region'))||'', date: this.urlParams.get('date')||'', trialDate: this.urlParams.get('trialdate')||'', serviceType: this.getServiceType(), artist: this.urlParams.get('varp')==='A' ? 'Anum' : (this.urlParams.get('varp')==='T' ? 'Team':'') },
                    services: {
                        bridalOrSemi: this.urlParams.get('bridalorsemi')||'bridal',
                        brideService: safeDecodeParam(this.urlParams.get('brideservice'))||'Both Hair & Makeup',
                        needsTrial: this.urlParams.get('needstrial')||'',
                        trialService: safeDecodeParam(this.urlParams.get('trialservice'))||'Both Hair & Makeup',
                        brideVeilSetting: this.urlParams.get('brideveilsetting')||'',
                        brideExtensions: this.urlParams.get('brideextensions')||'',
                        anyBP: this.urlParams.get('anybp')||'',
                        bpHM: parseInt(this.urlParams.get('bphm'))||0,
                        bpM: parseInt(this.urlParams.get('bpm'))||0,
                        bpH: parseInt(this.urlParams.get('bph'))||0,
                        bpSetting: parseInt(this.urlParams.get('bpsetting'))||0,
                        bpHExtensions: parseInt(this.urlParams.get('bphextensions'))||0,
                        bpAirbrush: parseInt(this.urlParams.get('bpairbrush'))||0,
                        nbCount: parseInt(this.urlParams.get('nbcount'))||0,
                        nbEveryoneBoth: this.urlParams.get('nbeveryoneboth')||'',
                        nbBoth: parseInt(this.urlParams.get('nbboth'))||0,
                        nbMakeup: parseInt(this.urlParams.get('nbmakeup'))||0,
                        nbHair: parseInt(this.urlParams.get('nbhair'))||0,
                        // Non-Bridal specific add-ons
                        nbExtensions: parseInt(this.urlParams.get('nbextensions'))||0,
                        nbJewelry: parseInt(this.urlParams.get('nbjewelry'))||0,
                        nbAirbrush: parseInt(this.urlParams.get('nbairbrush'))||0
                    }
                }
            }

            getServiceType(){
                const st = this.urlParams.get('servicetype'), bs = this.urlParams.get('bridalorsemi');
                if (st==='nonbridal') return 'Non-Bridal / Photoshoot';
                if (st==='semi' || bs==='semi') return 'Semi Bridal';
                if (st==='bridal' || bs==='bridal') return 'Bridal';
                return 'Bridal';
            }
            init(){
                const dateInput = document.getElementById('eventDate');

                if (this.bookingData.event.date && dateInput){ try{ dateInput.value = this.bookingData.event.date }catch(e){} }

                // set min for date input to today to disable past dates
                const today = new Date(); const yyyy = today.getFullYear(); const mm = String(today.getMonth()+1).padStart(2,'0'); const dd = String(today.getDate()).padStart(2,'0');
                if (dateInput) dateInput.min = `${yyyy}-${mm}-${dd}`;

                this.displayBookingData();
            }

            setupDateValidation(){
                const dateInput = document.getElementById('eventDate');
                const dateMsg = document.getElementById('dateMessage');

                const validateSingle = (inputElem, messageElem) => {
                    if (!inputElem) return;
                    const v = inputElem.value; if(!v){ messageElem && (messageElem.innerHTML=''); inputElem.setCustomValidity(''); return }
                    const sel = new Date(v+'T00:00:00'); const today=new Date(); today.setHours(0,0,0,0); const next=new Date(today); next.setDate(today.getDate()+1);
                    if (sel.getTime()===today.getTime() || sel.getTime()===next.getTime()){
                        if (messageElem) messageElem.innerHTML = `<div class="warning">We are not available for bookings on today or the very next day. Please call us at <a href=\"tel:+14162751719\">(416) 275-1719 </a>.</div>`;
                        inputElem.setCustomValidity('Date not available');
                    } else { if (messageElem) messageElem.innerHTML=''; inputElem.setCustomValidity('') }
                };

                if (dateInput) dateInput.addEventListener('change', ()=>validateSingle(dateInput, dateMsg));

                // run once
                validateSingle(dateInput, dateMsg);
            }

            displayBookingData(){
                const anumQuote = this.calculateQuote('Anum');
                const teamQuote = this.calculateQuote('Team');

                document.getElementById('content').innerHTML = `
                    <div style="text-align:left; margin-bottom:12px;"><h2 style="margin:0 0 6px 0; font-size:20px;">Available Packages</h2><p style="margin:0; color:var(--muted);">Both packages are shown below — choose how you'd like to proceed.</p></div>

                    <div id="cardsContainer">
                        ${this.generateQuoteCard('Anum Package', anumQuote, true)}
                        ${this.generateQuoteCard('Team Package', teamQuote, false)}
                    </div>

                    <!-- Next steps section -->
                    <div id="nextStepsContainer" style="margin-top:20px; padding:16px; border-radius:8px; border:1px solid var(--border); background:transparent;">
                        <h3 style="text-align:center; margin:0 0 12px 0; font-size:22px;">Note: For multi-day event bookings, please contact us directly to discuss your event details.</h3>
                        <h3 style="text-align:center; margin:0 0 12px 0; font-size:18px;">Ready to Move Forward?</h3>
                        <p style="text-align:center; margin:0 0 16px 0; color:var(--muted); font-size:14px;">
                            Choose how you'd like to proceed with your booking
                        </p>
                        <div class="action-buttons">
                            <a href="#" onclick="handleBookNow(); return false;" class="action-btn">
                                <h4>📅 Book Now</h4>
                                <p>Secure your date and proceed with booking</p>
                            </a>
                            <a href="#" onclick="handleScheduleCall(); return false;" class="action-btn">
                                <h4>📞 Schedule a Call</h4>
                                <p>Let's discuss your needs and answer questions</p>
                            </a>
                        </div>
                        <div id="actionMessage" style="margin-top:16px; text-align:center; font-weight:600;"></div>
                    </div>
                `;

            }

            // Function to create Stripe payment
            async createStripePayment(paymentData) {
                try {
                    console.log('Creating Stripe payment:', paymentData);
                    
                    const formData = new FormData();
                    formData.append('action', 'create_stripe_payment');
                    
                    // Add all payment data
                    Object.keys(paymentData).forEach(key => {
                        formData.append(key, paymentData[key] || '');
                    });

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });

                    const responseText = await response.text();
                    console.log('Stripe payment response:', responseText);
                    
                    const result = JSON.parse(responseText);
                    return result;
                } catch (error) {
                    console.error('Error creating Stripe payment:', error);
                    return { success: false, message: 'Payment creation error: ' + error.message };
                }
            }

            // Function to prepare booking for payment (store in session, not database yet)
            async submitBookingToDatabase(bookingData) {
                try {
                    // Debug: Log data being sent
                    console.log('Preparing booking data for payment (storing in session):', bookingData);
                    
                    const formData = new FormData();
                    formData.append('action', 'submit_booking');
                    
                    // Add all booking data
                    Object.keys(bookingData).forEach(key => {
                        formData.append(key, bookingData[key] || '');
                        console.log(`Adding ${key}: ${bookingData[key]}`);
                    });

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });

                    console.log('Response status:', response.status);
                    const responseText = await response.text();
                    console.log('Response text:', responseText);
                    
                    const result = JSON.parse(responseText);
                    console.log('Parsed result:', result);
                    return result;
                } catch (error) {
                    console.error('Error preparing booking:', error);
                    return { success: false, message: 'Network error occurred: ' + error.message };
                }
            }

            // Function to handle booking type selection for BRIDAL ONLY
            selectBookingType(artistType, bookingType) {
                const artistName = artistType === 'anum' ? 'Anum' : 'Team';
                
                // Replace entire content with booking form
                document.getElementById('content').innerHTML = `
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Complete Your Booking</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 16px;">
                            ${this.getServiceDescription(bookingType)} with <strong>${artistName}</strong>
                        </p>
                    </div>

                    <div style="max-width: 500px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--border);">
                        
                        ${bookingType === 'both' ? `
                            <div style="margin-bottom: 24px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">Select your trial date</label>
                                <input type="date" id="bookingTrialDate" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;" />
                                <div id="trialDateError" style="color: #dc3545; font-size: 14px; margin-top: 8px; text-align: center;"></div>
                            </div>
                        ` : ''}

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">What time do you need to be ready?</label>
                            <p style="font-size: 14px; color: var(--muted); margin-bottom: 12px;">This is the time you need to be completely ready by, not the start time.</p>
                            <input type="time" id="readyTimeInput" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;" />
                        </div>

                        <div id="appointmentTimeMessage" style="margin-bottom: 24px;"></div>

                        <div style="display: flex; gap: 12px;">
                            <button onclick="goBackToBookingOptions()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text); transition: all 0.2s;">
                                ← Back
                            </button>
                            <button id="continueToAddressBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                                Continue to Address
                            </button>
                        </div>
                    </div>
                `;

                // Store booking details for later use
                window.currentBooking = {
                    artistType,
                    artistName,
                    bookingType
                };

                // Set up date and time validation
                this.setupBookingFormValidation(bookingType);
            }

            // NEW: Function to handle direct service booking (Semi Bridal & Non-Bridal)
            selectDirectService(artistType) {
                const artistName = artistType === 'anum' ? 'Anum' : 'Team';
                const serviceType = this.bookingData.event.serviceType;
                
                // Replace entire content with simplified booking form (no booking type selection)
                document.getElementById('content').innerHTML = `
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Complete Your Booking</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 16px;">
                            ${serviceType} with <strong>${artistName}</strong>
                        </p>
                    </div>

                    <div style="max-width: 500px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--border);">
                        
                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">What time do you need to be ready?</label>
                            <p style="font-size: 14px; color: var(--muted); margin-bottom: 12px;">This is the time you need to be completely ready by, not the start time.</p>
                            <input type="time" id="readyTimeInput" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;" />
                        </div>

                        <div id="appointmentTimeMessage" style="margin-bottom: 24px;"></div>

                        <div style="display: flex; gap: 12px;">
                            <button onclick="goBackToArtistSelection()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text); transition: all 0.2s;">
                                ← Back
                            </button>
                            <button id="continueToAddressBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                                Continue to Address
                            </button>
                        </div>
                    </div>
                `;

                // Store booking details for later use
                window.currentBooking = {
                    artistType,
                    artistName,
                    bookingType: 'service' // Default for non-bridal services
                };

                // Set up time validation (no trial date needed)
                this.setupDirectServiceValidation();
            }

            // Function to update radio selection styling (fallback for browsers without :has() support)
            updateRadioSelection(selectedRadio) {
                // Remove selected class from all labels
                document.querySelectorAll('.booking-type-options label').forEach(label => {
                    label.classList.remove('selected');
                });
                
                // Add selected class to the parent label of the checked radio
                if (selectedRadio.checked) {
                    selectedRadio.closest('label').classList.add('selected');
                }
            }

            // REPLACE the calculateQuote() function in custom-quote.php with this complete version
            // This matches the logic from getDetailedQuoteForArtist() in index.php
            
            calculateQuote(artistType){
                const isAnum = artistType === 'Anum';
                const services = this.bookingData.services;
                const event = this.bookingData.event;
                let items = [], subtotal = 0;
            
                // Travel fee calculation
                let travelFee = 0; 
                const region = event.region;
                if (region === 'Toronto/GTA') travelFee = 25;
                else if (region && (region.includes('Further Out')|| region.includes('1 Hour Plus'))) travelFee = 120;
                else if (region && (region.includes('Moderate Distance')|| region.includes('30 Minutes to 1 Hour'))) travelFee = 80;
                else if (region && (region.includes('Immediate Neighbors')|| region.includes('15-30 Minutes'))) travelFee = 40;
                else if (region === 'Outside GTA') travelFee = 80;
                else if (region && region.toLowerCase().includes('outside')) travelFee = 80;
                else if (region && region !== 'Toronto/GTA' && region !== '' && region !== 'Destination Wedding') travelFee = 80;
            
                if (event.serviceType === 'Bridal'){
                    // Main bridal service
                    const brideService = (services.brideService||'Both Hair & Makeup').trim();
                    if (brideService.toLowerCase().includes('hair') && brideService.toLowerCase().includes('makeup')) { 
                        const price = isAnum ? 450 : 360; 
                        items.push({description:`Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else if (brideService.toLowerCase().includes('hair only')) { 
                        const price = isAnum ? 200 : 160; 
                        items.push({description:`Bridal Hair Only (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else if (brideService.toLowerCase().includes('makeup only')) { 
                        const price = isAnum ? 275 : 220; 
                        items.push({description:`Bridal Makeup Only (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else { 
                        const price = isAnum ? 450 : 360; 
                        items.push({description:`Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
            
                    // TRIAL SERVICES (using correct URL parameter names)
                    if (services.needsTrial === 'Yes' && services.trialService) {
                        if (services.trialService.includes('Both Hair') || services.trialService.includes('Hair & Makeup')) {
                            const price = isAnum ? 250 : 200;
                            items.push({description: 'Bridal Trial (Hair & Makeup)', price});
                            subtotal += price;
                        } else if (services.trialService.includes('Hair Only')) {
                            const price = isAnum ? 150 : 120;
                            items.push({description: 'Bridal Trial (Hair Only)', price});
                            subtotal += price;
                        } else if (services.trialService.includes('Makeup Only')) {
                            const price = isAnum ? 150 : 120;
                            items.push({description: 'Bridal Trial (Makeup Only)', price});
                            subtotal += price;
                        }
                    }
            
                    // BRIDAL ADD-ONS (using correct URL parameter names)
                    if (services.brideVeilSetting === 'Yes') { 
                        items.push({description:'Bridal Jewelry & Dupatta/Veil Setting', price:50}); 
                        subtotal += 50;
                    }
                    if (services.brideExtensions === 'Yes') { 
                        items.push({description:'Bridal Hair Extensions Installation', price:30}); 
                        subtotal += 30;
                    }
            
                    // BRIDAL PARTY SERVICES (using correct URL parameter names from parseURLData())
                    if (services.anyBP === 'Yes') {
                        if (services.bpHM > 0) { 
                            const totalPrice = services.bpHM * 200; 
                            items.push({description:`Bridal Party Hair and Makeup (200 CAD x ${services.bpHM})`, price: totalPrice}); 
                            subtotal += totalPrice;
                        }
                        if (services.bpM > 0) { 
                            const totalPrice = services.bpM * 100; 
                            items.push({description:`Bridal Party Makeup Only (100 CAD x ${services.bpM})`, price: totalPrice}); 
                            subtotal += totalPrice;
                        }
                        if (services.bpH > 0) { 
                            const totalPrice = services.bpH * 100; 
                            items.push({description:`Bridal Party Hair Only (100 CAD x ${services.bpH})`, price: totalPrice}); 
                            subtotal += totalPrice;
                        }
                        if (services.bpSetting > 0) { 
                            const totalPrice = services.bpSetting * 20; 
                            items.push({description:`Bridal Party Dupatta/Veil Setting (20 CAD x ${services.bpSetting})`, price: totalPrice}); 
                            subtotal += totalPrice;
                        }
                        if (services.bpHExtensions > 0) { 
                            const totalPrice = services.bpHExtensions * 20; 
                            items.push({description:`Bridal Party Hair Extensions Installation (20 CAD x ${services.bpHExtensions})`, price: totalPrice}); 
                            subtotal += totalPrice;
                        }
                        if (services.bpAirbrush > 0) { 
                            const totalPrice = services.bpAirbrush * 50; 
                            items.push({description:`Bridal Party Airbrush Makeup (50 CAD x ${services.bpAirbrush})`, price: totalPrice}); 
                            subtotal += totalPrice;
                        }
                    }
                } 
                else if (event.serviceType === 'Semi Bridal') {
                    // Semi-bridal services
                    const brideService = (services.brideService||'Both Hair & Makeup').trim();
                    if (brideService.toLowerCase().includes('hair') && brideService.toLowerCase().includes('makeup')) { 
                        const price = isAnum ? 350 : 280; 
                        items.push({description:`Semi Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else if (brideService.toLowerCase().includes('hair only')) { 
                        const price = isAnum ? 175 : 140; 
                        items.push({description:`Semi Bridal Hair Only (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else if (brideService.toLowerCase().includes('makeup only')) { 
                        const price = isAnum ? 225 : 180; 
                        items.push({description:`Semi Bridal Makeup Only (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    // Add semi-bridal add-ons as needed
                    // Add-ons for semi bridal
                    // Add-ons for semi bridal
                    if (services.brideVeilSetting === 'Yes') { 
                        items.push({description:'Veil/Dupatta Setting', price:50}); 
                        subtotal += 50;
                    }
                    if (services.brideExtensions === 'Yes') { 
                        items.push({description:'Hair Extensions Installation', price:30}); 
                        subtotal += 30;
                    }
                    
                    // Bridal party for semi bridal
                    if (services.bpHM > 0) { 
                        const t = services.bpHM * 200; 
                        items.push({description:`Bridal Party Hair & Makeup x ${services.bpHM}`, price:t}); 
                        subtotal += t;
                    }
                    if (services.bpM > 0) { 
                        const t = services.bpM * 100; 
                        items.push({description:`Bridal Party Makeup Only x ${services.bpM}`, price:t}); 
                        subtotal += t;
                    }
                    if (services.bpH > 0) { 
                        const t = services.bpH * 100; 
                        items.push({description:`Bridal Party Hair Only x ${services.bpH}`, price:t}); 
                        subtotal += t;
                    }
                    
                    // Missing bridal party add-ons
                    if (services.bpSetting > 0) { 
                        const totalPrice = services.bpSetting * 20; 
                        items.push({description:`Bridal Party Dupatta/Veil Setting x ${services.bpSetting}`, price: totalPrice}); 
                        subtotal += totalPrice;
                    }
                    if (services.bpHExtensions > 0) { 
                        const totalPrice = services.bpHExtensions * 20; 
                        items.push({description:`Bridal Party Hair Extensions Installation x ${services.bpHExtensions}`, price: totalPrice}); 
                        subtotal += totalPrice;
                    }
                    if (services.bpAirbrush > 0) { 
                        const totalPrice = services.bpAirbrush * 50; 
                        items.push({description:`Bridal Party Airbrush Makeup x ${services.bpAirbrush}`, price: totalPrice}); 
                        subtotal += totalPrice;
                    }
                }
                else if (event.serviceType === 'Non-Bridal / Photoshoot') {
                    // Non-bridal services (existing logic is mostly correct)
                    if (services.nbEveryoneBoth === 'Yes') {
                        const pricePerPerson = isAnum ? 250 : 200;
                        if (services.nbCount > 0) {
                            const totalPrice = services.nbCount * pricePerPerson;
                            items.push({description: `Non-Bridal Hair & Makeup (${isAnum?'Anum':'Team'}) x ${services.nbCount}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                    } else {
                        if (services.nbBoth > 0) {
                            const pricePerPerson = isAnum ? 250 : 200;
                            const totalPrice = services.nbBoth * pricePerPerson;
                            items.push({description: `Non-Bridal Hair & Makeup (${isAnum?'Anum':'Team'}) x ${services.nbBoth}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                        if (services.nbMakeup > 0) {
                            const pricePerPerson = isAnum ? 140 : 110;
                            const totalPrice = services.nbMakeup * pricePerPerson;
                            items.push({description: `Non-Bridal Makeup Only (${isAnum?'Anum':'Team'}) x ${services.nbMakeup}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                        if (services.nbHair > 0) {
                            const pricePerPerson = isAnum ? 130 : 110;
                            const totalPrice = services.nbHair * pricePerPerson;
                            items.push({description: `Non-Bridal Hair Only (${isAnum?'Anum':'Team'}) x ${services.nbHair}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                    }
            
                    // Non-Bridal Add-ons
                    if (services.nbExtensions > 0) {
                        const totalPrice = services.nbExtensions * 20;
                        items.push({description: `Hair Extensions Installation x ${services.nbExtensions}`, price: totalPrice});
                        subtotal += totalPrice;
                    }
                    if (services.nbJewelry > 0) {
                        const totalPrice = services.nbJewelry * 20;
                        items.push({description: `Jewelry/Dupatta Setting x ${services.nbJewelry}`, price: totalPrice});
                        subtotal += totalPrice;
                    }
                    if (services.nbAirbrush > 0) {
                        const totalPrice = services.nbAirbrush * 50;
                        items.push({description: `Airbrush Makeup x ${services.nbAirbrush}`, price: totalPrice});
                        subtotal += totalPrice;
                    }
                }
            
                // Add travel fee
                if (travelFee > 0) { 
                    items.push({description: region === 'Toronto/GTA' ? 'Travel Fee (Toronto/GTA)' : `Travel Fee (${region})`, price: travelFee}); 
                    subtotal += travelFee;
                }
                
                const hst = subtotal * 0.13; 
                const total = subtotal + hst;
                return { items, subtotal, hst, total };
            }
            
            generateQuoteCard(title, quote, isPremium){
                const artistType = isPremium ? 'Anum' : 'Team';
                const packageDesc = isPremium ? 'Premium service by Anum herself' : 'Professional service by trained team members';
                const itemsHtml = quote.items.length ? quote.items.map(i=>`<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">${i.description} </span><span style="font-size:13px"> $${i.price.toFixed(2)}</span></div>`).join('') : '<div style="color:var(--muted)">No additional items</div>';

                // Determine deposit rate based on service type - 50% for non-bridal, 30% for others
                 const svc = (this.bookingData.event.serviceType || '').toLowerCase();
                const depositRate = (svc.includes('non-bridal') || svc.includes('non bridal') ) ? 0.5 : 0.3;
                const depositPctText = depositRate === 0.5 ? '50%' : '30%';
                // Calculate deposit on subtotal (before HST)
                const depositAmount = quote.total * depositRate;

                return `
                    <div class="quote-card" data-artist="${artistType}">
                        <h3>${artistType} Package</h3>
                        <div class="desc">${packageDesc}</div>
                        <div class="items">${itemsHtml}</div>
                        <div class="subtotal" style="display:flex;justify-content:space-between;margin-bottom:4px; font-size:13px"><span>Subtotal:</span><span>$${quote.subtotal.toFixed(2)}</span></div>
                        <div class="hst" style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px"><span>HST 13%:</span><span>$${quote.hst.toFixed(2)}</span></div>
                        <div class="total" style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px"><span>Total:</span><span> $${quote.total.toFixed(2)}</span></div>
                        <div class="deposit" style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px"><span>Deposit required (${depositPctText}):</span><span> $${depositAmount.toFixed(2)}</span></div>
                    </div>
                `;
            }

            // Continue with other methods...
            // [Additional methods would continue here but truncated for space]
            // NEW: Setup validation for direct service bookings
            setupDirectServiceValidation() {
                const readyTimeInput = document.getElementById('readyTimeInput');
                const appointmentMsg = document.getElementById('appointmentTimeMessage');
                const continueBtn = document.getElementById('continueToAddressBtn');

                // Restore saved ready time
                if (this.formData.readyTime) {
                    readyTimeInput.value = this.formData.readyTime;
                }

                const validateForm = () => {
                    const readyTime = readyTimeInput.value;
                    
                    if (!readyTime) {
                        continueBtn.disabled = true;
                        continueBtn.style.background = '#ccc';
                        continueBtn.style.color = '#666';
                        continueBtn.style.cursor = 'not-allowed';
                        appointmentMsg.innerHTML = '';
                        return;
                    }

                    // Save ready time
                    this.formData.readyTime = readyTime;
                    this.saveFormData();

                    // Update button state
                    continueBtn.disabled = false;
                    continueBtn.style.background = 'var(--text)';
                    continueBtn.style.color = 'white';
                    continueBtn.style.cursor = 'pointer';

                    // Show appointment time calculation
                    const [hours, minutes] = readyTime.split(':');
                    const readyDate = new Date();
                    readyDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);
                    
                    const appointmentDate = new Date(readyDate.getTime() - (2 * 60 * 60 * 1000));
                    
                    const appointmentTime = appointmentDate.toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    });
                    
                    const readyTimeFormatted = readyDate.toLocaleTimeString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    });

                    appointmentMsg.innerHTML = `
                        <div style="padding: 16px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border);">
                            <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px; text-align: center; color: var(--text);">Your Appointment Schedule</div>
                            <div style="font-size: 14px; margin-bottom: 4px;">📅 <strong>Appointment Start:</strong> ${appointmentTime}</div>
                            <div style="font-size: 14px; margin-bottom: 8px;">⏰ <strong>Ready Time:</strong> ${readyTimeFormatted}</div>
                            <div style="font-size: 12px; color: var(--muted); text-align: center;">We'll start 2 hours before your ready time to ensure you're completely finished on schedule.</div>
                        </div>
                    `;
                };

                // Add event listener
                readyTimeInput.addEventListener('change', validateForm);

                // Add click handler for continue button
                continueBtn.addEventListener('click', () => {
                    if (!continueBtn.disabled) {
                        this.showAddressCollection();
                    }
                });

                // Initial validation
                validateForm();
            }

            // Function to get service description
            getServiceDescription(bookingType) {
                // For semi bridal and non-bridal, return the service type directly
                if (bookingType === 'service') {
                    return this.bookingData.event.serviceType;
                }
                
                // For bridal, return based on booking type
                switch(bookingType) {
                    case 'trial': return 'Trial Session';
                    case 'both': return 'Bridal Service + Trial';
                    default: return 'Bridal Service';
                }
            }

            // Function to setup form validation (BRIDAL ONLY)
            setupBookingFormValidation(bookingType) {
                const trialDateInput = document.getElementById('bookingTrialDate');
                const readyTimeInput = document.getElementById('readyTimeInput');
                const appointmentMsg = document.getElementById('appointmentTimeMessage');
                const continueBtn = document.getElementById('continueToAddressBtn');
                const trialDateError = document.getElementById('trialDateError');

                // Set minimum dates
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                
                if (trialDateInput) {
                    trialDateInput.min = `${yyyy}-${mm}-${dd}`;
                    // Restore saved trial date
                    if (this.formData.trialDate) {
                        trialDateInput.value = this.formData.trialDate;
                    }
                }

                // Restore saved ready time
                if (this.formData.readyTime) {
                    readyTimeInput.value = this.formData.readyTime;
                }

                const validateForm = () => {
                    let isValid = true;
                    
                    // Validate trial date if needed
                    if (bookingType === 'trial' || bookingType === 'both') {
                        const trialDate = trialDateInput.value;
                        const eventDate = document.getElementById('eventDate');
                        const eventDateValue = eventDate ? eventDate.value : '';
                        
                        if (!trialDate) {
                            trialDateError.textContent = 'Please select a trial date';
                            isValid = false;
                        } else if (eventDateValue && new Date(trialDate) >= new Date(eventDateValue)) {
                            trialDateError.textContent = 'Trial date must be before your event date';
                            isValid = false;
                        } else {
                            const selectedDate = new Date(trialDate);
                            const todayCheck = new Date();
                            todayCheck.setHours(0, 0, 0, 0);
                            const nextDay = new Date(todayCheck);
                            nextDay.setDate(todayCheck.getDate() + 1);
                            
                            if (selectedDate.getTime() === todayCheck.getTime() || selectedDate.getTime() === nextDay.getTime()) {
                                trialDateError.textContent = 'We are not available for bookings on today or tomorrow. Please call us at (416) 275-1719 .';
                                isValid = false;
                            } else {
                                trialDateError.textContent = '';
                                // Save trial date
                                this.formData.trialDate = trialDate;
                                this.saveFormData();
                            }
                        }
                    }

                    // Validate ready time
                    const readyTime = readyTimeInput.value;
                    if (!readyTime) {
                        isValid = false;
                    } else {
                        // Save ready time
                        this.formData.readyTime = readyTime;
                        this.saveFormData();
                    }

                    // Update button state
                    if (isValid && readyTime) {
                        continueBtn.disabled = false;
                        continueBtn.style.background = 'var(--text)';
                        continueBtn.style.color = 'white';
                        continueBtn.style.cursor = 'pointer';

                        // Show appointment time calculation
                        const [hours, minutes] = readyTime.split(':');
                        const readyDate = new Date();
                        readyDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);
                        
                        const appointmentDate = new Date(readyDate.getTime() - (2 * 60 * 60 * 1000));
                        
                        const appointmentTime = appointmentDate.toLocaleTimeString('en-US', {
                            hour: 'numeric',
                            minute: '2-digit',
                            hour12: true
                        });
                        
                        const readyTimeFormatted = readyDate.toLocaleTimeString('en-US', {
                            hour: 'numeric',
                            minute: '2-digit',
                            hour12: true
                        });

                        appointmentMsg.innerHTML = `
                            <div style="padding: 16px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border);">
                                <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px; text-align: center; color: var(--text);">Your Appointment Schedule</div>
                                <div style="font-size: 14px; margin-bottom: 4px;">📅 <strong>Appointment Start:</strong> ${appointmentTime}</div>
                                <div style="font-size: 14px; margin-bottom: 8px;">⏰ <strong>Ready Time:</strong> ${readyTimeFormatted}</div>
                                <div style="font-size: 12px; color: var(--muted); text-align: center;">We'll start 2 hours before your ready time to ensure you're completely finished on schedule.</div>
                            </div>
                        `;
                    } else {
                        continueBtn.disabled = true;
                        continueBtn.style.background = '#ccc';
                        continueBtn.style.color = '#666';
                        continueBtn.style.cursor = 'not-allowed';
                        appointmentMsg.innerHTML = '';
                    }
                };

                // Add event listeners
                if (trialDateInput) {
                    trialDateInput.addEventListener('change', validateForm);
                }
                readyTimeInput.addEventListener('change', validateForm);

                // Add click handler for continue button
                continueBtn.addEventListener('click', () => {
                    if (!continueBtn.disabled) {
                        this.showAddressCollection();
                    }
                });

                // Initial validation
                validateForm();
            }

            // Function to show address collection step
            showAddressCollection() {
                document.getElementById('content').innerHTML = `
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Service Address</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 16px;">
                            Where should we provide the service?
                        </p>
                    </div>

                    <div style="max-width: 500px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--border);">
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">Street Address</label>
                            <input type="text" id="streetAddress" placeholder="123 Main Street" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;" />
                        <div id="streetAddressError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
                        </div>

                        <div class="city-province-grid">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">City</label>
                                <input type="text" id="city" placeholder="Toronto" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;" />
                            <div id="cityError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">Province</label>
                                <select id="province" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;">
                                    <option value="">Select Province</option>
                                    <option value="AB">Alberta</option>
                                    <option value="BC">British Columbia</option>
                                    <option value="ON">Ontario</option>
                                    <option value="QC">Quebec</option>
                                    
                                </select>
                            <div id="provinceError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
                            </div>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--text); font-size: 16px;">Postal Code</label>
                            <input type="text" id="postalCode" placeholder="M5V 3A8" maxlength="7" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); box-sizing: border-box;" />
                            <div id="postalCodeError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
                            <p style="font-size: 12px; color: var(--muted); margin-top: 4px;">Canadian postal code format: A1A 1A1</p>
                        </div>

                        <div style="display: flex; gap: 12px;">
                            <button onclick="goBackToTimeSelection()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text); transition: all 0.2s;">
                                ← Back
                            </button>
                            <button id="confirmBookingBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                                Review Booking
                            </button>
                        </div>

                        <div id="finalBookingMessage" style="margin-top: 16px; text-align: center;"></div>
                    </div>
                `;

                this.setupAddressValidation();
            }

            // Function to setup address validation
            setupAddressValidation() {
                // Address fields
                const streetAddressInput = document.getElementById('streetAddress');
                const cityInput = document.getElementById('city');
                const provinceInput = document.getElementById('province');
                const postalCodeInput = document.getElementById('postalCode');
                const confirmBtn = document.getElementById('confirmBookingBtn');
                const finalMsg = document.getElementById('finalBookingMessage');

                // Error elements
                const streetAddressError = document.getElementById('streetAddressError');
                const cityError = document.getElementById('cityError');
                const provinceError = document.getElementById('provinceError');
                const postalCodeError = document.getElementById('postalCodeError');

                // Restore saved address data
                if (this.formData.streetAddress) streetAddressInput.value = this.formData.streetAddress;
                if (this.formData.city) cityInput.value = this.formData.city;
                if (this.formData.province) provinceInput.value = this.formData.province;
                if (this.formData.postalCode) postalCodeInput.value = this.formData.postalCode;

                // Canadian postal code validation
                const validatePostalCode = (postalCode) => {
                    const canadianPostalRegex = /^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/;
                    return canadianPostalRegex.test(postalCode);
                };

                // Format postal code as user types
                const formatPostalCode = (value) => {
                    let formatted = value.replace(/\s/g, '').toUpperCase();
                    if (formatted.length > 3) {
                        formatted = formatted.substring(0, 3) + ' ' + formatted.substring(3, 6);
                    }
                    return formatted;
                };

                // Add postal code formatting
                postalCodeInput.addEventListener('input', (e) => {
                    const formatted = formatPostalCode(e.target.value);
                    e.target.value = formatted;
                    this.formData.postalCode = formatted;
                    this.saveFormData();
                    validateAddressForm();
                });

                // FOR custom-quote.php - Replace the validateAddressForm function:

const validateAddressForm = () => {
    let isValid = true;
    
    // Validate address fields (remove error message assignments)
    const streetAddress = streetAddressInput.value.trim();
    if (!streetAddress) {
        isValid = false;
    } else {
        this.formData.streetAddress = streetAddress;
        this.saveFormData();
    }

    const city = cityInput.value.trim();
    if (!city) {
        isValid = false;
    } else {
        this.formData.city = city;
        this.saveFormData();
    }

    const province = provinceInput.value;
    if (!province) {
        isValid = false;
    } else {
        this.formData.province = province;
        this.saveFormData();
    }

    const postalCode = postalCodeInput.value.trim();
    if (!postalCode) {
        isValid = false;
    } else if (!validatePostalCode(postalCode)) {
        isValid = false;
    }

    // Update button state
    if (isValid) {
        confirmBtn.disabled = false;
        confirmBtn.style.background = 'var(--text)';
        confirmBtn.style.color = 'white';
        confirmBtn.style.cursor = 'pointer';
    } else {
        confirmBtn.disabled = true;
        confirmBtn.style.background = '#ccc';
        confirmBtn.style.color = '#666';
        confirmBtn.style.cursor = 'not-allowed';
    }
};


                // Add event listeners
                streetAddressInput.addEventListener('input', validateAddressForm);
                cityInput.addEventListener('input', validateAddressForm);
                provinceInput.addEventListener('change', validateAddressForm);

                // Add click handler for confirm button
                confirmBtn.addEventListener('click', () => {
                    if (!confirmBtn.disabled) {
                        this.showBookingSummary();
                    }
                });

                // Initial validation
                validateAddressForm();
            }

            // Function to show booking summary with Terms & Conditions and Signature
            showBookingSummary() {
                const serviceText = this.getServiceDescription(window.currentBooking.bookingType);
                const address = `${this.formData.streetAddress}, ${this.formData.city}, ${this.formData.province} ${this.formData.postalCode}`;
                const eventDate = document.getElementById('eventDate') ? document.getElementById('eventDate').value : this.bookingData.event.date;
                
                // Calculate appointment time
                const [hours, minutes] = this.formData.readyTime.split(':');
                const readyDate = new Date();
                readyDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);
                const appointmentDate = new Date(readyDate.getTime() - (2 * 60 * 60 * 1000));
                
                const appointmentTime = appointmentDate.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
                
                const readyTimeFormatted = readyDate.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
            
                // Get quote information
                const quote = this.calculateDynamicQuote(window.currentBooking.artistType === 'anum' ? 'Anum' : 'Team', window.currentBooking.bookingType);
                
                    
                
                // Determine deposit rate - 50% for non-bridal, 30% for others
                const svc = (this.bookingData.event.serviceType || '').toLowerCase();
                const depositRate = (svc.includes('non-bridal') || svc.includes('non bridal')) ? 0.5 : 0.3;
                const depositPctText = depositRate === 0.5 ? '50%' : '30%';
                // Calculate deposit on subtotal (before HST)
                const depositAmount = quote.total * depositRate;
            
                document.getElementById('content').innerHTML = `
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Booking Summary</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 16px;">
                            Please review your booking details below
                        </p>
                    </div>
            
                    <div style="max-width: 600px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--border);">
                        
                        <!-- Service Details -->
                        <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--border);">
                            <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--text);">Service Details</h3>
                            <div style="display: grid; gap: 12px;">
                                <div class="summary-grid">
                                    <span class="label">Artist:</span>
                                    <span class="value">${window.currentBooking.artistName}</span>
                                </div>
                                <div class="summary-grid">
                                    <span class="label">Service:</span>
                                    <span class="value">${serviceText}</span>
                                </div>
                                <div class="summary-grid">
                                    <span class="label">Event Date:</span>
                                    <span class="value">${eventDate ? new Date(eventDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) : 'Not specified'}</span>
                                </div>
                                ${this.formData.trialDate ? `
                                    <div class="summary-grid">
                                        <span class="label">Trial Date:</span>
                                        <span class="value">${new Date(this.formData.trialDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
            
                        <!-- Appointment Schedule -->
                        <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--border);">
                            <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--text);">Appointment Schedule</h3>
                            <div style="padding: 16px; background: var(--bg); border-radius: 8px; border: 1px solid var(--border);">
                                <div style="display: grid; gap: 8px;">
                                    <div class="summary-grid">
                                        <span class="label">📅 Appointment Start:</span>
                                        <span class="value">${appointmentTime}</span>
                                    </div>
                                    <div class="summary-grid">
                                        <span class="label">⏰ Ready Time:</span>
                                        <span class="value">${readyTimeFormatted}</span>
                                    </div>
                                    <div class="summary-grid">
                                        <span class="label">📍 Location:</span>
                                        <span class="value" style="word-break: break-word;">${address}</span>
                                    </div>
                                </div>
                                <p style="font-size: 12px; color: var(--muted); text-align: center; margin: 12px 0 0 0;">
                                    We'll start 2 hours before your ready time to ensure you're completely finished on schedule.
                                </p>
                            </div>
                        </div>
            
                        <!-- Pricing Summary -->
                        <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--border);">
                            <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--text);">Pricing Summary</h3>
                            <div style="display: grid; gap: 8px;">
                                ${quote.items.map(item => `
                                    <div style="display: flex; justify-content: space-between; font-size: 14px;">
                                        <span>${item.description}</span>
                                        <span>$${item.price.toFixed(2)}</span>
                                    </div>
                                `).join('')}
                                <hr style="margin: 12px 0; border: 1px solid var(--border);">
                                <div style="display: flex; justify-content: space-between; font-size: 14px; color: var(--text);">
                                    <span>Subtotal:</span>
                                    <span>$${quote.subtotal.toFixed(2)}</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 14px; color: var(--text); margin-bottom: 8px;">
                                    <span>HST (13%):</span>
                                    <span>$${quote.hst.toFixed(2)}</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 16px;">
                                    <span>Total (with 13% HST):</span>
                                    <span>$${quote.total.toFixed(2)} CAD</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-weight: 600; margin-top: 8px; color: var(--text);">
                                    <span>Amount to Pay (${depositPctText}):</span>
                                    <span>$${depositAmount.toFixed(2)} CAD</span>
                                </div>
                            </div>
                        </div>
            
                        <!-- Terms & Conditions Section -->
                        <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--border);">
                            <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--text);">Terms & Conditions</h3>
                            <div style="max-height: 200px; overflow-y: auto; padding: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; font-size: 13px; line-height: 1.5;">
                                <h4 style="margin: 0 0 8px 0; font-size: 14px;">Client Responsibilities:</h4>
                                <ul style="margin: 0 0 12px 0; padding-left: 18px;">
                                    <li>Provide accurate and detailed information regarding the desired makeup and hair services.</li>
                                    <li>Ensure a suitable location with proper lighting and access to an electrical outlet.</li>
                                    <li>Arrive with clean, dry hair and a clean face, free of makeup or hair products.</li>
                                    <li>Client is responsible for any parking fees incurred at the event location.</li>
                                </ul>
                                
                                <h4 style="margin: 0 0 8px 0; font-size: 14px;">Cancellation Policy:</h4>
                                <ul style="margin: 0 0 12px 0; padding-left: 18px;">
                                    <li>The deposit is non-refundable if the client cancels.</li>
                                    <li>If the event is canceled less than 3 days before the scheduled date, the full remaining balance will still be due.</li>
                                </ul>
                                
                                <h4 style="margin: 0 0 8px 0; font-size: 14px;">Liability:</h4>
                                <ul style="margin: 0 0 12px 0; padding-left: 18px;">
                                    <li><strong>Looks By Anum</strong> is not responsible for allergic reactions or injuries resulting from the services provided.</li>
                                    <li>The client must inform the artist of any allergies or sensitivities before the service begins.</li>
                                    <li>The client agrees to hold <strong>Looks By Anum</strong> harmless from any claims related to the services rendered.</li>
                                </ul>
                                
                                <h4 style="margin: 0 0 8px 0; font-size: 14px;">Payment Terms:</h4>
                                <p style="margin: 0 0 8px 0;">The total price for the makeup and hair services is <strong>${quote.total.toFixed(2)} CAD</strong>. A non-refundable deposit of <strong>${depositPctText}</strong> is required to secure your booking. The remaining balance will be due on the day of the event.</p>
                                <p style="margin: 0;">Once we receive the deposit, your booking will be confirmed, and the date will be reserved exclusively for you. Please note that availability cannot be guaranteed until the deposit is received.</p>
                            </div>
                            
                            <div style="margin-top: 16px;">
                                <label style="display: flex; align-items: flex-start; cursor: pointer; gap: 8px;">
                                    <input type="checkbox" id="termsCheckbox" style="margin-top: 2px;" />
                                    <span style="font-size: 14px; line-height: 1.4;">
                                        I have read, understood, and agree to the terms and conditions outlined above. I acknowledge that by providing my digital signature below, I am entering into a legally binding contract.
                                    </span>
                                </label>
                            </div>
                        </div>
            
                        <!-- Digital Signature Section -->
                        <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--border);">
                            <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--text);">Digital Signature</h3>
                            <p style="margin: 0 0 12px 0; font-size: 14px; color: var(--muted);">
                                Please sign below to confirm your agreement to the booking and terms:
                            </p>
                            
                            <div style="border: 2px solid var(--border); border-radius: 8px; background: white; padding: 12px; margin-bottom: 12px;">
                                <canvas 
                                    id="signatureCanvas" 
                                    width="400" 
                                    height="150" 
                                    style="border: 1px dashed #ccc; cursor: crosshair; width: 100%; height: 150px; display: block;"
                                ></canvas>
                            </div>
                            
                            <div style="display: flex; gap: 12px; justify-content: center;">
                                <button onclick="clearSignature()" style="padding: 8px 16px; background: white; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 14px;">
                                    Clear Signature
                                </button>
                            </div>
                            
                            <div id="signatureError" style="color: #dc3545; font-size: 14px; margin-top: 8px; text-align: center;"></div>
                        </div>
            
                        <!-- Action Buttons -->
                        <div style="display: flex; gap: 12px;">
                            <button onclick="goBackToAddress()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text); transition: all 0.2s;">
                                ← Edit Details
                            </button>
                            <button id="submitBookingBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                                Proceed to Payment
                            </button>
                        </div>
                        
                        <div id="submitMessage" style="margin-top: 12px; text-align: center; font-size: 14px; color: var(--muted);"></div>
                    </div>
                `;
            
                // Initialize signature pad
                this.initializeSignature();
            }
            
            // Initialize signature pad functionality - FIXED VERSION
            initializeSignature() {
                const canvas = document.getElementById('signatureCanvas');
                const submitBtn = document.getElementById('submitBookingBtn');
                const termsCheckbox = document.getElementById('termsCheckbox');
                const signatureError = document.getElementById('signatureError');
                const submitMessage = document.getElementById('submitMessage');
                
                if (!canvas) {
                    console.error('Canvas not found');
                    return;
                }
            
                const ctx = canvas.getContext('2d');
                let isDrawing = false;
                let hasSignature = false;
            
                // Simple canvas setup without high DPI scaling (which can cause issues)
                const rect = canvas.getBoundingClientRect();
                canvas.width = rect.width;
                canvas.height = rect.height;
            
                console.log('Canvas initialized:', canvas.width, 'x', canvas.height);
            
                // Simplified drawing functions
                const startDrawing = (e) => {
                    isDrawing = true;
                    const rect = canvas.getBoundingClientRect();
                    const x = (e.clientX || (e.touches && e.touches[0].clientX)) - rect.left;
                    const y = (e.clientY || (e.touches && e.touches[0].clientY)) - rect.top;
                    
                    ctx.beginPath();
                    ctx.moveTo(x, y);
                    console.log('Started drawing at:', x, y);
                };
            
                const draw = (e) => {
                    if (!isDrawing) return;
                    
                    const rect = canvas.getBoundingClientRect();
                    const x = (e.clientX || (e.touches && e.touches[0].clientX)) - rect.left;
                    const y = (e.clientY || (e.touches && e.touches[0].clientY)) - rect.top;
                    
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#000';
                    ctx.lineTo(x, y);
                    ctx.stroke();
                    
                    // Mark as having signature and validate immediately
                    if (!hasSignature) {
                        hasSignature = true;
                        console.log('Signature detected!');
                        validateSubmission();
                    }
                };
            
                const stopDrawing = () => {
                    if (isDrawing) {
                        isDrawing = false;
                        ctx.beginPath();
                        console.log('Stopped drawing');
                    }
                };
            
                // Mouse events
                canvas.addEventListener('mousedown', startDrawing);
                canvas.addEventListener('mousemove', draw);
                canvas.addEventListener('mouseup', stopDrawing);
                canvas.addEventListener('mouseout', stopDrawing);
            
                // Touch events (simplified)
                canvas.addEventListener('touchstart', (e) => {
                    e.preventDefault();
                    startDrawing(e);
                });
                canvas.addEventListener('touchmove', (e) => {
                    e.preventDefault();
                    draw(e);
                });
                canvas.addEventListener('touchend', (e) => {
                    e.preventDefault();
                    stopDrawing();
                });
            
                // Clear signature function
                window.clearSignature = () => {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    hasSignature = false;
                    console.log('Signature cleared');
                    validateSubmission();
                };
            
                // Terms checkbox validation
                termsCheckbox.addEventListener('change', () => {
                    console.log('Terms checkbox changed:', termsCheckbox.checked);
                    validateSubmission();
                });
            
                // Validation function with debugging
                const validateSubmission = () => {
                    const termsAccepted = termsCheckbox.checked;
                    const signatureProvided = hasSignature;
            
                    console.log('Validating submission:');
                    console.log('- Terms accepted:', termsAccepted);
                    console.log('- Signature provided:', signatureProvided);
            
                    if (termsAccepted && signatureProvided) {
                        console.log('✅ Enabling submit button');
                        submitBtn.disabled = false;
                        submitBtn.style.background = 'var(--text)';
                        submitBtn.style.color = 'white';
                        submitBtn.style.cursor = 'pointer';
                        submitBtn.onclick = () => submitFinalBooking();
                        submitMessage.textContent = 'Ready to proceed to payment';
                        submitMessage.style.color = '#28a745';
                        signatureError.textContent = '';
                    } else {
                        console.log('❌ Keeping submit button disabled');
                        submitBtn.disabled = true;
                        submitBtn.style.background = '#ccc';
                        submitBtn.style.color = '#666';
                        submitBtn.style.cursor = 'not-allowed';
                        submitBtn.onclick = null;
                        
                        if (!termsAccepted && !signatureProvided) {
                            submitMessage.textContent = 'Please accept terms and provide signature to continue';
                            signatureError.textContent = '';
                        } else if (!termsAccepted) {
                            submitMessage.textContent = 'Please accept the terms and conditions';
                            signatureError.textContent = '';
                        } else if (!signatureProvided) {
                            submitMessage.textContent = 'Please provide your signature above';
                            signatureError.textContent = 'Signature is required to proceed';
                        }
                        submitMessage.style.color = 'var(--muted)';
                    }
                };
            
                // Get signature data function
                window.getSignatureData = () => {
                    if (hasSignature) {
                        try {
                            const dataURL = canvas.toDataURL('image/png');
                            console.log('Signature data captured, length:', dataURL.length);
                            return dataURL;
                        } catch (e) {
                            console.error('Error getting signature data:', e);
                            return null;
                        }
                    }
                    return null;
                };
            
                // Initial validation
                console.log('Running initial validation');
                validateSubmission();
            

            }            
            // NEW: Dynamic quote calculation based on current booking selection
            calculateDynamicQuote(artistType, bookingType = null) {
                const isAnum = artistType === 'Anum';
                const services = this.bookingData.services;
                const event = this.bookingData.event;
                let items = [], subtotal = 0;
            
                // Travel fee calculation (same as before)
                let travelFee = 0; const region = event.region;
                if (region === 'Toronto/GTA') travelFee = 25;
                else if (region && (region.includes('Further Out')|| region.includes('1 Hour Plus'))) travelFee = 120;
                else if (region && (region.includes('Moderate Distance')|| region.includes('30 Minutes to 1 Hour'))) travelFee = 80;
                else if (region && (region.includes('Immediate Neighbors')|| region.includes('15-30 Minutes'))) travelFee = 40;
                else if (region === 'Outside GTA') travelFee = 80;
                else if (region && region.toLowerCase().includes('outside')) travelFee = 80;
                else if (region && region !== 'Toronto/GTA' && region !== '' && region !== 'Destination Wedding') travelFee = 80;
            
                // BRIDAL SERVICE with dynamic booking type
                if (event.serviceType === 'Bridal') {
                    const brideService = (services.brideService||'Both Hair & Makeup').trim();
                    
                    // Main bridal service (only if booking type includes 'bridal' or 'both')
                    if (!bookingType || bookingType === 'bridal' || bookingType === 'both') {
                        if (brideService.toLowerCase().includes('hair') && brideService.toLowerCase().includes('makeup')) { 
                            const price = isAnum ? 450 : 360; 
                            items.push({description:`Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                            subtotal += price;
                        }
                        else if (brideService.toLowerCase().includes('hair only')) { 
                            const price = isAnum ? 200 : 160; 
                            items.push({description:`Bridal Hair Only (${isAnum?'Anum':'Team'})`, price}); 
                            subtotal += price;
                        }
                        else if (brideService.toLowerCase().includes('makeup only')) { 
                            const price = isAnum ? 275 : 220; 
                            items.push({description:`Bridal Makeup Only (${isAnum?'Anum':'Team'})`, price}); 
                            subtotal += price;
                        }
                        else { 
                            const price = isAnum ? 450 : 360; 
                            items.push({description:`Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                            subtotal += price;
                        }
            
                        // Add-ons for bridal
                        if (services.brideVeilSetting === 'Yes') { 
                            items.push({description:'Veil/Dupatta Setting', price:50}); 
                            subtotal += 50;
                        }
                        if (services.brideExtensions === 'Yes') { 
                            items.push({description:'Hair Extensions Installation', price:30}); 
                            subtotal += 30;
                        }
            
                        // Bridal party
                        if (services.bpHM > 0) { 
                            const t = services.bpHM * 200; 
                            items.push({description:`Bridal Party Hair & Makeup x ${services.bpHM}`, price:t}); 
                            subtotal += t;
                        }
                        if (services.bpM > 0) { 
                            const t = services.bpM * 100; 
                            items.push({description:`Bridal Party Makeup Only x ${services.bpM}`, price:t}); 
                            subtotal += t;
                        }
                        if (services.bpH > 0) { 
                            const t = services.bpH * 100; 
                            items.push({description:`Bridal Party Hair Only x ${services.bpH}`, price:t}); 
                            subtotal += t;
                        }
                    }
            
                    // Trial service (only if booking type is 'both' - REMOVED standalone trial)
                    if (bookingType === 'both') {
                        const trial = (services.trialService || 'Both Hair & Makeup').trim();
                        if (trial.toLowerCase().includes('makeup') && trial.toLowerCase().includes('hair')) { 
                            const p = isAnum ? 250 : 200; 
                            items.push({description:'Bridal Trial (Hair & Makeup)', price:p}); 
                            subtotal += p;
                        } 
                        else if (trial.toLowerCase().includes('makeup only')) { 
                            const p = isAnum ? 150 : 120; 
                            items.push({description:'Bridal Trial (Makeup Only)', price:p}); 
                            subtotal += p;
                        }
                        else if (trial.toLowerCase().includes('hair only')) { 
                            const p = isAnum ? 150 : 120; 
                            items.push({description:'Bridal Trial (Hair Only)', price:p}); 
                            subtotal += p;
                        }
                    }
                }
                
                // SEMI BRIDAL SERVICE (no trials)
                else if (event.serviceType === 'Semi Bridal') {
                    const brideService = (services.brideService||'Both Hair & Makeup').trim();
                    if (brideService.toLowerCase().includes('hair') && brideService.toLowerCase().includes('makeup')) { 
                        const price = isAnum ? 350 : 280; 
                        items.push({description:`Semi Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else if (brideService.toLowerCase().includes('hair only')) { 
                        const price = isAnum ? 175 : 140; 
                        items.push({description:`Semi Bridal Hair Only (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else if (brideService.toLowerCase().includes('makeup only')) { 
                        const price = isAnum ? 225 : 180; 
                        items.push({description:`Semi Bridal Makeup Only (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
                    else { 
                        const price = isAnum ? 350 : 280; 
                        items.push({description:`Semi Bridal Makeup & Hair (${isAnum?'Anum':'Team'})`, price}); 
                        subtotal += price;
                    }
            
                    // Add-ons for semi bridal
                    if (services.brideVeilSetting === 'Yes') { 
                        items.push({description:'Veil/Dupatta Setting', price:50}); 
                        subtotal += 50;
                    }
                    if (services.brideExtensions === 'Yes') { 
                        items.push({description:'Hair Extensions Installation', price:30}); 
                        subtotal += 30;
                    }
                    
                    // Bridal party for semi bridal
                    if (services.bpHM > 0) { 
                        const t = services.bpHM * 200; 
                        items.push({description:`Bridal Party Hair & Makeup x ${services.bpHM}`, price:t}); 
                        subtotal += t;
                    }
                    if (services.bpM > 0) { 
                        const t = services.bpM * 100; 
                        items.push({description:`Bridal Party Makeup Only x ${services.bpM}`, price:t}); 
                        subtotal += t;
                    }
                    if (services.bpH > 0) { 
                        const t = services.bpH * 100; 
                        items.push({description:`Bridal Party Hair Only x ${services.bpH}`, price:t}); 
                        subtotal += t;
                    }
                    
                    // Missing bridal party add-ons
                    if (services.bpSetting > 0) { 
                        const totalPrice = services.bpSetting * 20; 
                        items.push({description:`Bridal Party Dupatta/Veil Setting x ${services.bpSetting}`, price: totalPrice}); 
                        subtotal += totalPrice;
                    }
                    if (services.bpHExtensions > 0) { 
                        const totalPrice = services.bpHExtensions * 20; 
                        items.push({description:`Bridal Party Hair Extensions Installation x ${services.bpHExtensions}`, price: totalPrice}); 
                        subtotal += totalPrice;
                    }
                    if (services.bpAirbrush > 0) { 
                        const totalPrice = services.bpAirbrush * 50; 
                        items.push({description:`Bridal Party Airbrush Makeup x ${services.bpAirbrush}`, price: totalPrice}); 
                        subtotal += totalPrice;
                    }
                }
                else if (event.serviceType === 'Non-Bridal / Photoshoot') {
                    // Non-bridal services (existing logic is mostly correct)
                    if (services.nbEveryoneBoth === 'Yes') {
                        const pricePerPerson = isAnum ? 250 : 200;
                        if (services.nbCount > 0) {
                            const totalPrice = services.nbCount * pricePerPerson;
                            items.push({description: `Non-Bridal Hair & Makeup (${isAnum?'Anum':'Team'}) x ${services.nbCount}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                    } else {
                        if (services.nbBoth > 0) {
                            const pricePerPerson = isAnum ? 250 : 200;
                            const totalPrice = services.nbBoth * pricePerPerson;
                            items.push({description: `Non-Bridal Hair & Makeup (${isAnum?'Anum':'Team'}) x ${services.nbBoth}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                        if (services.nbMakeup > 0) {
                            const pricePerPerson = isAnum ? 140 : 110;
                            const totalPrice = services.nbMakeup * pricePerPerson;
                            items.push({description: `Non-Bridal Makeup Only (${isAnum?'Anum':'Team'}) x ${services.nbMakeup}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                        if (services.nbHair > 0) {
                            const pricePerPerson = isAnum ? 130 : 110;
                            const totalPrice = services.nbHair * pricePerPerson;
                            items.push({description: `Non-Bridal Hair Only (${isAnum?'Anum':'Team'}) x ${services.nbHair}`, price: totalPrice});
                            subtotal += totalPrice;
                        }
                    }
            
                    // Non-Bridal Add-ons
                    if (services.nbExtensions > 0) {
                        const totalPrice = services.nbExtensions * 20;
                        items.push({description: `Hair Extensions Installation x ${services.nbExtensions}`, price: totalPrice});
                        subtotal += totalPrice;
                    }
                    if (services.nbJewelry > 0) {
                        const totalPrice = services.nbJewelry * 20;
                        items.push({description: `Jewelry/Dupatta Setting x ${services.nbJewelry}`, price: totalPrice});
                        subtotal += totalPrice;
                    }
                    if (services.nbAirbrush > 0) {
                        const totalPrice = services.nbAirbrush * 50;
                        items.push({description: `Airbrush Makeup x ${services.nbAirbrush}`, price: totalPrice});
                        subtotal += totalPrice;
                    }
                }
            
                // Add travel fee
                if (travelFee > 0) { 
                    items.push({description: region === 'Toronto/GTA' ? 'Travel Fee (Toronto/GTA)' : `Travel Fee (${region})`, price: travelFee}); 
                    subtotal += travelFee;
                }
                
                const hst = subtotal * 0.13; 
                const total = subtotal + hst;
                return { items, subtotal, hst, total };
            }
        }

        // Global function to submit final booking (prepare for payment) - UPDATED WITH SIGNATURE
        async function submitFinalBooking() {
            const submitBtn = document.getElementById('submitBookingBtn');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<span class="spinner"></span>Preparing booking...';
            submitBtn.disabled = true;
        
            // Collect all booking data
            const eventDate = document.getElementById('eventDate') ? document.getElementById('eventDate').value : '';
            
            // Use DYNAMIC quote calculation based on current booking selection
            const artistName = window.currentBooking.artistType === 'anum' ? 'Anum' : 'Team';
            const quote = window.quoteInstance.calculateDynamicQuote(artistName, window.currentBooking.bookingType);
            
            const svc = (window.quoteInstance.bookingData.event.serviceType || '').toLowerCase();
            const depositRate = (svc.includes('non-bridal') || svc.includes('non bridal')) ? 0.5 : 0.3;
            const depositAmount = quote.total * depositRate; // Calculate on subtotal, not total
        
            // Capture signature data
            const signatureData = window.getSignatureData ? window.getSignatureData() : null;
        
            const bookingData = {
                booking_id: window.quoteInstance.bookingData.client.bookingId,
                client_name: `${window.quoteInstance.bookingData.client.firstName} ${window.quoteInstance.bookingData.client.lastName}`,
                client_email: window.quoteInstance.bookingData.client.email,
                client_phone: window.quoteInstance.bookingData.client.phone,
                artist_type: window.currentBooking.artistType,
                booking_type: window.currentBooking.bookingType,
                event_date: eventDate,
                trial_date: window.quoteInstance.formData.trialDate || '',
                ready_time: window.quoteInstance.formData.readyTime,
                street_address: window.quoteInstance.formData.streetAddress,
                city: window.quoteInstance.formData.city,
                province: window.quoteInstance.formData.province,
                postal_code: window.quoteInstance.formData.postalCode,
                service_type: window.quoteInstance.bookingData.event.serviceType,
                region: window.quoteInstance.bookingData.event.region,
                quote_total: quote.total,
                deposit_amount: depositAmount,
                client_signature: signatureData,
                terms_accepted: true,
                signature_date: new Date().toISOString(),
                
                // ADD ALL THE DETAILED SERVICE DATA:
                bride_service: window.quoteInstance.bookingData.services.brideService || '',
                trial_service: window.quoteInstance.bookingData.services.trialService || '',
                bride_veil_setting: window.quoteInstance.bookingData.services.brideVeilSetting || '',
                bride_extensions: window.quoteInstance.bookingData.services.brideExtensions || '',
                needs_trial: window.quoteInstance.bookingData.services.needsTrial || '',
                
                // Bridal party services
                bpHM: window.quoteInstance.bookingData.services.bpHM || 0,
                bpM: window.quoteInstance.bookingData.services.bpM || 0,
                bpH: window.quoteInstance.bookingData.services.bpH || 0,
                bpSetting: window.quoteInstance.bookingData.services.bpSetting || 0,
                bpHExtensions: window.quoteInstance.bookingData.services.bpHExtensions || 0,
                bpAirbrush: window.quoteInstance.bookingData.services.bpAirbrush || 0,
                
                // Non-bridal services
                nbCount: window.quoteInstance.bookingData.services.nbCount || 0,
                nbBoth: window.quoteInstance.bookingData.services.nbBoth || 0,
                nbMakeup: window.quoteInstance.bookingData.services.nbMakeup || 0,
                nbHair: window.quoteInstance.bookingData.services.nbHair || 0,
                nbExtensions: window.quoteInstance.bookingData.services.nbExtensions || 0,
                nbJewelry: window.quoteInstance.bookingData.services.nbJewelry || 0,
                nbAirbrush: window.quoteInstance.bookingData.services.nbAirbrush || 0
            };
        
            // Debug: Log all the collected data
            console.log('Preparing booking for payment (not saving to DB yet):', bookingData);
            console.log('Signature captured:', signatureData ? 'YES' : 'NO');
        
            // Step 1: Prepare booking data (store in session, not database)
            const result = await window.quoteInstance.submitBookingToDatabase(bookingData);
            
            if (result.success) {
                console.log('✅ Booking prepared for payment:', result.message);
                
                // Step 2: Create Stripe payment
                const paymentData = {
                    amount: depositAmount.toFixed(2),
                    currency: 'CAD',
                    booking_id: bookingData.booking_id,
                    client_email: bookingData.client_email,
                    client_name: bookingData.client_name,
                    description: `${window.currentBooking.artistName} - ${window.quoteInstance.getServiceDescription(window.currentBooking.bookingType)} Deposit`
                };
                
                console.log('Creating Stripe payment with data:', paymentData);
                
                // Show payment loading state
                submitBtn.innerHTML = '<span class="spinner"></span>Setting up payment...';
                
                const paymentResult = await window.quoteInstance.createStripePayment(paymentData);
                
                if (paymentResult.success) {
                    // Show test mode notice if applicable
                    if (paymentResult.test_mode) {
                        submitBtn.innerHTML = '<span class="spinner"></span>Redirecting to TEST payment...';
                        console.log('⚠️ TEST MODE: Use test card numbers like 4242424242424242');
                        console.log('💡 Note: Database will be updated only after successful payment');
                    } else {
                        submitBtn.innerHTML = '<span class="spinner"></span>Redirecting to payment...';
                    }
                    
                    // Add a small delay to ensure user sees the message
                    setTimeout(() => {
                        console.log('Redirecting to Stripe payment:', paymentResult.payment_url);
                        console.log('🔄 Booking will be saved to database only on successful payment');
                        // Open in same window
                        window.location.href = paymentResult.payment_url;
                    }, 1500);
                } else {
                    // Payment creation failed, restore button
                    console.error('Payment creation failed:', paymentResult.message);
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    alert('Payment setup failed: ' + paymentResult.message);
                }
                
                // Clear stored form data only if payment creation succeeded
                if (paymentResult.success) {
                    sessionStorage.removeItem('bookingFormData');
                }
            } else {
                // Booking preparation failed, restore button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                alert('Error preparing booking: ' + result.message);
                console.error('Booking preparation failed:', result);
            }
        }

        // Global function to handle book now action
        function handleBookNow() {
            // Check service type to determine flow
            const serviceType = window.quoteInstance.bookingData.event.serviceType;
            const anumQuote = window.quoteInstance.calculateQuote('Anum');
            const teamQuote = window.quoteInstance.calculateQuote('Team');
            if (serviceType === 'Bridal') {
                // BRIDAL FLOW: Artist selection -> Booking type selection
                document.getElementById('content').innerHTML = `
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Choose Your Artist</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 16px;">
                            Select which artist you'd like to book with for your bridal service
                        </p>
                    </div>

                    <div style="max-width: 600px; margin: 0 auto;">
                        <div class="booking-type-options" style="justify-content: center; gap: 20px;">
                            <label onclick="selectArtist('anum'); return false;" style="cursor: pointer; min-width: 250px; padding: 20px; text-align: center;">
                                <span style="font-size: 16px;">👑 Book with Anum</span><br>
                                <small style="color: var(--muted); margin-top: 5px; display: block;">Total: $${anumQuote.total.toFixed(2)} CAD</small>
                            </label>
                            <label onclick="selectArtist('team'); return false;" style="cursor: pointer; min-width: 250px; padding: 20px; text-align: center;">
                                <span style="font-size: 16px;">👥 Book with Team</span><br>
                                <small style="color: var(--muted); margin-top: 5px; display: block;">Total: $${teamQuote.total.toFixed(2)} CAD</small>
                            </label>
                        </div>
                        
                        <div style="text-align: center; margin-top: 20px;">
                            <button onclick="goBackToQuotes()" style="padding: 12px 24px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text);">
                                ← Back to Quotes
                            </button>
                        </div>
                    </div>

                    <div id="artistMessage" style="margin-top: 20px; text-align: center; font-weight: 600;"></div>
                `;
            } else {
                // SEMI BRIDAL & NON-BRIDAL FLOW: Direct to artist selection (no booking type selection)
                document.getElementById('content').innerHTML = `
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Choose Your Artist</h2>
                        <p style="margin: 0; color: var(--muted); font-size: 16px;">
                            Select which artist you'd like to book with for your ${serviceType}
                        </p>
                    </div>

                    <div style="max-width: 600px; margin: 0 auto;">
                        <div class="booking-type-options" style="justify-content: center; gap: 20px;">
                            <label onclick="selectDirectServiceArtist('anum'); return false;" style="cursor: pointer; min-width: 250px; padding: 20px;">
                                <span style="font-size: 16px;">👑 Book with Anum</span>
                                <small style="color: var(--muted); margin-top: 5px; display: block;">Total: $${anumQuote.total.toFixed(2)} CAD</small>
                            </label>
                            <label onclick="selectDirectServiceArtist('team'); return false;" style="cursor: pointer; min-width: 250px; padding: 20px;">
                                <span style="font-size: 16px;">👥 Book with Team</span>
                                <small style="color: var(--muted); margin-top: 5px; display: block;">Total: $${teamQuote.total.toFixed(2)} CAD</small>
                            </label>
                        </div>
                        
                        <div style="text-align: center; margin-top: 20px;">
                            <button onclick="goBackToQuotes()" style="padding: 12px 24px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text);">
                                ← Back to Quotes
                            </button>
                        </div>
                    </div>

                    <div id="artistMessage" style="margin-top: 20px; text-align: center; font-weight: 600;"></div>
                `;
            }
        }
        
        // Global function to handle schedule a call action
        function handleScheduleCall() {
            // Replace entire content with Calendly widget
            document.getElementById('content').innerHTML = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">Schedule Your Consultation</h2>
                    <p style="margin: 0; color: var(--muted); font-size: 16px;">
                        Choose a convenient time to discuss your needs
                    </p>
                </div>

                <div style="background: white; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin-bottom: 20px;">
                    <div class="calendly-inline-widget" data-url="https://calendly.com/ismaanwar-ia?hide_landing_page_details=1" style="min-width:320px;height:630px;"></div>
                </div>
                
                <div style="text-align: center;">
                    <button onclick="goBackToQuotes()" style="padding: 12px 24px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text);">
                        ← Back to Quotes
                    </button>
                </div>
            `;
            
            // Load Calendly script if not already loaded
            if (!window.Calendly) {
                const script = document.createElement('script');
                script.src = 'https://assets.calendly.com/assets/external/widget.js';
                script.async = true;
                document.head.appendChild(script);
            }
        }

        // Function to go back to booking options
        function goBackToBookingOptions() {
            // Go back to the booking options for the selected artist
            if (window.selectedArtist) {
                showBookingOptions(window.selectedArtist.type, window.selectedArtist.name);
            } else {
                // If no artist selected, go back to artist selection
                handleBookNow();
            }
        }

        // Function to handle artist selection for BRIDAL ONLY
        function selectArtist(artistType) {
            const artistName = artistType === 'anum' ? 'Anum' : 'Team';
            
            // Remove previous selection styling
            document.querySelectorAll('.booking-type-options label').forEach(btn => {
                btn.style.background = 'white';
                btn.style.color = '#111111';
                const span = btn.querySelector('span');
                if (span) span.style.color = '#111111';
            });
            
            // Highlight selected artist
            const selectedLabel = event.target.closest('label');
            selectedLabel.style.background = '#111111';
            selectedLabel.style.color = '#fff';
            const selectedSpan = selectedLabel.querySelector('span');
            if (selectedSpan) selectedSpan.style.color = '#fff';
            
            // Show "What would you like to book with..." section
            setTimeout(() => {
                showBookingOptions(artistType, artistName);
            }, 300);
        }

        // NEW: Function to handle artist selection for SEMI BRIDAL & NON-BRIDAL
        function selectDirectServiceArtist(artistType) {
            const artistName = artistType === 'anum' ? 'Anum' : 'Team';
            
            // Remove previous selection styling
            document.querySelectorAll('.booking-type-options label').forEach(btn => {
                btn.style.background = 'white';
                btn.style.color = '#111111';
                const span = btn.querySelector('span');
                if (span) span.style.color = '#111111';
            });
            
            // Highlight selected artist
            const selectedLabel = event.target.closest('label');
            selectedLabel.style.background = '#111111';
            selectedLabel.style.color = '#fff';
            const selectedSpan = selectedLabel.querySelector('span');
            if (selectedSpan) selectedSpan.style.color = '#fff';
            
            // Show direct service booking (skip booking type selection)
            setTimeout(() => {
                window.quoteInstance.selectDirectService(artistType);
            }, 300);
        }

        // Function to show booking options (BRIDAL ONLY)
            function showBookingOptions(artistType, artistName) {
            // Replace content with booking options (REMOVED TRIAL ONLY)
            document.getElementById('content').innerHTML = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--text);">What would you like to book with ${artistName}?</h2>
                    <p style="margin: 0; color: var(--muted); font-size: 16px;">
                        Choose the service you'd like to book
                    </p>
                </div>
        
                <div style="max-width: 600px; margin: 0 auto;">
                    <div class="booking-type-options" style="flex-direction: column; align-items: center; gap: 16px;">
                        <label style="min-width: 300px; padding: 20px;">
                            <input type="radio" name="bookingType" value="bridal" style="margin-right: 12px;" onchange="window.quoteInstance.selectBookingType('${artistType}', 'bridal'); window.quoteInstance.updateRadioSelection(this)">
                            <span style="font-size: 16px;">👑 Bridal Service</span>
                        </label>
                        <label style="min-width: 300px; padding: 20px;">
                            <input type="radio" name="bookingType" value="both" style="margin-right: 12px;" onchange="window.quoteInstance.selectBookingType('${artistType}', 'both'); window.quoteInstance.updateRadioSelection(this)">
                            <span style="font-size: 16px;">🎉 Bridal + Trial</span>
                        </label>
                    </div>
                    
                    <div style="text-align: center; margin-top: 30px;">
                        <button onclick="goBackToArtistSelection()" style="padding: 12px 24px; background: white; border: 2px solid var(--text); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--text);">
                            ← Back to Artist Selection
                        </button>
                    </div>
                </div>
        
                <div id="bookingMessage" style="margin-top: 20px; text-align: center; font-weight: 600;"></div>
            `;
        
            // Store selected artist for later use
            window.selectedArtist = { type: artistType, name: artistName };
        }

        // Function to go back to time selection
        function goBackToTimeSelection() {
            // Check if this is a bridal service or direct service
            const serviceType = window.quoteInstance.bookingData.event.serviceType;
            
            if (serviceType === 'Bridal' && window.currentBooking) {
                // Go back to the booking form with time selection
                window.quoteInstance.selectBookingType(
                    window.currentBooking.artistType, 
                    window.currentBooking.bookingType
                );
            } else {
                // Go back to direct service form
                window.quoteInstance.selectDirectService(window.currentBooking.artistType);
            }
        }

        // Function to go back to quotes
        function goBackToQuotes() {
            window.quoteInstance.displayBookingData();
        }

        // Function to go back to address
        function goBackToAddress() {
            window.quoteInstance.showAddressCollection();
        }

        // Function to go back to artist selection
        function goBackToArtistSelection() {
            handleBookNow();
        }

        document.addEventListener('DOMContentLoaded', function(){ 
            window.quoteInstance = new QuotePageFromURL(); 
        });
    </script>
</body>
</html>