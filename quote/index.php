<?php
require_once __DIR__ . '/hubspot-integration.php';
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';


error_reporting(E_ALL);
ini_set('display_errors', 1);




if (isset($_GET['test_db'])) {
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "✅ Database connection OK<br>";
        
        $result = $pdo->query("SELECT COUNT(*) FROM {$table}");
        echo "✅ Table access OK - " . $result->fetchColumn() . " records found<br>";
        
        $result = $pdo->query("DESCRIBE {$table}");
        echo "✅ Table structure:<br>";
        while ($row = $result->fetch()) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Database Error: " . $e->getMessage();
    }
    exit;
}
// Add this at the top to find your error log location
if (isset($_GET['show_error_log_path'])) {
    echo "PHP Error Log: " . ini_get('error_log') . "<br>";
    echo "Server Error Log Path: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
    echo "Current Directory: " . __DIR__ . "<br>";
    
    // Test the debug function
    debugLog("TEST MESSAGE - Finding debug output location");
    echo "Debug message sent to error log. Check your error logs now.";
    exit;
}
// SECURE: Complete booking system with environment variables
error_reporting(E_ALL);
ini_set('display_errors', 1);

function writeDebugFile($message) {
    $debugFile = __DIR__ . '/payment_debug.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($debugFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}


// Load environment variables from .env file
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception('.env file not found. Please create .env file with required configuration.');
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load environment variables
try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    die('Configuration Error: ' . $e->getMessage());
}

// Helper function to get environment variable with fallback
function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

// Validate required environment variables
$requiredEnvVars = [
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PREFIX',
    'STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY','HUBSPOT_API_KEY' 
];

foreach ($requiredEnvVars as $var) {
    if (empty(env($var))) {
        die("Configuration Error: Missing required environment variable: {$var}");
    }
}

// CONFIG - Now using environment variables
$db = [
    'host'   => env('DB_HOST'),
    'name'   => env('DB_NAME'),
    'user'   => env('DB_USER'),
    'pass'   => env('DB_PASS'),
    'prefix' => env('DB_PREFIX', 'wp_')
];

$table = $db['prefix'] . 'bridal_bookings';



// Stripe configuration - Now using environment variables
$stripeConfig = [
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY')
];

// Validate Stripe keys format
if (strpos($stripeConfig['secret_key'], 'sk_') !== 0) {
    die('Configuration Error: Invalid Stripe secret key format');
}
if (strpos($stripeConfig['publishable_key'], 'pk_') !== 0) {
    die('Configuration Error: Invalid Stripe publishable key format');
}

try {
  $pdo = new PDO(
    'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (PDOException $e) {
  echo '<!doctype html><meta charset="utf-8"><h1>Database Error</h1><p>Cannot connect to database</p>';
  exit;
}

// =============================================================================
// ENHANCED LOGGING AND CONTRACT GENERATION FUNCTIONS
// =============================================================================

// Function to log with timestamp
function debugLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] PAYMENT_DEBUG: $message");
}

// START SESSION EARLY
session_start();
debugLog("Session started. Session ID: " . session_id());

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
        
        // REPLACE your $paymentData array (lines 264-271) with this:

        // Get booking data to check for applied coupons
        $bookingId = $_POST['booking_id'] ?? '';
        $originalAmount = floatval($_POST['amount']);
        
        // Check if coupon was applied to this booking
        $couponDiscount = 0;
        $finalAmount = $originalAmount;
        
        if ($bookingId) {
            try {
                $stmt = $pdo->prepare("SELECT coupon_discount, quote_total FROM wp_bridal_bookings WHERE unique_id = ?");
                $stmt->execute([$bookingId]);
                $bookingData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($bookingData && $bookingData['coupon_discount'] > 0) {
                    $couponDiscount = floatval($bookingData['coupon_discount']);
                    $finalAmount = $originalAmount - $couponDiscount;
                    
                    // Log for debugging
                    error_log("Coupon applied: Original=$originalAmount, Discount=$couponDiscount, Final=$finalAmount");
                }
            } catch (Exception $e) {
                error_log("Error checking coupon: " . $e->getMessage());
                // Continue with original amount if there's an error
            }
        }
        
        $paymentData = [
            'amount' => $finalAmount, // Use discounted amount instead of original
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
            'line_items[0][price_data][unit_amount]' => intval($finalAmount * 100), // Use final amount after coupon
            'line_items[0][quantity]' => 1,
            'mode' => 'payment',
            'allow_promotion_codes' => 'true',
            'success_url' => $currentDomain . $currentPath . '/index.php?payment_success=1&session_id={CHECKOUT_SESSION_ID}&booking_id=' . urlencode($paymentData['booking_id']) . '&coupon_applied=' . ($couponDiscount > 0 ? '1' : '0'),
            'cancel_url' => $currentDomain . $currentPath . '/index.php?payment_cancel=1&booking_id=' . urlencode($paymentData['booking_id']),
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
        
        echo json_encode([
            'success' => true,
            'payment_url' => $sessionData['url'],
            'session_id' => $sessionData['id'],
            'payment_data' => $paymentData,
            'test_mode' => $isTestMode
        ]);
        
    } catch (Exception $e) {
        debugLog("Stripe payment creation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Payment creation failed: ' . $e->getMessage()]);
    }
    exit;
}

// Handle booking preparation (store in session, not database yet)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_booking') {
    header('Content-Type: application/json');
    
    debugLog("Booking preparation request received at " . date('Y-m-d H:i:s'));
    debugLog("Will store in session, not database (until payment success with contract generation)");
    
    $requiredFields = ['client_name', 'client_email', 'artist_type'];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $missingFields[] = $field;
        }
    }
    
    // Don't generate new ID - booking_id must be provided from WordPress plugin
    if (empty($_POST['booking_id'])) {
        debugLog("❌ NO BOOKING ID PROVIDED");
        echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
        exit;
    }
    
    $bookingId = $_POST['booking_id'];
    debugLog("Using existing booking ID: " . $bookingId);
    
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
            'appointment_time' => !empty($_POST['appointment_time']) ? $_POST['appointment_time'] : '',
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
            'signature_date' => $_POST['signature_date'] ?? null
        ];
        // Calculate appointment time (2 hours before ready time) for HubSpot
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

// Handle booking update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_booking') {
    header('Content-Type: application/json');
    
    try {
        $bookingId = $_POST['booking_id'] ?? '';
        
        if (empty($bookingId)) {
            echo json_encode(['success' => false, 'message' => 'Missing booking ID']);
            exit;
        }
        
        $updateFields = [];
        $updateValues = [];
        
        $fieldMappings = [
            'artist_type' => 'artist_type',
            'ready_time' => 'ready_time',
            'street_address' => 'street_address',
            'city' => 'city',
            'province' => 'province',
            'postal_code' => 'postal_code',
            'quote_total' => 'quote_total',
            'deposit_amount' => 'deposit_amount'
        ];
        
        foreach ($fieldMappings as $postField => $dbField) {
            if (isset($_POST[$postField])) {
                $updateFields[] = "{$dbField} = ?";
                
                if ($postField === 'quote_total' || $postField === 'deposit_amount') {
                    $updateValues[] = floatval($_POST[$postField]);
                } else {
                    $updateValues[] = $_POST[$postField];
                }
            }
        }
        
        if (empty($updateFields)) {
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit;
        }
        
        $updateValues[] = $bookingId;
        $updateSQL = "UPDATE {$table} SET " . implode(', ', $updateFields) . " WHERE unique_id = ?";
        
        $pdo = new PDO(
            'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $updateStmt = $pdo->prepare($updateSQL);
        $updateResult = $updateStmt->execute($updateValues);
        
        if ($updateResult && $updateStmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'message' => 'Booking updated successfully',
                'booking_id' => $bookingId
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No changes made or booking not found']);
        }
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}


// If you want to create coupons programmatically:
function createStripeCoupon($name, $type, $value) {
    global $stripeConfig;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/coupons');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $postData = [
        'name' => $name,
        'duration' => 'once'
    ];
    
    if ($type === 'percent') {
        $postData['percent_off'] = $value;
    } else {
        $postData['amount_off'] = $value * 100; // Convert to cents
        $postData['currency'] = 'cad';
    }
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripeConfig['secret_key'],
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $coupon = json_decode($response, true);
        return $coupon['id'];
    }
    return false;
}

function createStripePromoCode($couponId, $code) {
    global $stripeConfig;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/promotion_codes');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'coupon' => $couponId,
        'code' => $code,
        'max_redemptions' => 1000 // Set usage limit
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripeConfig['secret_key'],
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

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
                
                // GET DISCOUNT INFORMATION FIRST
                $discountAmount = floatval($bookingData['discount_amount'] ?? 0);
                $promoCode = $bookingData['promo_code'] ?? '';
                $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
                
                // Calculate totals with discount handling
                $currentTotal = floatval($bookingData['quote_total'] ?? 0);
                $originalTotal = $hasDiscount ? floatval($bookingData['original_total'] ?? ($currentTotal + $discountAmount)) : $currentTotal;
                $total = $currentTotal; // Use current (discounted) total
                
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
                
                // Calculate service charges (using current discounted total)
                $totalBeforeHST = $total / 1.13;
                $serviceCharges = $totalBeforeHST - $travelCharges;
                
                // Determine service type and deposit rate
                $serviceType = $bookingData['service_type'] ?? 'Bridal';
                $depositRate = (strpos(strtolower($serviceType), 'non-bridal') !== false || strpos(strtolower($serviceType), 'photoshoot') !== false) ? 0.5 : 0.3;
                $depositPercentage = ($depositRate === 0.5) ? '50%' : '30%';
                
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
                $pdf->Cell(0,6,'Dear ' . ($clientName ?? '') . ',',0,1);
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
                $pdf->Cell(100, 8, $serviceType, 1, 1, 'L', true);
                
                // Number of Services
                $pdf->Cell(80, 8, 'Number of Services', 1, 0, 'L', true);
                $numberOfServices = !empty($bookingData['trial_date']) ? '2 (Trial + Event)' : '1';
                $pdf->Cell(100, 8, $numberOfServices, 1, 1, 'L', true);
                
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
                $startTime = $appointmentTime ?: ($bookingData['appointment_time'] ?? 'TBD');
                $pdf->Cell(100, 8, $startTime, 1, 1, 'L', true);
                
                // To Be Ready Time
                $pdf->Cell(80, 8, 'To Be Ready Time', 1, 0, 'L', true);
                $readyTime = $readyTimeFormatted ?: 'TBD';
                if (!empty($bookingData['ready_time']) && !$readyTimeFormatted) {
                    $readyTime = date('g:i A', strtotime($bookingData['ready_time']));
                }
                $pdf->Cell(100, 8, $readyTime, 1, 1, 'L', true);
                
                // ADD DISCOUNT INFORMATION IF APPLICABLE
                if ($hasDiscount) {
                    // $pdf->SetFont('Arial','B',10);
                    // $pdf->SetFillColor(255, 243, 205); // Light yellow background
                    // $pdf->Cell(80, 8, 'Promotional Code', 1, 0, 'L', true);
                    // $pdf->Cell(100, 8, $promoCode, 1, 1, 'L', true);
                    
                    $pdf->SetFont('Arial','',10);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Cell(80, 8, 'Original Total', 1, 0, 'L', true);
                    $pdf->Cell(100, 8, '$' . number_format($originalTotal, 2), 1, 1, 'L', true);
                    
                    $pdf->SetFillColor(220, 252, 231); // Light green background
                    $pdf->Cell(80, 8, 'Discount Amount', 1, 0, 'L', true);
                    $pdf->Cell(100, 8, '-$' . number_format($discountAmount, 2), 1, 1, 'L', true);
                    
                    $pdf->SetFillColor(255, 255, 255);
                }
                
                // Service Charges (using discounted total)
                $pdf->Cell(80, 8, 'Service Charges', 1, 0, 'L', true);
                $pdf->Cell(100, 8, '$' . number_format($serviceCharges, 2), 1, 1, 'L', true);
                
                // Travel Charges
                $pdf->Cell(80, 8, 'Travel Charges', 1, 0, 'L', true);
                $pdf->Cell(100, 8, '$' . number_format($travelCharges, 2), 1, 1, 'L', true);
                
                // Total (highlighted row) - show final discounted amount
                $pdf->SetFont('Arial','B',10);
                $pdf->SetFillColor(248, 249, 250);
                $totalLabel = $hasDiscount ? 'Total (After Discount)' : 'Total';
                $pdf->Cell(80, 10, $totalLabel, 1, 0, 'L', true);
                $pdf->Cell(100, 10, '$' . number_format($total, 2), 1, 1, 'L', true);
                
                $pdf->Ln(10);
                
                // Price & Booking section with discount information
                $pdf->SetFont('Arial','B',12);
                $pdf->Cell(0,8,'Price & Booking',0,1);
                $pdf->SetFont('Arial','',9);
                
                if ($hasDiscount) {
                    $savings = $originalTotal - $total;
                    $savingsPercentage = $originalTotal > 0 ? round(($savings / $originalTotal) * 100, 1) : 0;
                    
                    $priceText1 = 'The original price for the makeup and hair services was $' . number_format($originalTotal, 2) . '. With your promotional code "' . $promoCode . '", you saved $' . number_format($savings, 2) . ' (' . $savingsPercentage . '%). Your final total is $' . number_format($total, 2) . '. A non-refundable deposit of ' . $depositPercentage . ' is required to secure your booking. The remaining balance will be due on the day of the event.';
                } else {
                    $priceText1 = 'The total price for the makeup and hair services is $' . number_format($total, 2) . '. A non-refundable deposit of ' . $depositPercentage . ' is required to secure your booking. The remaining balance will be due on the day of the event.';
                }
                
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
                
                // Signatures section
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

                // Save PDF
                $pdf->Output($contractPath, 'F');
                debugLog("Complete PDF contract generated successfully with discount information");
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

// Add the missing generateContractHTML function for fallback
function generateContractHTML($bookingData) {
    // Get discount information
    $discountAmount = floatval($bookingData['discount_amount'] ?? 0);
    $promoCode = $bookingData['promo_code'] ?? '';
    $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
    
    // Calculate totals with discount handling
    $currentTotal = floatval($bookingData['quote_total'] ?? $bookingData['price'] ?? 0);
    $originalTotal = $hasDiscount ? floatval($bookingData['original_total'] ?? ($currentTotal + $discountAmount)) : $currentTotal;
    
    // Basic contract HTML structure
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Contract - Looks By Anum</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .discount-highlight { background: #d1ecf1; padding: 15px; border-radius: 6px; margin: 15px 0; text-align: center; color: #0c5460; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { padding: 8px 12px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f5f5f5; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h1>looks BY ANUM</h1>
        <h2>Contract for Makeup and Hair Services</h2>
        <p>Contract Date: ' . date('F j, Y') . '</p>
    </div>
    
    <h3>Client Information</h3>
    <table>
        <tr><th>Name</th><td>' . htmlspecialchars($bookingData['client_name'] ?? $bookingData['name'] ?? '') . '</td></tr>
        <tr><th>Email</th><td>' . htmlspecialchars($bookingData['client_email'] ?? $bookingData['email'] ?? '') . '</td></tr>
        <tr><th>Service</th><td>' . htmlspecialchars($bookingData['service_type'] ?? 'Bridal') . '</td></tr>';
        
    if ($hasDiscount) {
        $savings = $originalTotal - $currentTotal;
        $html .= '
        <tr><th>Original Total</th><td>$' . number_format($originalTotal, 2) . '</td></tr>
        <tr><th>Promotional Code</th><td>' . htmlspecialchars($promoCode) . '</td></tr>
        <tr><th>Discount</th><td>-$' . number_format($discountAmount, 2) . '</td></tr>
        <tr><th>Final Total</th><td><strong>$' . number_format($currentTotal, 2) . '</strong></td></tr>';
        
        $html .= '</table><div class="discount-highlight">
            <strong>You saved $' . number_format($savings, 2) . ' with promotional code "' . htmlspecialchars($promoCode) . '"!</strong>
        </div>';
    } else {
        $html .= '<tr><th>Total Amount</th><td><strong>$' . number_format($currentTotal, 2) . '</strong></td></tr>';
        $html .= '</table>';
    }
    
    $html .= '
    <h3>Terms and Conditions</h3>
    <p>By accepting this contract, the client agrees to all standard terms and conditions for makeup and hair services provided by Looks By Anum.</p>
    
    <div style="margin-top: 30px; text-align: center; border-top: 1px solid #ddd; padding-top: 15px;">
        <p><strong>Contract generated on ' . date('F j, Y \a\t g:i A') . '</strong></p>
    </div>
</body>
</html>';
    
    return $html;
}



// =============================================================================
// ENHANCED PAYMENT SUCCESS HANDLER WITH CONTRACT GENERATION
// =============================================================================

// UPDATED PAYMENT SUCCESS HANDLER - Replace the existing handler in index.php
if (isset($_GET['payment_success']) && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    $bookingId = $_GET['booking_id'] ?? '';
    
    debugLog("=== ENHANCED PAYMENT SUCCESS HANDLER ===");
    debugLog("Session ID: " . $sessionId);
    debugLog("Booking ID: " . $bookingId);
    
    if (empty($bookingId)) {
        debugLog("❌ NO BOOKING ID PROVIDED");
        echo '<!DOCTYPE html><html><head><title>Payment Error</title></head><body style="font-family:Arial;padding:40px;text-align:center"><h1>Payment Error</h1><p>Missing booking information.</p></body></html>';
        exit;
    }
    
    try {
        // STEP 1: Get detailed payment information from Stripe WITH COUPON DATA
        debugLog("Retrieving Stripe session data with discount expansion...");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . $sessionId . '?expand[]=total_details.breakdown');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripeConfig['secret_key']
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
        
        $stripeSession = json_decode($response, true);

        if (!$stripeSession || isset($stripeSession['error'])) {
            throw new Exception('Failed to retrieve payment details from Stripe');
        }

        $amountTotal = $stripeSession['amount_total'] / 100;
        $amountSubtotal = $stripeSession['amount_subtotal'] / 100;
        $currency = strtoupper($stripeSession['currency']);
        
        // Extract discount information
        $discountAmount = 0;
        $promoCode = '';
        
        if (isset($stripeSession['total_details']['breakdown']['discounts']) && 
            is_array($stripeSession['total_details']['breakdown']['discounts']) && 
            count($stripeSession['total_details']['breakdown']['discounts']) > 0) {
            
            debugLog("🎫 Discounts found in Stripe session");
            
            $firstDiscount = $stripeSession['total_details']['breakdown']['discounts'][0];
            
            if (isset($firstDiscount['amount'])) {
                $discountAmount = $firstDiscount['amount'] / 100;
                debugLog("Found discount amount: " . $discountAmount);
            }
            
            if (isset($firstDiscount['discount']['promotion_code'])) {
                $promoCode = $firstDiscount['discount']['promotion_code'];
                debugLog("Found promotion code: " . $promoCode);
            }
            
            debugLog("🎫 Successfully extracted - Amount: $discountAmount, Code: $promoCode");
        } else {
            debugLog("No discounts found in Stripe session");
        }

        // STEP 2: Update database record with ALL coupon data
        $checkStmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ?");
        $checkStmt->execute([$bookingId]);
        $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingRecord) {
            throw new Exception("Booking record not found");
        }
        
        $originalTotal = floatval($existingRecord['quote_total'] ?? $existingRecord['price'] ?? 0);
        $newTotal = $originalTotal;
        
        // Apply discount if present
        if ($discountAmount > 0) {
            $newTotal = $originalTotal - $discountAmount;
            debugLog("🎫 Applying discount: Original=$originalTotal, Discount=$discountAmount, New=$newTotal");
        }
        
        // Enhanced database update with ALL coupon fields
        $updateSQL = "UPDATE {$table} SET 
            status = 'paid',
            stripe_session_id = ?,
            quote_total = ?,
            amount_paid = ?,
            discount_amount = ?,
            promo_code = ?,
            coupon_code = ?,
            coupon_discount = ?,
            original_total = ?
            WHERE unique_id = ?";
        
        $updateStmt = $pdo->prepare($updateSQL);
        $updateResult = $updateStmt->execute([
            $sessionId,
            $newTotal,
            $amountTotal,
            $discountAmount,
            $promoCode,
            $promoCode, // Also save to coupon_code field
            $discountAmount, // Also save to coupon_discount field
            $originalTotal, // Save original total
            $bookingId
        ]);
        
        if (!$updateResult || $updateStmt->rowCount() === 0) {
            debugLog("❌ DATABASE UPDATE FAILED");
            throw new Exception("Failed to update booking record");
        }
        
        debugLog("✅ DATABASE UPDATED with coupon data:");
        debugLog("- Promo Code: " . ($promoCode ?: 'None'));
        debugLog("- Discount Amount: $" . $discountAmount);
        debugLog("- Original Total: $" . $originalTotal);
        debugLog("- New Total: $" . $newTotal);
        debugLog("- Amount Paid: $" . $amountTotal);

        // STEP 3: Get UPDATED booking data for contract and HubSpot
        $updatedStmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ?");
        $updatedStmt->execute([$bookingId]);
        $updatedBookingRecord = $updatedStmt->fetch(PDO::FETCH_ASSOC);
        
        // STEP 4: Prepare COMPLETE booking data with session data (if available)
        $sessionKey = 'pending_booking_' . $bookingId;
        $sessionBookingData = $_SESSION[$sessionKey] ?? [];
        
        // Merge updated database record with session data for complete picture
        $completeBookingData = array_merge($updatedBookingRecord, $sessionBookingData);
        
        // Ensure we have the correct discount values from the database
        $completeBookingData['discount_amount'] = $discountAmount;
        $completeBookingData['promo_code'] = $promoCode;
        $completeBookingData['coupon_code'] = $promoCode;
        $completeBookingData['coupon_discount'] = $discountAmount;
        $completeBookingData['original_total'] = $originalTotal;
        $completeBookingData['quote_total'] = $newTotal; // Use discounted total
        $completeBookingData['amount_paid'] = $amountTotal;
        
        debugLog("✅ COMPLETE BOOKING DATA PREPARED with discount information");
        
        // STEP 5: Generate contract with CORRECTED discount data
        $contractPath = null;
        try {
            $contractsDir = __DIR__ . '/contracts';
            if (!is_dir($contractsDir)) {
                mkdir($contractsDir, 0755, true);
            }
            
            $contractFileName = 'Services_Contract_LBA_' . $bookingId . '_' . date('Y-m-d_H-i-s') . '.pdf';
            $contractFullPath = $contractsDir . '/' . $contractFileName;
            $contractGenerated = generateContractPDF($completeBookingData, $contractFullPath);
            
            if ($contractGenerated) {
                $contractPath = 'contracts/' . $contractFileName;
                
                // Update contract path in database
                $contractStmt = $pdo->prepare("UPDATE {$table} SET contract_path = ? WHERE unique_id = ?");
                $contractStmt->execute([$contractPath, $bookingId]);
                
                debugLog("✅ CONTRACT GENERATED with correct discount data: " . $contractPath);
                
                // Generate public URL for HubSpot
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $domain = $_SERVER['HTTP_HOST'];
                $scriptPath = dirname($_SERVER['PHP_SELF']);
                $basePath = rtrim($scriptPath, '/');
                $contractPdfUrl = $protocol . '://' . $domain . $basePath . '/' . $contractPath;
                
                $completeBookingData['contract_pdf_url'] = $contractPdfUrl;
                $completeBookingData['contract_pdf'] = $contractPdfUrl;
            }
        } catch (Exception $contractError) {
            debugLog("⚠️ CONTRACT GENERATION FAILED: " . $contractError->getMessage());
        }
        
        // STEP 6: Send to HubSpot with CORRECT discount data
        try {
            // Prepare HubSpot data with corrected amounts
            $hubspotData = [
                'booking_id' => $bookingId,
                'client_name' => ($completeBookingData['client_name'] ?? $completeBookingData['name'] ?? ''),
                'client_email' => ($completeBookingData['client_email'] ?? $completeBookingData['email'] ?? ''),
                'client_phone' => ($completeBookingData['client_phone'] ?? $completeBookingData['phone'] ?? ''),
                'artist_type' => ($completeBookingData['artist_type'] ?? $completeBookingData['artist'] ?? ''),
                'service_type' => $completeBookingData['service_type'] ?? '',
                'region' => $completeBookingData['region'] ?? '',
                'event_date' => $completeBookingData['event_date'] ?? '',
                'ready_time' => $completeBookingData['ready_time'] ?? '',
                'trial_date' => $completeBookingData['trial_date'] ?? '',
                'appointment_time' => $completeBookingData['appointment_time'] ?? '',
                'street_address' => $completeBookingData['street_address'] ?? '',
                'city' => $completeBookingData['city'] ?? '',
                'province' => $completeBookingData['province'] ?? '',
                'postal_code' => $completeBookingData['postal_code'] ?? '',
                'quote_total' => $newTotal, // Use discounted total
                'original_total' => $originalTotal, // Include original total
                'discount_amount' => $discountAmount, // Include discount
                'promo_code' => $promoCode, // Include promo code
                'deposit_amount' => $completeBookingData['deposit_amount'] ?? $amountTotal,
                'amount_paid' => $amountTotal, // Actual amount paid
                'status' => 'paid',
                'quote_url' => 'https://quote.looksbyanum.com/?id=' . $bookingId,
                'contract_pdf_url' => $completeBookingData['contract_pdf_url'] ?? '',
                'contract_pdf' => $completeBookingData['contract_pdf'] ?? '',
                // Additional discount fields for HubSpot
                'has_discount' => $discountAmount > 0 ? 'Yes' : 'No',
                'discount_percentage' => $originalTotal > 0 ? round(($discountAmount / $originalTotal) * 100, 2) : 0,
                'savings_amount' => $discountAmount
            ];
            
            debugLog("HubSpot Data with discount info:");
            debugLog("- Original Total: $" . $originalTotal);
            debugLog("- New Total: $" . $newTotal);
            debugLog("- Discount: $" . $discountAmount);
            debugLog("- Promo Code: " . ($promoCode ?: 'None'));
            debugLog("- Amount Paid: $" . $amountTotal);
            
            $hubspotResult = sendQuoteToHubSpot($hubspotData);
            
            if ($hubspotResult) {
                debugLog("✅ HubSpot: Successfully sent booking data with discount information for " . $bookingId);
            } else {
                debugLog("❌ HubSpot: Failed to send booking data for " . $bookingId);
            }
        } catch (Exception $e) {
            debugLog("❌ HubSpot: Error - " . $e->getMessage());
        }
        
        // STEP 7: Send admin email with CORRECT discount data
        try {
            debugLog("Sending admin email notification with discount data...");
            $emailResult = sendAdminBookingNotification($completeBookingData, $contractPath);
            
            if ($emailResult) {
                debugLog("✅ ADMIN EMAIL sent successfully with discount information");
            } else {
                debugLog("⚠️ Admin email notification failed, but payment was successful");
            }
        } catch (Exception $e) {
            debugLog("⚠️ Admin email error: " . $e->getMessage());
        }
        
        // STEP 8: Generate enhanced services breakdown for display
        $servicesBreakdown = generateServicesBreakdownForIndex($completeBookingData);
        
        // STEP 9: Show enhanced success page
        $clientName = htmlspecialchars($completeBookingData['client_name'] ?? $completeBookingData['name'] ?? 'Unknown');
        
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Payment Successful - Looks By Anum</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
                .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
                .success-icon { font-size: 64px; margin-bottom: 20px; }
                h1 { color: #28a745; margin-bottom: 16px; }
                p { color: #666; line-height: 1.6; margin-bottom: 24px; }
                .booking-details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
                .detail-row { display: flex; justify-content: space-between; margin-bottom: 8px; align-items: flex-start; }
                .detail-label { font-weight: 600; margin-right: 12px; }
                .detail-value { text-align: right; flex: 1; word-break: break-word; }
                .btn { display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 6px; margin: 10px; transition: all 0.2s; }
                .btn:hover { background: #218838; text-decoration: none; }
                .btn-secondary { background: #6c757d; }
                .btn-secondary:hover { background: #545b62; }
                .status-badge { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
                .discount-highlight { background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 15px 0; text-align: center; color: #0c5460; }
                @media (max-width: 600px) {
                    .detail-row { flex-direction: column; align-items: flex-start; }
                    .detail-value { text-align: left; margin-top: 4px; }
                    .container { padding: 20px; margin: 20px; }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="success-icon">🎉</div>
                <h1>Payment Successful!</h1>
                <p><strong>Thank you, ' . $clientName . '!</strong> Your payment has been processed and your booking is confirmed.</p>
                
                <div class="booking-details">
                    <div class="detail-row">
                        <span class="detail-label">Booking ID:</span>
                        <span class="detail-value">' . htmlspecialchars($bookingId) . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Client:</span>
                        <span class="detail-value">' . htmlspecialchars($completeBookingData['client_name'] ?? $completeBookingData['name'] ?? '') . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value"><span class="status-badge">PAID & CONFIRMED</span></span>
                    </div>
                </div>';
        
        // Show discount information prominently if applicable
        if ($discountAmount > 0 && !empty($promoCode)) {
            $savingsPercentage = $originalTotal > 0 ? round(($discountAmount / $originalTotal) * 100, 1) : 0;
            echo '<div class="discount-highlight">
                    <strong>🎉 Congratulations! You saved $' . number_format($discountAmount, 2) . ' (' . $savingsPercentage . '%) with promotional code "' . htmlspecialchars($promoCode) . '"</strong><br>
                    <small>This discount has been applied to your total booking amount.</small>
                  </div>';
        }
        
        // Services breakdown (will show discount details)
        echo $servicesBreakdown;
        
        // Contract section if available
        if ($contractPath) {
            $contractType = (strpos($contractPath, '.pdf') !== false) ? 'PDF' : 'Document';
            $contractIcon = (strpos($contractPath, '.pdf') !== false) ? '📄' : '📋';
            
            echo '<div style="background: #e7f3ff; border: 2px solid #28a745; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 10px;">' . $contractIcon . '</div>
                    <h3 style="margin-top: 0; color: #28a745;">Your Booking Contract (' . $contractType . ')</h3>
                    <p>Your official contract has been generated with all discount details included.</p>
                    <a href="' . htmlspecialchars($contractPath) . '" target="_blank" class="btn">
                        📋 Download Contract
                    </a>
                  </div>';
        }
        
        echo '    <div style="margin-top: 30px;">
                    <a href="tel:+14162751719" class="btn">📞 Call Us</a>
                    <a href="mailto:info@looksbyanum.com" class="btn btn-secondary">✉️ Email Us</a>
                </div>
                
                <div style="margin-top: 20px;">
                    <a href="https://looksbyanum.com" class="btn btn-secondary">Return to Website</a>
                </div>
            </div>
        </body>
        </html>';
        
        // Clean up session data
        unset($_SESSION[$sessionKey]);
        debugLog("✅ PAYMENT SUCCESS PROCESSED WITH DISCOUNT DATA");
        
    } catch (Exception $e) {
        debugLog("❌ PAYMENT SUCCESS HANDLER ERROR: " . $e->getMessage());
        
        // Show error page but still indicate payment was successful
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Payment Successful - Minor Issue</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
                .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
                h1 { color: #28a745; margin-bottom: 16px; }
                p { color: #666; line-height: 1.6; margin-bottom: 24px; }
                .info-box { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 16px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Payment Successful!</h1>
                <p><strong>✅ Your payment was processed successfully!</strong></p>
                
                <div class="info-box">
                    <strong>Minor Technical Issue</strong><br>
                    • Your payment went through successfully<br>
                    • Your booking is secured<br>
                    • Any promotional discounts have been applied<br>
                    • We will contact you within 2 hours to confirm all details<br>
                </div>
                
                <p><strong>Booking ID:</strong> ' . htmlspecialchars($bookingId) . '</p>
            </div>
        </body>
        </html>';
    }
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
            .btn { display: inline-block; padding: 12px 24px; background: #111; color: white; text-decoration: none; border-radius: 6px; margin: 10px; }
            .btn-secondary { background: #6c757d; }
            .info-box { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 16px; margin: 20px 0; }
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

// Helper functions
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }


// === ADD THESE NEW FUNCTIONS BELOW ===

/**
 * Generate detailed services breakdown for success pages
 */

function generateServicesBreakdown($bookingData) {
    $services = [];
    $total = floatval($bookingData['quote_total'] ?? $bookingData['price'] ?? 0);
    
    if ($total <= 0) {
        return $services;
    }
    
    // Calculate travel charges based on region
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
    
    // Determine main service based on service type
    $serviceType = $bookingData['service_type'] ?? 'Bridal';
    $artistType = $bookingData['artist_type'] ?? $bookingData['artist'] ?? 'Team';
    
    // Build services array
    if (stripos($serviceType, 'bridal') !== false) {
        if ($serviceCharges > 280) {
            $services[] = ['description' => 'Bridal Makeup & Hair (with Team)', 'price' => 360.00];
            if (!empty($bookingData['trial_date'])) {
                $services[] = ['description' => 'Trial - Hair & Makeup (with Team)', 'price' => 280.00];
            }
            $remaining = $serviceCharges - 360.00 - (empty($bookingData['trial_date']) ? 0 : 280.00);
            if ($remaining > 0) {
                $services[] = ['description' => 'Additional Services', 'price' => $remaining];
            }
        } else {
            $services[] = ['description' => 'Bridal Makeup & Hair (with Team)', 'price' => $serviceCharges];
        }
    } else {
        $services[] = ['description' => ucfirst($serviceType) . ' Service', 'price' => $serviceCharges];
    }
    
    // Add specific additional services if they exist
    if (strpos($serviceType, 'jewelry') !== false || !empty($bookingData['jewelry_setting'])) {
        $services[] = ['description' => 'Jewelry & Dupatta/Veil Setting', 'price' => 50.00];
    }
    
    if (strpos($serviceType, 'extensions') !== false || !empty($bookingData['hair_extensions'])) {
        $services[] = ['description' => 'Hair Extensions Installation', 'price' => 30.00];
    }
    
    // Add travel fee
    if ($travelCharges > 0) {
        $services[] = ['description' => 'Travel Fee', 'price' => $travelCharges];
    }
    
    return $services;
}
/**
 * Generate HTML for services breakdown display
 */
function renderServicesBreakdownHTML($bookingData) {
    $html = '
    <div class="services-breakdown" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin: 20px 0;">
        <h3 style="margin: 0 0 16px 0; font-size: 18px; color: #333; border-bottom: 2px solid #28a745; padding-bottom: 8px;">
            📋 Services Breakdown
        </h3>';
    
    if (!empty($bookingData['items'])) {
        $html .= '<div style="display: grid; gap: 8px; margin-bottom: 12px;">';
        
        foreach ($bookingData['items'] as $item) {
            $html .= '
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #eee;">
                <span style="font-size: 14px; color: #555;">' . htmlspecialchars($item['description']) . '</span>
                <span style="font-weight: 600; color: #333;">$' . number_format($item['price'], 2) . '</span>
            </div>';
        }
        
        $html .= '</div>';
        
        // Subtotal, HST, and Total
        $html .= '
        <div style="border-top: 2px solid #dee2e6; padding-top: 12px; margin-top: 12px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 14px; color: #666;">Subtotal:</span>
                <span style="font-weight: 600;">$' . number_format($bookingData['subtotal'], 2) . '</span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 14px; color: #666;">HST (13%):</span>
                <span style="font-weight: 600;">$' . number_format($bookingData['hst'], 2) . '</span>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; color: #28a745; border-top: 1px solid #28a745; padding-top: 8px;">
                <span>Total Amount:</span>
                <span>$' . number_format($bookingData['total'], 2) . '</span>
            </div>
        </div>';
    } else {
        $html .= '<p style="color: #666; font-style: italic;">Service details will be confirmed via email.</p>';
    }
    
    $html .= '</div>';
    
    return $html;
}

// === END OF NEW FUNCTIONS ===





// FIXED: Complete quote calculation function that handles ALL service types
function getDetailedQuoteForArtist($artistType, $booking_data) {
    $quote = [];
    $subtotal = 0;
    
    // Travel fee calculation
    $travelFee = 0;
    if (isset($booking_data['region'])) {
        if ($booking_data['region'] === 'Toronto/GTA') {
            $travelFee = 25;
        } else if (strpos($booking_data['region'], 'Immediate Neighbors') !== false) {
            $travelFee = 40;
        } else if (strpos($booking_data['region'], 'Moderate Distance') !== false) {
            $travelFee = 80;
        } else if (strpos($booking_data['region'], 'Further Out') !== false) {
            $travelFee = 120;
        }
    }
    
    $isAnumArtist = $artistType === 'By Anum';
    $artistText = $isAnumArtist ? '(with Anum)' : '(with Team)';
    
    // Handle ALL service types
    if (isset($booking_data['service_type'])) {
        if ($booking_data['service_type'] === 'Bridal') {
            // Main bridal service
            if (isset($booking_data['bride_service'])) {
                if ($booking_data['bride_service'] === 'Both Hair & Makeup') {
                    $price = $isAnumArtist ? 450 : 360;
                    $quote[] = ['description' => "Bridal Makeup & Hair {$artistText}:", 'price' => $price];
                    $subtotal += $price;
                } else if ($booking_data['bride_service'] === 'Hair Only') {
                    $price = $isAnumArtist ? 200 : 160;
                    $quote[] = ['description' => "Bridal Hair Only {$artistText}:", 'price' => $price];
                    $subtotal += $price;
                } else if ($booking_data['bride_service'] === 'Makeup Only') {
                    $price = $isAnumArtist ? 275 : 220;
                    $quote[] = ['description' => "Bridal Makeup Only {$artistText}:", 'price' => $price];
                    $subtotal += $price;
                }
            }
            
            // Trial services
            if (isset($booking_data['needs_trial']) && $booking_data['needs_trial'] === 'Yes') {
                if (isset($booking_data['trial_service'])) {
                    if ($booking_data['trial_service'] === 'Both Hair & Makeup') {
                        $price = $isAnumArtist ? 250 : 200;
                        $quote[] = ['description' => 'Bridal Trial (Hair & Makeup):', 'price' => $price];
                        $subtotal += $price;
                    } else if ($booking_data['trial_service'] === 'Hair Only') {
                        $price = $isAnumArtist ? 150 : 120;
                        $quote[] = ['description' => 'Bridal Trial (Hair Only):', 'price' => $price];
                        $subtotal += $price;
                    } else if ($booking_data['trial_service'] === 'Makeup Only') {
                        $price = $isAnumArtist ? 150 : 120;
                        $quote[] = ['description' => 'Bridal Trial (Makeup Only):', 'price' => $price];
                        $subtotal += $price;
                    }
                }
            }
            
            // Bridal add-ons
            if (isset($booking_data['needs_jewelry']) && $booking_data['needs_jewelry'] === 'Yes') {
                $quote[] = ['description' => 'Bridal Jewelry & Dupatta/Veil Setting:', 'price' => 50];
                $subtotal += 50;
            }
            if (isset($booking_data['needs_extensions']) && $booking_data['needs_extensions'] === 'Yes') {
                $quote[] = ['description' => 'Bridal Hair Extensions Installation:', 'price' => 30];
                $subtotal += 30;
            }
            
            // Bridal party services
            if (isset($booking_data['has_party_members']) && $booking_data['has_party_members'] === 'Yes') {
                if (isset($booking_data['party_both_count']) && $booking_data['party_both_count'] > 0) {
                    $totalPrice = $booking_data['party_both_count'] * 200;
                    $quote[] = ['description' => "Bridal Party Hair and Makeup (200 CAD x {$booking_data['party_both_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_makeup_count']) && $booking_data['party_makeup_count'] > 0) {
                    $totalPrice = $booking_data['party_makeup_count'] * 100;
                    $quote[] = ['description' => "Bridal Party Makeup Only (100 CAD x {$booking_data['party_makeup_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_hair_count']) && $booking_data['party_hair_count'] > 0) {
                    $totalPrice = $booking_data['party_hair_count'] * 100;
                    $quote[] = ['description' => "Bridal Party Hair Only (100 CAD x {$booking_data['party_hair_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_dupatta_count']) && $booking_data['party_dupatta_count'] > 0) {
                    $totalPrice = $booking_data['party_dupatta_count'] * 20;
                    $quote[] = ['description' => "Bridal Party Dupatta/Veil Setting (20 CAD x {$booking_data['party_dupatta_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_extensions_count']) && $booking_data['party_extensions_count'] > 0) {
                    $totalPrice = $booking_data['party_extensions_count'] * 20;
                    $quote[] = ['description' => "Bridal Party Hair Extensions Installation (20 CAD x {$booking_data['party_extensions_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['has_airbrush']) && $booking_data['has_airbrush'] === 'Yes' && isset($booking_data['airbrush_count']) && $booking_data['airbrush_count'] > 0) {
                    $totalPrice = $booking_data['airbrush_count'] * 50;
                    $quote[] = ['description' => "Bridal Party Airbrush Makeup (50 CAD x {$booking_data['airbrush_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
            }
            
        } else if ($booking_data['service_type'] === 'Semi Bridal') {
            // Semi bridal main service
            if (isset($booking_data['bride_service'])) {
                if ($booking_data['bride_service'] === 'Both Hair & Makeup') {
                    $price = $isAnumArtist ? 350 : 280;
                    $quote[] = ['description' => "Semi Bridal Makeup & Hair {$artistText}:", 'price' => $price];
                    $subtotal += $price;
                } else if ($booking_data['bride_service'] === 'Hair Only') {
                    $price = $isAnumArtist ? 175 : 140;
                    $quote[] = ['description' => "Semi Bridal Hair Only {$artistText}:", 'price' => $price];
                    $subtotal += $price;
                } else if ($booking_data['bride_service'] === 'Makeup Only') {
                    $price = $isAnumArtist ? 225 : 180;
                    $quote[] = ['description' => "Semi Bridal Makeup Only {$artistText}:", 'price' => $price];
                    $subtotal += $price;
                }
            }
            
            // Semi bridal add-ons (same as bridal)
            if (isset($booking_data['needs_jewelry']) && $booking_data['needs_jewelry'] === 'Yes') {
                $quote[] = ['description' => 'Bridal Jewelry & Dupatta/Veil Setting:', 'price' => 50];
                $subtotal += 50;
            }
            if (isset($booking_data['needs_extensions']) && $booking_data['needs_extensions'] === 'Yes') {
                $quote[] = ['description' => 'Bridal Hair Extensions Installation:', 'price' => 30];
                $subtotal += 30;
            }
            
            // Semi bridal party (same pricing as bridal)
            if (isset($booking_data['has_party_members']) && $booking_data['has_party_members'] === 'Yes') {
                if (isset($booking_data['party_both_count']) && $booking_data['party_both_count'] > 0) {
                    $totalPrice = $booking_data['party_both_count'] * 200;
                    $quote[] = ['description' => "Bridal Party Hair and Makeup (200 CAD x {$booking_data['party_both_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_makeup_count']) && $booking_data['party_makeup_count'] > 0) {
                    $totalPrice = $booking_data['party_makeup_count'] * 100;
                    $quote[] = ['description' => "Bridal Party Makeup Only (100 CAD x {$booking_data['party_makeup_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_hair_count']) && $booking_data['party_hair_count'] > 0) {
                    $totalPrice = $booking_data['party_hair_count'] * 100;
                    $quote[] = ['description' => "Bridal Party Hair Only (100 CAD x {$booking_data['party_hair_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_dupatta_count']) && $booking_data['party_dupatta_count'] > 0) {
                    $totalPrice = $booking_data['party_dupatta_count'] * 20;
                    $quote[] = ['description' => "Bridal Party Dupatta/Veil Setting (20 CAD x {$booking_data['party_dupatta_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['party_extensions_count']) && $booking_data['party_extensions_count'] > 0) {
                    $totalPrice = $booking_data['party_extensions_count'] * 20;
                    $quote[] = ['description' => "Bridal Party Hair Extensions Installation (20 CAD x {$booking_data['party_extensions_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['has_airbrush']) && $booking_data['has_airbrush'] === 'Yes' && isset($booking_data['airbrush_count']) && $booking_data['airbrush_count'] > 0) {
                    $totalPrice = $booking_data['airbrush_count'] * 50;
                    $quote[] = ['description' => "Bridal Party Airbrush Makeup (50 CAD x {$booking_data['airbrush_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
            }
            
        } else if ($booking_data['service_type'] === 'Non-Bridal / Photoshoot') {
            // Non-bridal services
            if (isset($booking_data['non_bridal_everyone_both']) && $booking_data['non_bridal_everyone_both'] === 'Yes') {
                $pricePerPerson = $isAnumArtist ? 250 : 200;
                if (isset($booking_data['non_bridal_count'])) {
                    $totalPrice = $booking_data['non_bridal_count'] * $pricePerPerson;
                    $quote[] = ['description' => "{$booking_data['service_type']} Hair & Makeup {$artistText} ({$pricePerPerson} CAD x {$booking_data['non_bridal_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
            } else if (isset($booking_data['non_bridal_everyone_both']) && $booking_data['non_bridal_everyone_both'] === 'No') {
                if (isset($booking_data['non_bridal_both_count']) && $booking_data['non_bridal_both_count'] > 0) {
                    $pricePerPerson = $isAnumArtist ? 250 : 200;
                    $totalPrice = $booking_data['non_bridal_both_count'] * $pricePerPerson;
                    $quote[] = ['description' => "{$booking_data['service_type']} Hair & Makeup {$artistText} ({$pricePerPerson} CAD x {$booking_data['non_bridal_both_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['non_bridal_makeup_count']) && $booking_data['non_bridal_makeup_count'] > 0) {
                    $pricePerPerson = $isAnumArtist ? 140 : 110;
                    $totalPrice = $booking_data['non_bridal_makeup_count'] * $pricePerPerson;
                    $quote[] = ['description' => "{$booking_data['service_type']} Makeup Only {$artistText} ({$pricePerPerson} CAD x {$booking_data['non_bridal_makeup_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
                if (isset($booking_data['non_bridal_hair_count']) && $booking_data['non_bridal_hair_count'] > 0) {
                    $pricePerPerson = $isAnumArtist ? 130 : 110;
                    $totalPrice = $booking_data['non_bridal_hair_count'] * $pricePerPerson;
                    $quote[] = ['description' => "{$booking_data['service_type']} Hair Only {$artistText} ({$pricePerPerson} CAD x {$booking_data['non_bridal_hair_count']}):", 'price' => $totalPrice];
                    $subtotal += $totalPrice;
                }
            }
            
            // Non-bridal add-ons
            if (isset($booking_data['non_bridal_extensions_count']) && $booking_data['non_bridal_extensions_count'] > 0) {
                $totalPrice = $booking_data['non_bridal_extensions_count'] * 20;
                $quote[] = ['description' => "Hair Extensions Installation (20 CAD x {$booking_data['non_bridal_extensions_count']}):", 'price' => $totalPrice];
                $subtotal += $totalPrice;
            }
            if (isset($booking_data['non_bridal_jewelry_count']) && $booking_data['non_bridal_jewelry_count'] > 0) {
                $totalPrice = $booking_data['non_bridal_jewelry_count'] * 20;
                $quote[] = ['description' => "Jewelry/Dupatta Setting (20 CAD x {$booking_data['non_bridal_jewelry_count']}):", 'price' => $totalPrice];
                $subtotal += $totalPrice;
            }
            if (isset($booking_data['non_bridal_has_airbrush']) && $booking_data['non_bridal_has_airbrush'] === 'Yes' && isset($booking_data['non_bridal_airbrush_count']) && $booking_data['non_bridal_airbrush_count'] > 0) {
                $totalPrice = $booking_data['non_bridal_airbrush_count'] * 50;
                $quote[] = ['description' => "Airbrush Makeup (50 CAD x {$booking_data['non_bridal_airbrush_count']}):", 'price' => $totalPrice];
                $subtotal += $totalPrice;
            }
        }
    }
    
    // Add travel fee
    if ($travelFee > 0) {
        $travelDescription = ($booking_data['region'] === 'Toronto/GTA') ? 'Travel Fee (Toronto/GTA):' : "Travel Fee ({$booking_data['region']}):";
        $quote[] = ['description' => $travelDescription, 'price' => $travelFee];
        $subtotal += $travelFee;
    }
    
    // Calculate taxes and total
    $hst = $subtotal * 0.13;
    $total = $subtotal + $hst;
    
    return ['quote' => $quote, 'subtotal' => $subtotal, 'hst' => $hst, 'total' => $total];
}


if (isset($_GET['test_gmail_smtp'])) {
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        testGmailSMTPConfiguration();
    } catch (Exception $e) {
        echo '<div style="background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;">';
        echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
        echo '</div>';
    } catch (Error $e) {
        echo '<div style="background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;">';
        echo '<strong>Fatal Error:</strong> ' . htmlspecialchars($e->getMessage());
        echo '</div>';
    }
    exit;
}


/**
 * Send professional admin notification email after successful payment
 * This replaces your existing sendEmailViaHostingerPHPMailer function
 */
function sendAdminBookingNotification($bookingData, $contractPath = null) {
    // Get SMTP credentials
    $smtpHost = 'smtp.gmail.com';
    $smtpUsername = '';
    $smtpPassword = '';
    
    // Try env() function first
    if (function_exists('env')) {
        $smtpUsername = env('SMTP_USERNAME');
        $smtpPassword = env('SMTP_PASSWORD');
    }
    
    // Fallback: read .env directly
    if (empty($smtpUsername) || empty($smtpPassword)) {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/SMTP_USERNAME=(.+)/', $envContent, $matches)) {
                $smtpUsername = trim($matches[1]);
            }
            if (preg_match('/SMTP_PASSWORD=(.+)/', $envContent, $matches)) {
                $smtpPassword = trim($matches[1]);
            }
        }
    }
    
    if (empty($smtpUsername) || empty($smtpPassword)) {
        debugLog("Gmail SMTP: Missing credentials for admin notification");
        return false;
    }
    
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // SSL settings for shared hosting
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients - Send to admin
        $adminEmail = 'info@looksbyanum.com';
        $mail->setFrom($smtpUsername, 'Looks By Anum');
        $mail->addAddress($adminEmail, 'Ism Anwar');
        $mail->addReplyTo('info@looksbyanum.com', 'Looks By Anum');
        
        // Content
        $clientName = $bookingData['client_name'] ?? $bookingData['name'] ?? 'Unknown Client';
        $bookingId = $bookingData['booking_id'] ?? $bookingData['unique_id'] ?? 'Unknown';
        $serviceType = $bookingData['service_type'] ?? 'Service';
        
        $mail->isHTML(true);
        $mail->Subject = "NEW BOOKING CONFIRMED - {$clientName} ({$serviceType}) - #{$bookingId}";
        
        // Use the professional black & white template
        $mail->Body = generateAdminEmailTemplate($bookingData);
        
        // Add contract attachment if available
        if ($contractPath && file_exists($contractPath)) {
            $mail->addAttachment($contractPath, basename($contractPath));
            debugLog("Contract attachment added: " . basename($contractPath));
        }
        
        // Send email
        $mail->send();
        debugLog("✅ Admin booking notification sent successfully to: " . $adminEmail);
        debugLog("Booking ID: " . $bookingId);
        return true;
        
    } catch (Exception $e) {
        debugLog("❌ Admin notification email failed: " . $e->getMessage());
        debugLog("Booking ID: " . ($bookingData['booking_id'] ?? 'Unknown'));
        return false;
    }
}

/**
 * Get specific booking data by booking ID for email notifications
 */
function getBookingDataForEmail($pdo, $table, $bookingId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ? OR id = ? LIMIT 1");
        $stmt->execute([$bookingId, $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($booking) {
            // Map all fields and ensure we have the data we need
            $mappedData = [
                'booking_id' => $booking['unique_id'] ?? $booking['id'],
                'client_name' => $booking['name'] ?? $booking['client_name'] ?? 'Unknown Client',
                'client_email' => $booking['email'] ?? $booking['client_email'] ?? 'Unknown Email',
                'client_phone' => $booking['phone'] ?? $booking['client_phone'] ?? 'Unknown Phone',
                'artist_type' => $booking['artist'] ?? $booking['artist_type'] ?? 'Team',
                'service_type' => $booking['service_type'] ?? 'Bridal',
                'event_date' => $booking['event_date'] ?? '',
                'trial_date' => $booking['trial_date'] ?? '',
                'ready_time' => $booking['ready_time'] ?? '',
                'street_address' => $booking['street_address'] ?? '',
                'city' => $booking['city'] ?? '',
                'province' => $booking['province'] ?? '',
                'postal_code' => $booking['postal_code'] ?? '',
                'quote_total' => floatval($booking['price'] ?? $booking['quote_total'] ?? 0),
                'deposit_amount' => floatval($booking['deposit_amount'] ?? 0),
                'status' => $booking['status'] ?? 'pending',
                'region' => $booking['region'] ?? '',
                'created_at' => $booking['created_at'] ?? $booking['date_created'] ?? date('Y-m-d H:i:s'),
                // Add any session data if available
                'contract_path' => $booking['contract_path'] ?? null
            ];
            
            debugLog("Successfully retrieved booking data for email: " . $mappedData['booking_id']);
            return $mappedData;
        }
        
        debugLog("No booking found for ID: " . $bookingId);
        return null;
        
    } catch (Exception $e) {
        debugLog("Database error getting booking for email: " . $e->getMessage());
        return null;
    }
}

/**
 * Enhanced black & white email template (same as before but with better error handling)
 */
// function generateBlackWhiteBookingEmail($bookingData) {
//     // Validate we have minimum required data
//     if (empty($bookingData['booking_id']) || empty($bookingData['client_name'])) {
//         debugLog("Warning: Missing critical booking data for email template");
//     }
    
//     // Format dates and times with error handling
//     $eventDate = '';
//     $trialDate = '';
//     $appointmentTime = '';
//     $readyTime = '';
    
//     if (!empty($bookingData['event_date'])) {
//         try {
//             $eventDate = date('l, F j, Y', strtotime($bookingData['event_date']));
//         } catch (Exception $e) {
//             $eventDate = $bookingData['event_date'];
//         }
//     }
    
//     if (!empty($bookingData['trial_date'])) {
//         try {
//             $trialDate = date('l, F j, Y', strtotime($bookingData['trial_date']));
//         } catch (Exception $e) {
//             $trialDate = $bookingData['trial_date'];
//         }
//     }
    
//     if (!empty($bookingData['ready_time'])) {
//         try {
//             $readyTime = date('g:i A', strtotime($bookingData['ready_time']));
            
//             // Calculate appointment time (2 hours before ready time)
//             $readyDateTime = new DateTime($bookingData['ready_time']);
//             $appointmentDateTime = clone $readyDateTime;
//             $appointmentDateTime->sub(new DateInterval('PT2H'));
//             $appointmentTime = $appointmentDateTime->format('g:i A');
//         } catch (Exception $e) {
//             $readyTime = $bookingData['ready_time'];
//             $appointmentTime = 'TBD';
//         }
//     }
    
//     // Calculate financial breakdown
//     $totalAmount = floatval($bookingData['quote_total'] ?? 0);
//     $depositAmount = floatval($bookingData['deposit_amount'] ?? 0);
//     $remainingBalance = $totalAmount - $depositAmount;
//     $subtotal = $totalAmount > 0 ? $totalAmount / 1.13 : 0;
//     $hst = $totalAmount - $subtotal;
    
//     // Determine deposit percentage
//     $serviceType = $bookingData['service_type'] ?? 'Bridal';
//     $depositRate = (stripos($serviceType, 'non-bridal') !== false) ? '50%' : '30%';
    
//     // Format service location
//     $serviceLocation = trim(implode(', ', array_filter([
//         $bookingData['street_address'] ?? '',
//         $bookingData['city'] ?? '',
//         $bookingData['province'] ?? '',
//         $bookingData['postal_code'] ?? ''
//     ])));
    
//     $clientName = $bookingData['client_name'] ?? 'Unknown Client';
//     $clientEmail = $bookingData['client_email'] ?? 'Unknown Email';
//     $clientPhone = $bookingData['client_phone'] ?? 'Unknown Phone';
//     $bookingId = $bookingData['booking_id'] ?? 'Unknown ID';
//     $artistType = $bookingData['artist_type'] ?? 'Team';
    
//     // Use booking creation time or current time
//     $bookingDate = !empty($bookingData['created_at']) ? 
//                   date('F j, Y \a\t g:i A', strtotime($bookingData['created_at'])) : 
//                   date('F j, Y \a\t g:i A');
    
//     // [Include the complete HTML template from the previous artifact - same styling]
//     // I'll include just the key parts here for brevity, but use the full template
    
//     $html = '<!DOCTYPE html>
// <html lang="en">
// <head>
//     <meta charset="UTF-8">
//     <meta name="viewport" content="width=device-width, initial-scale=1.0">
//     <title>New Booking Confirmation - Looks By Anum</title>
//     <style>
//         body { 
//             font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
//             line-height: 1.6; 
//             color: #000; 
//             max-width: 800px; 
//             margin: 0 auto; 
//             padding: 20px; 
//             background-color: #f5f5f5; 
//         }
//         .container { 
//             background: #fff; 
//             border-radius: 0;
//             padding: 40px; 
//             box-shadow: 0 4px 12px rgba(0,0,0,0.1);
//             border: 2px solid #000;
//         }
//         /* [Include all the black & white styling from previous template] */
//         .header { 
//             text-align: center; 
//             margin-bottom: 40px; 
//             padding-bottom: 20px; 
//             border-bottom: 3px solid #000; 
//         }
//         .header h1 { 
//             color: #000; 
//             margin: 0; 
//             font-size: 32px; 
//             font-weight: 700;
//             text-transform: uppercase;
//             letter-spacing: 2px;
//         }
//         .alert { 
//             background: #000; 
//             color: #fff;
//             border-radius: 0;
//             padding: 20px; 
//             margin-bottom: 30px; 
//             text-align: center; 
//             border: none;
//         }
//         .section-title {
//             background: #000;
//             color: #fff;
//             padding: 15px 25px;
//             margin: 0;
//             font-size: 16px;
//             font-weight: 700;
//             text-transform: uppercase;
//             letter-spacing: 1px;
//         }
//         table { 
//             width: 100%; 
//             border-collapse: collapse; 
//             margin: 0;
//             background: #fff; 
//             border: 2px solid #000;
//         }
//         th, td { 
//             padding: 18px 25px; 
//             text-align: left; 
//             border-bottom: 1px solid #ddd; 
//         }
//         th { 
//             background-color: #f8f8f8; 
//             font-weight: 700; 
//             color: #000; 
//             width: 35%; 
//             font-size: 14px;
//             text-transform: uppercase;
//             letter-spacing: 0.5px;
//         }
//         /* [Continue with all other styles from previous template] */
//     </style>
// </head>
// <body>
//     <div class="container">
//         <div class="header">
//             <h1>New Booking Confirmed</h1>
//             <div class="subtitle">Looks By Anum Professional Services</div>
//             <p style="margin: 15px 0 0 0; color: #666; font-size: 14px;">Booking received on ' . $bookingDate . '</p>
//         </div>

//         <div class="alert">
//             <strong>PAYMENT SUCCESSFUL & BOOKING CONFIRMED</strong>
//         </div>

//         <!-- [Include all table sections with real data] -->
        
//         <div class="section">
//             <h3 class="section-title">Booking Information</h3>
//             <table>
//                 <tr><th>Booking ID</th><td><strong>' . htmlspecialchars($bookingId) . '</strong></td></tr>
//                 <tr><th>Service Type</th><td><strong>' . htmlspecialchars($serviceType) . '</strong></td></tr>
//                 <tr><th>Artist Requested</th><td><strong>' . htmlspecialchars($artistType) . '</strong></td></tr>
//                 <!-- [Continue with other fields] -->
//             </table>
//         </div>
        
//         <!-- [Include all other sections] -->
//     </div>
// </body>
// </html>';

//     return $html;
// }



// Continue with existing logic
$uid = isset($_GET['id']) ? trim($_GET['id']) : '';


if ($uid === '') {
  echo '<!doctype html><meta charset="utf-8"><h1>Missing booking ID</h1><p>Add ?id=YOUR_ID to the URL</p>';
  exit;
}

try {
  $pdo = new PDO(
    'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (PDOException $e) {
  echo '<!doctype html><meta charset="utf-8"><h1>Database Error</h1><p>Cannot connect to database</p>';
  exit;
}

try {
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = :u LIMIT 1");
  $stmt->execute([':u' => $uid]);
  $b = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  echo '<!doctype html><meta charset="utf-8"><h1>Query Error</h1><p>Error: ' . $e->getMessage() . '</p>';
  exit;
}

if (!$b) {
  echo '<!doctype html><meta charset="utf-8"><h1>Not Found</h1><p>No booking found for ID: ' . h($uid) . '</p>';
  exit;
}

// Add this debug code RIGHT AFTER fetching the booking record
// Find this section in your index.php:

try {
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = :u LIMIT 1");
  $stmt->execute([':u' => $uid]);
  $b = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  echo '<!doctype html><meta charset="utf-8"><h1>Query Error</h1><p>Error: ' . $e->getMessage() . '</p>';
  exit;
}

if (!$b) {
  echo '<!doctype html><meta charset="utf-8"><h1>Not Found</h1><p>No booking found for ID: ' . h($uid) . '</p>';
  exit;
}

// The rest of your existing code continues here...
$bookingStatus = isset($b['status']) ? $b['status'] : 'pending';
$isPaid = ($bookingStatus === 'paid');


function sendEmailViaGmailSMTP($bookingData, $contractPath = null) {
    $smtpUsername = env('SMTP_USERNAME');
    $smtpPassword = env('SMTP_PASSWORD');
    
    if (!$smtpUsername || !$smtpPassword) {
        debugLog("Gmail SMTP: Missing credentials");
        return false;
    }
    
    $adminEmail = 'info@looksbyanum.com';
    $clientName = $bookingData['client_name'] ?? $bookingData['name'] ?? 'Unknown Client';
    $bookingId = $bookingData['booking_id'] ?? $bookingData['unique_id'] ?? 'Unknown';
    $serviceType = $bookingData['service_type'] ?? 'Service';
    
    $subject = "New Booking Confirmed - {$clientName} ({$serviceType}) - Booking #{$bookingId}";
    $message = generateAdminEmailTemplate($bookingData, $contractPath);
    
    // Configure PHP for Gmail SMTP
    ini_set('SMTP', 'smtp.gmail.com');
    ini_set('smtp_port', '587');
    ini_set('sendmail_from', $smtpUsername);
    
    $headers = "From: Looks By Anum <{$smtpUsername}>\r\n";
    $headers .= "Reply-To: info@looksbyanum.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $result = mail($adminEmail, $subject, $message, $headers);
    
    if ($result) {
        debugLog("✅ Gmail SMTP: Email sent successfully");
        return true;
    } else {
        debugLog("❌ Gmail SMTP: Email failed to send");
        return false;
    }
}


// Handle payment success (from custom-quote.php redirect)
if (isset($_GET['payment_success']) && isset($_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
    $bookingId = $_GET['booking_id'] ?? '';
    
    echo "<h2>Processing Payment Success</h2>";
    echo "Booking ID: " . htmlspecialchars($bookingId) . "<br>";
    
    debugLog("=== INDEX.PHP PAYMENT SUCCESS HANDLER ===");
    debugLog("Session ID: " . $sessionId);
    debugLog("Booking ID: " . $bookingId);
    
    if (empty($bookingId)) {
        debugLog("❌ NO BOOKING ID PROVIDED");
        echo '<!DOCTYPE html><html><head><title>Payment Error</title></head><body style="font-family:Arial;padding:40px;text-align:center"><h1>Payment Error</h1><p>Missing booking information. Please contact support.</p></body></html>';
        exit;
    }
    try {
    // STEP 1: Get detailed payment information from Stripe WITH COUPON DATA
    debugLog("Retrieving Stripe session data with discount expansion...");
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . $sessionId . '?expand[]=total_details.breakdown');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripeConfig['secret_key']
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
        
        $stripeSession = json_decode($response, true);

        if (!$stripeSession || isset($stripeSession['error'])) {
            throw new Exception('Failed to retrieve payment details from Stripe');
        }

        $amountTotal = $stripeSession['amount_total'] / 100;
        $amountSubtotal = $stripeSession['amount_subtotal'] / 100;
        $currency = strtoupper($stripeSession['currency']);
        
        // Extract discount information using the CORRECT path from debug results
        $discountAmount = 0;
        $promoCode = '';
                
        
        if (isset($stripeSession['total_details']['breakdown']['discounts']) && 
            is_array($stripeSession['total_details']['breakdown']['discounts']) && 
            count($stripeSession['total_details']['breakdown']['discounts']) > 0) {
            
            debugLog("🎫 Discounts found in Stripe session");
            
            $firstDiscount = $stripeSession['total_details']['breakdown']['discounts'][0];
            
            // Extract discount amount
            if (isset($firstDiscount['amount'])) {
                $discountAmount = $firstDiscount['amount'] / 100;
                debugLog("Found discount amount: " . $discountAmount);
            }
            
            // Extract promotion code using the CORRECT path from debug
            if (isset($firstDiscount['discount']['promotion_code'])) {
                $promoCode = $firstDiscount['discount']['promotion_code'];
                debugLog("Found promotion code: " . $promoCode);
            }
            
            debugLog("🎫 Successfully extracted - Amount: $discountAmount, Code: $promoCode");
        } else {
            debugLog("No discounts found in Stripe session");
        }
        if (!$stripeSession || isset($stripeSession['error'])) {
            throw new Exception('Failed to retrieve payment details from Stripe');
        }
    
        // STEP 2: Calculate updated amounts
        $checkStmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ?");
        $checkStmt->execute([$bookingId]);
        $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingRecord) {
            throw new Exception("Booking record not found");
        }
        
        $originalTotalAmount = floatval($b['quote_total'] ?? $b['price'] ?? 0);
        $newTotalAmount = $originalTotalAmount;
        
        $originalTotal = floatval($existingRecord['quote_total'] ?? $existingRecord['price'] ?? 0);
        $newTotal = $originalTotal;
        if ($discountAmount > 0) {
            $newTotal = $originalTotal - $discountAmount;
            debugLog("🎫 Applying discount: Original=$originalTotal, Discount=$discountAmount, New=$newTotal");
        }
                
        // STEP 3: Update database record with coupon data
        $updateSQL = "UPDATE {$table} SET 
            status = 'paid',
            stripe_session_id = ?,
            quote_total = ?,
            amount_paid = ?,
            discount_amount = ?,
            promo_code = ?
            WHERE unique_id = ?";
        
        $updateStmt = $pdo->prepare($updateSQL);
        $updateResult = $updateStmt->execute([
            $sessionId,
            $newTotal,
            $amountTotal,
            $discountAmount,
            $promoCode,
            $bookingId
        ]);
            
    
    
    if (!$updateResult || $updateStmt->rowCount() === 0) {
        debugLog("❌ DATABASE UPDATE FAILED");
        debugLog("SQL: " . $updateSQL);
        debugLog("Values: sessionId=$sessionId, newTotal=$newTotal, amountTotal=$amountTotal, discountAmount=$discountAmount, promoCode=$promoCode, bookingId=$bookingId");
        throw new Exception("Failed to update booking record");
    }
    
    debugLog("✅ DATABASE UPDATED SUCCESSFULLY with coupon data:");
    debugLog("- Promo Code: " . ($promoCode ?: 'None'));
    debugLog("- Discount Amount: $" . $discountAmount);
    debugLog("- Original Total: $" . $originalTotal);
    debugLog("- New Total: $" . $newTotal);
    debugLog("- Amount Paid: $" . $amountTotal);
    debugLog("- Rows affected: " . $updateStmt->rowCount());
    
    if ($discountAmount > 0 && !empty($bookingId)) {
        echo "<h3>Updating Database...</h3>";
        
        try {
            // Connect to database
            $pdo = new PDO(
                'mysql:host=127.0.0.1;dbname=u194250183_WAsbP;charset=utf8mb4',
                'u194250183_80NGy',
                '6OuLxGYTII',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            echo "Database connected<br>";
            
            // Get current booking
            $stmt = $pdo->prepare("SELECT * FROM wp_bridal_bookings WHERE unique_id = ?");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($booking) {
                echo "Booking found<br>";
                
                $originalTotal = floatval($booking['quote_total']);
                $discountPercentage = $discountAmount / $amountSubtotal;
                $newTotal = $originalTotal * (1 - $discountPercentage);
                
                echo "Original DB Total: $" . number_format($originalTotal, 2) . "<br>";
                echo "New DB Total: $" . number_format($newTotal, 2) . "<br>";
                echo "Savings: $" . number_format($originalTotal - $newTotal, 2) . "<br>";
                
                // Update the database
                $updateSQL = "UPDATE wp_bridal_bookings SET 
                    quote_total = ?, 
                    deposit_amount = ?, 
                    amount_paid = ?,
                    discount_amount = ?,
                    promo_code = ?,
                    status = 'paid'
                    WHERE unique_id = ?";
                
                $updateStmt = $pdo->prepare($updateSQL);
                $success = $updateStmt->execute([
                    $newTotal,
                    $amountTotal,
                    $amountTotal,
                    $discountAmount,
                    $promoCode,
                    $bookingId
                ]);
                
                if ($success && $updateStmt->rowCount() > 0) {
                    echo "<p style='color:green; font-weight:bold;'>✅ DATABASE UPDATED SUCCESSFULLY!</p>";
                    echo "Rows affected: " . $updateStmt->rowCount() . "<br>";
                    
                    // Show success message
                    echo "<div style='background:#d4edda; border:1px solid #c3e6cb; padding:20px; margin:20px 0; border-radius:5px;'>";
                    echo "<h3 style='color:#155724; margin:0;'>🎉 Payment Successful!</h3>";
                    echo "<p>Your booking has been confirmed with the promotional discount applied.</p>";
                    echo "<p><strong>You saved: $" . number_format($originalTotal - $newTotal, 2) . "</strong></p>";
                    echo "</div>";
                    
                } else {
                    echo "<p style='color:red;'>❌ Database update failed</p>";
                    echo "Error info: " . print_r($updateStmt->errorInfo(), true) . "<br>";
                }
                
            } else {
                echo "<p style='color:red;'>Booking not found in database</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color:red;'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
    } else {
        echo "<h3>No discount to apply</h3>";
        echo "Either no promotional code was used or booking ID is missing.<br>";
    }
    
    echo "<br><br><a href='https://looksbyanum.com' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Return to Website</a>";
    exit;
    
    debugLog("Payment Details - Total: $amountTotal, Subtotal: $amountSubtotal, Discount: $discountAmount, Promo: $promoCode");

// STEP 3: Update database record
$checkStmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ?");
$checkStmt->execute([$bookingId]);
$existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$existingRecord) {
    throw new Exception("Booking record not found");
}

$originalTotal = floatval($existingRecord['quote_total'] ?? $existingRecord['price'] ?? 0);
$newTotal = $originalTotal;

// Apply discount if present
if ($discountAmount > 0) {
    $newTotal = $originalTotal - $discountAmount;
    debugLog("🎫 Applying discount: Original=$originalTotal, Discount=$discountAmount, New=$newTotal");
}

// Enhanced database update with ALL coupon fields
$updateSQL = "UPDATE {$table} SET 
    status = 'paid',
    stripe_session_id = ?,
    quote_total = ?,
    amount_paid = ?,
    discount_amount = ?,
    promo_code = ?,
    coupon_code = ?,
    coupon_discount = ?,
    original_total = ?
    WHERE unique_id = ?";

$updateStmt = $pdo->prepare($updateSQL);
$updateResult = $updateStmt->execute([
    $sessionId,
    $newTotal,
    $amountTotal,
    $discountAmount,
    $promoCode,
    $promoCode, // Also save to coupon_code field
    $discountAmount, // Also save to coupon_discount field
    $originalTotal, // Save original total
    $bookingId
]);

if (!$updateResult || $updateStmt->rowCount() === 0) {
    debugLog("❌ DATABASE UPDATE FAILED");
    throw new Exception("Failed to update booking record");
}

debugLog("✅ DATABASE UPDATED with coupon data:");
debugLog("- Promo Code: " . ($promoCode ?: 'None'));
debugLog("- Discount Amount: $" . $discountAmount);
debugLog("- Original Total: $" . $originalTotal);
debugLog("- New Total: $" . $newTotal);
debugLog("- Amount Paid: $" . $amountTotal);

if ($updateResult && $updateStmt->rowCount() > 0) {
    debugLog("✅ RECORD UPDATED SUCCESSFULLY - Status set to 'paid'");
    
    // Refresh the record data
    $stmt->execute([':u' => $uid]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // GET ORIGINAL AMOUNTS BEFORE UPDATING
    $originalTotalAmount = floatval($b['quote_total'] ?? 0);
        
        $originalTotalAmount = floatval($b['quote_total'] ?? 0);

        // 👇 ADD THIS ENTIRE BLOCK HERE 👇
        if ($discountAmount > 0) {
            debugLog("Discount detected: $discountAmount, updating database...");
                writeDebugFile("ENTERING DISCOUNT UPDATE BLOCK");
                writeDebugFile("discountAmount: $discountAmount");
                writeDebugFile("Original total: $originalTotalAmount");
            
            // Calculate the proportional discount for the total booking
            $discountPercentage = $discountAmount / $amountSubtotal;
            $newTotalAmount = $originalTotalAmount * (1 - $discountPercentage);
            
            try {
                // Update the database with the discounted amounts
                $updateAmountsSQL = "UPDATE {$table} SET 
                    quote_total = ?, 
                    deposit_amount = ?, 
                    amount_paid = ?,
                    discount_amount = ?,
                    promo_code = ?
                    WHERE unique_id = ?";
                
                $updateAmountsStmt = $pdo->prepare($updateAmountsSQL);
                $updateSuccess = $updateAmountsStmt->execute([
                    $newTotalAmount,
                    $amountTotal,
                    $amountTotal,
                    $discountAmount,
                    $promoCode,
                    $bookingId
                ]);
                
                if ($updateSuccess) {
                    debugLog("✅ Database updated with discount amounts");
                    
                    // REFRESH THE RECORD DATA TO GET UPDATED VALUES
                    $stmt->execute([':u' => $uid]);
                    $b = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    debugLog("❌ Failed to update discount amounts");
                }
                
            } catch (Exception $e) {
                debugLog("❌ Database update error: " . $e->getMessage());
            }
        }
        // 👆 END OF ADDED CODE 👆

          
        $clientName = htmlspecialchars($b['client_name'] ?? $b['name'] ?? 'Unknown');
        
        // Generate payment breakdown HTML
        $paymentBreakdownHtml = '';
        if ($discountAmount > 0) {
            // Show breakdown with discount
            $paymentBreakdownHtml = '
            <div class="payment-breakdown">
                <h3>💰 Payment Summary</h3>
                <div class="detail-row">
                    <span class="detail-label">Subtotal:</span>
                    <span class="detail-value">$' . number_format($amountSubtotal, 2) . ' ' . $currency . '</span>
                </div>
                <div class="detail-row discount">
                    <span class="detail-label">
                        Discount (' . ($promoCode ? '<span class="promo-code">' . htmlspecialchars($promoCode) . '</span>' : 'Applied') . '):
                    </span>
                    <span class="detail-value">-$' . number_format($discountAmount, 2) . ' ' . $currency . '</span>
                </div>
                <div class="detail-row total">
                    <span class="detail-label">Total Charged:</span>
                    <span class="detail-value">$' . number_format($amountTotal, 2) . ' ' . $currency . '</span>
                </div>
                <div style="margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 4px; text-align: center; color: #0c5460;">
                    <strong>🎉 You saved $' . number_format($discountAmount, 2) . ' (' . round(($discountAmount / $amountSubtotal) * 100) . '%) with your promotional code!</strong>
                </div>
            </div>';
        } else {
            // Show simple amount (no discount applied)
            $paymentBreakdownHtml = '
            <div class="payment-breakdown">
                <h3>💰 Payment Summary</h3>
                <div class="detail-row total">
                    <span class="detail-label">Amount Charged:</span>
                    <span class="detail-value">$' . number_format($amountTotal, 2) . ' ' . $currency . '</span>
                </div>
            </div>';
        }
        
        // Replace your success page HTML with this corrected version:

        // Calculate correct amounts based on discount
        $originalDepositAmount = floatval($b['deposit_amount'] ?? ($b['quote_total'] * 0.3 ?? 0));
        $originalTotalAmount = floatval($b['quote_total'] ?? 0);
        
        // If discount was applied, calculate the adjusted amounts
        // Add coupon information for display
        $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
        $originalTotal = $hasDiscount ? ($adjustedTotalAmount + $discountAmount) : $adjustedTotalAmount;
        
        // STEP 4: Show enhanced success page
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Payment Successful - Looks By Anum</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
                .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
                .success-icon { font-size: 64px; margin-bottom: 20px; }
                h1 { color: #28a745; margin-bottom: 16px; }
                h3 { color: #333; text-align: center; margin-top: 0; }
                p { color: #666; line-height: 1.6; margin-bottom: 24px; }
                .booking-details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
                .services-breakdown { background: #f8f9fa; border-radius: 8px; margin: 20px 0; text-align: left; }
                .payment-breakdown { background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; border: 1px solid #28a745; }
                .payment-breakdown h3 { margin-top: 0; color: #28a745; text-align: center; }
                .detail-row { display: flex; justify-content: space-between; margin-bottom: 8px; padding: 4px 0; border-bottom: 1px solid #eee; }
                .detail-row:last-child { border-bottom: none; }
                .detail-row.total { border-top: 2px solid #28a745; margin-top: 10px; padding-top: 10px; font-weight: bold; font-size: 16px; border-bottom: none; }
                .detail-row.discount { color: #dc3545; font-weight: 600; }
                .detail-row.original { text-decoration: line-through; color: #999; font-size: 14px; }
                .detail-label { font-weight: 600; }
                .detail-value { text-align: right; }
                .promo-code { background: #fff3cd; padding: 8px 12px; border-radius: 4px; font-family: monospace; font-weight: bold; }
                .btn { display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 6px; margin: 10px; transition: all 0.2s; }
                .btn:hover { background: #218838; text-decoration: none; }
                .btn-secondary { background: #6c757d; }
                .btn-secondary:hover { background: #545b62; }
                .discount-note { background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 15px 0; text-align: center; color: #0c5460; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="success-icon">🎉</div>
                <h1>Payment Successful!</h1>
                <p><strong>Thank you, ' . $clientName . '!</strong> Your payment has been processed and your booking is confirmed.</p>
                
                <div class="booking-details">
                    <h3>📋 Booking Details</h3>
                    <div class="detail-row">
                        <span class="detail-label">Booking ID:</span>
                        <span class="detail-value">' . htmlspecialchars($bookingId) . '</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value" style="color: #28a745; font-weight: bold;">CONFIRMED & PAID</span>
                    </div>
                </div>
                
                <div class="services-breakdown">
                    <h3>📊 Booking Summary</h3>';
        
        // Show original vs discounted amounts if discount was applied
        if ($discountAmount > 0) {
            echo '
            <div class="detail-row original">
                <span class="detail-label">Original Total Amount:</span>
                <span class="detail-value">$' . number_format($originalTotalAmount, 2) . ' CAD</span>
            </div>
            <div class="detail-row discount">
                <span class="detail-label">Discount Applied (' . htmlspecialchars($promoCode) . '):</span>
                <span class="detail-value">-$' . number_format($originalTotalAmount - $adjustedTotalAmount, 2) . ' CAD</span>
            </div>
            <div class="detail-row total">
                <span class="detail-label">New Total Amount:</span>
                <span class="detail-value">$' . number_format($adjustedTotalAmount, 2) . ' CAD</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount Paid Today (30% Deposit):</span>
                <span class="detail-value">$' . number_format($amountTotal, 2) . ' CAD</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Remaining Balance (due on event day):</span>
                <span class="detail-value">$' . number_format($adjustedRemainingBalance, 2) . ' CAD</span>
            </div>';
            
            $totalSavings = $originalTotalAmount - $adjustedTotalAmount;
            echo '<div class="discount-note">
                    <strong>🎉 Your promotional code saved you $' . number_format($totalSavings, 2) . ' on your total booking!</strong><br>
                    <small>Discount applied to both deposit and remaining balance.</small>
                  </div>';
            
        } else {
            // No discount - show normal amounts
            echo '
            <div class="detail-row total">
                <span class="detail-label">Total Amount:</span>
                <span class="detail-value">$' . number_format($adjustedTotalAmount, 2) . ' CAD</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount Paid Today (30% Deposit):</span>
                <span class="detail-value">$' . number_format($amountTotal, 2) . ' CAD</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Remaining Balance (due on event day):</span>
                <span class="detail-value">$' . number_format($adjustedRemainingBalance, 2) . ' CAD</span>
            </div>';
        }
        
        echo '
                </div>
                
                ' . $paymentBreakdownHtml . '
                
                <div style="margin-top: 30px;">
                    <a href="tel:+14162751719" class="btn">📞 Call Us</a>
                    <a href="mailto:info@looksbyanum.com" class="btn btn-secondary">✉️ Email Us</a>
                </div>
                
                <div style="margin-top: 20px;">
                    <a href="https://looksbyanum.com" class="btn btn-secondary">Return to Website</a>
                </div>
            </div>
        </body>
        </html>';
        
        exit;
        
    } else {
        debugLog("❌ UPDATE FAILED - No rows affected");
        
        // Show error page but still indicate payment was successful
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Payment Successful - Minor Issue</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
                .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
                h1 { color: #28a745; margin-bottom: 16px; }
                p { color: #666; line-height: 1.6; margin-bottom: 24px; }
                .info-box { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 16px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Payment Successful!</h1>
                <p><strong>✅ Your payment was processed successfully!</strong></p>
                
                <div class="info-box">
                    <strong>Minor Technical Issue</strong><br>
                    • Your payment went through successfully<br>
                    • Your booking is secured<br>
                    • We will contact you within 2 hours to confirm all details<br>
                    • Our technical team has been notified of the display issue
                </div>
                
                <p><strong>Booking ID:</strong> ' . htmlspecialchars($bookingId) . '</p>
                <p>Please save this booking ID for your records.</p>
            </div>
        </body>
        </html>';
        
        exit;
    }
    
} catch (Exception $e) {
    debugLog("❌ PAYMENT SUCCESS HANDLER ERROR: " . $e->getMessage());
    
    // Show error page but still indicate payment was successful
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Successful - Minor Issue</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
            .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
            h1 { color: #28a745; margin-bottom: 16px; }
            p { color: #666; line-height: 1.6; margin-bottom: 24px; }
            .info-box { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 16px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Payment Successful!</h1>
            <p><strong>✅ Your payment was processed successfully!</strong></p>
            
            <div class="info-box">
                <strong>Minor Technical Issue</strong><br>
                • Your payment went through successfully<br>
                • Your booking is secured<br>
                • We will contact you within 2 hours to confirm all details<br>
                • Our technical team has been notified of the display issue
            </div>
            
            <p><strong>Booking ID:</strong> ' . htmlspecialchars($bookingId) . '</p>
            <p>Please save this booking ID for your records.</p>
        </div>
    </body>
    </html>';
    
    exit;
    } 
}

$isDestinationWedding = empty($b['service_type']) || $b['service_type'] === 'Destination Wedding';

// FIXED: Complete paid status display with proper closing
if ($isPaid) {
    $contractPath = isset($b['contract_path']) ? $b['contract_path'] : null;
    $contractType = 'PDF';
    if ($contractPath && strpos($contractPath, '.html') !== false) {
        $contractType = 'Document';
    }

    // CORRECTED FIELD MAPPING - using actual WordPress database column names
    $clientName = $b['name'] ?? '';  // WordPress plugin uses 'name', not 'client_name'
    $clientEmail = $b['email'] ?? ''; // WordPress plugin uses 'email', not 'client_email'  
    $clientPhone = $b['phone'] ?? ''; // WordPress plugin uses 'phone', not 'client_phone'
    $artistType = $b['artist'] ?? 'anum'; // WordPress plugin uses 'artist', not 'artist_type'
    $serviceType = $b['service_type'] ?? 'Bridal'; // This one is correct
    $eventDate = $b['event_date'] ?? '';
    $readyTime = $b['ready_time'] ?? '';
    $totalAmount = floatval($b['price'] ?? 0); // WordPress plugin uses 'price', not 'quote_total'
    $depositAmount = floatval($b['deposit_amount'] ?? 0);
        // Define coupon variables to prevent undefined variable error
        // CORRECTED calculation section:
        $discountAmount = floatval($b['discount_amount'] ?? 0);
        $promoCode = $b['promo_code'] ?? '';
        $actualAmountPaid = floatval($b['amount_paid'] ?? $depositAmount);
        $currentTotal = floatval($b['quote_total'] ?? $b['price'] ?? 0); // Current total after discount
        $originalTotal = $currentTotal + $discountAmount; // Original before discount
        $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
    
    // Format service location from WordPress plugin fields
    $serviceLocation = trim(implode(', ', array_filter([
        $b['street_address'] ?? '',
        $b['city'] ?? '',
        $b['province'] ?? '',
        $b['postal_code'] ?? ''
    ])));

    // If location fields don't exist in WordPress, create a placeholder
    if (empty($serviceLocation)) {
        $serviceLocation = 'Location details will be confirmed via email';
    }

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
    // Add coupon section if discount was applied


    // Rest of your HTML output code remains the same...
    echo '<!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Confirmed - Looks By Anum</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 0; padding: 40px; background: #f8f9fa; }
            .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
            .success-icon { font-size: 64px; margin-bottom: 20px; }
            h1 { color: #28a745; margin-bottom: 16px; }
            p { color: #666; line-height: 1.6; margin-bottom: 24px; }
            .booking-details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left; }
            .booking-details h3 { margin-top: 0; color: #333; text-align: center; }
            .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
            .detail-row:last-child { border-bottom: none; }
            .detail-label { font-weight: 600; color: #333; }
            .detail-value { color: #555; }
            .status-badge { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
            .services-breakdown { background: #f8f9fa;border-radius: 8px; margin: 20px 0; text-align: left; }
            .services-breakdown h3 { margin-top: 0; color: #333; text-align: center; }
            .service-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
            .service-item:last-child { border-bottom: none; }
            .service-name { color: #333; }
            .service-price { color: #555; font-weight: 600; }
            .total-row { padding-top: 10px; font-weight: bold; font-size: 16px; }
            .btn { display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 6px; margin: 10px; transition: all 0.2s; }
            .btn:hover { background: #218838; text-decoration: none; }
            .btn-secondary { background: #6c757d; }
            .btn-secondary:hover { background: #545b62; }
            .contract-section { background: #e7f3ff; border: 2px solid #28a745; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .contract-icon { font-size: 48px; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="success-icon">🎉</div>
            <h1>Booking Confirmed!</h1>
            <p><strong>Success!</strong> Thank you for booking with us. We will contact you within 24 hours to confirm all details.</p>
            
            <!-- Booking Details Section -->
            <div class="booking-details">
                <h3>📋 Booking Details</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Booking ID:</span>
                    <span class="detail-value">' . h($b['unique_id']) . '</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value">' . h($clientName ?: 'Not specified') . '</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">' . h($clientEmail ?: 'Not specified') . '</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">' . h($clientPhone ?: 'Not specified') . '</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Artist:</span>
                    <span class="detail-value">' . h($artistType) . '</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Service Type:</span>
                    <span class="detail-value">' . h($serviceType) . '</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="status-badge">PAID & CONFIRMED</span>
                </div>';
                
    if ($eventDateFormatted) {
        echo '<div class="detail-row">
                <span class="detail-label">Event Date:</span>
                <span class="detail-value">' . h($eventDateFormatted) . '</span>
              </div>';
    }
    
    if ($readyTimeFormatted) {
        echo '<div class="detail-row">
                <span class="detail-label">Ready Time:</span>
                <span class="detail-value">' . h($readyTimeFormatted) . '</span>
              </div>';
    }
    
    if ($serviceLocation) {
        echo '<div class="detail-row">
                <span class="detail-label">Service Location:</span>
                <span class="detail-value">' . h($serviceLocation) . '</span>
              </div>';
    }
    

            
    // Services breakdown using the corrected function
    echo generateServicesBreakdownForIndex($b);
    
    // Contract section
    if ($contractPath) {
        echo '<div class="contract-section">
                <div class="contract-icon">📄</div>
                <h3 style="margin-top: 0; color: #28a745;">Your Booking Contract (' . $contractType . ')</h3>
                <p>Your official contract has been generated. Please download and keep this for your records.</p>
                <a href="' . h($contractPath) . '" target="_blank" class="btn">
                    Download Contract
                </a>
                <p style="font-size: 12px; color: #666; margin-top: 10px;">
                    Contract will open in a new window. You can print or save it for your records.
                </p>
              </div>';
    }
    
    echo '    <div style="background: #e7f3ff; border: 1px solid #b8daff; border-radius: 6px; padding: 16px; margin: 20px 0;">
                <strong>✅ Your booking is confirmed!</strong><br>
                • Payment processed successfully<br>
                • Booking saved in our system<br>
                • Confirmation email has been sent<br>
                • We will contact you within 24 hours to confirm all details
              </div>
              
              <a href="mailto:info@looksbyanum.com" class="btn btn-secondary">Contact Us</a>
        </div>
    </body>
    </html>';
    exit;
}


/**
 * Generate comprehensive admin email template with ALL booking details
 */
// UPDATED ADMIN EMAIL TEMPLATE with proper discount handling
function generateAdminEmailTemplate($bookingData, $contractPath = null) {
    // Get discount information (matching generateServicesBreakdownForIndex logic)
    $discountAmount = floatval($bookingData['discount_amount'] ?? 0);
    $promoCode = $bookingData['promo_code'] ?? '';
    $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
    
    // Calculate totals with discount handling
    $currentTotal = floatval($bookingData['quote_total'] ?? $bookingData['price'] ?? 0);
    $originalTotal = $hasDiscount ? floatval($bookingData['original_total'] ?? ($currentTotal + $discountAmount)) : $currentTotal;
    
    $actualAmountPaid = floatval($bookingData['amount_paid'] ?? 0);
    $depositAmount = floatval($bookingData['deposit_amount'] ?? $actualAmountPaid);
    $remainingBalance = $currentTotal - $actualAmountPaid;
    
    // Calculate subtotal and HST for current (discounted) amount
    $subtotal = $currentTotal > 0 ? $currentTotal / 1.13 : 0;
    $hst = $currentTotal - $subtotal;
    
    // Format dates and times
    $eventDate = '';
    if (!empty($bookingData['event_date'])) {
        try {
            $eventDate = date('l, F j, Y', strtotime($bookingData['event_date']));
        } catch (Exception $e) {
            $eventDate = $bookingData['event_date'];
        }
    }
    
    $readyTime = '';
    if (!empty($bookingData['ready_time'])) {
        try {
            $readyTime = date('g:i A', strtotime($bookingData['ready_time']));
        } catch (Exception $e) {
            $readyTime = $bookingData['ready_time'];
        }
    }
    
    $appointmentTime = '';
    if (!empty($bookingData['appointment_time'])) {
        $appointmentTime = $bookingData['appointment_time'];
    } elseif ($readyTime) {
        try {
            $readyDateTime = new DateTime($bookingData['ready_time']);
            $appointmentDateTime = clone $readyDateTime;
            $appointmentDateTime->sub(new DateInterval('PT2H'));
            $appointmentTime = $appointmentDateTime->format('g:i A');
        } catch (Exception $e) {
            $appointmentTime = '';
        }
    }
    
    // Format service location
    $serviceLocation = trim(implode(', ', array_filter([
        $bookingData['street_address'] ?? '',
        $bookingData['city'] ?? '',
        $bookingData['province'] ?? '',
        $bookingData['postal_code'] ?? ''
    ])));
    
    // Client information
    $clientName = $bookingData['client_name'] ?? $bookingData['name'] ?? '';
    $clientEmail = $bookingData['client_email'] ?? $bookingData['email'] ?? '';
    $clientPhone = $bookingData['client_phone'] ?? $bookingData['phone'] ?? '';
    $artistType = $bookingData['artist_type'] ?? $bookingData['artist'] ?? '';
    $serviceType = $bookingData['service_type'] ?? 'Bridal';
    
    $bookingId = $bookingData['booking_id'] ?? $bookingData['unique_id'] ?? '';
    $bookingDate = date('F j, Y \a\t g:i A');
    $region = $bookingData['region'] ?? '';
    $status = $bookingData['status'] ?? 'pending';
    
    // Determine deposit percentage
    $depositPercentage = '30%';
    if (stripos($serviceType, 'non-bridal') !== false) {
        $depositPercentage = '50%';
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking Confirmation - Looks By Anum</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            line-height: 1.6; 
            color: #000; 
            max-width: 800px; 
            margin: 0 auto; 
            padding: 20px; 
            background-color: #f5f5f5; 
        }
        .container { 
            background: #fff; 
            border-radius: 0;
            padding: 40px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: 2px solid #000;
        }
        .header { 
            text-align: center; 
            margin-bottom: 40px; 
            padding-bottom: 20px; 
            border-bottom: 3px solid #000; 
        }
        .header h1 { 
            color: #000; 
            margin: 0; 
            font-size: 32px; 
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .alert { 
            background: #000; 
            color: #fff;
            border-radius: 0;
            padding: 20px; 
            margin-bottom: 30px; 
            text-align: center; 
            border: none;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            background: #000;
            color: #fff;
            padding: 15px 25px;
            margin: 0 0 0 0;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 0;
            background: #fff; 
            border: 2px solid #000;
        }
        th, td { 
            padding: 18px 25px; 
            text-align: left; 
            border-bottom: 1px solid #ddd; 
        }
        th { 
            background-color: #f8f8f8; 
            font-weight: 700; 
            color: #000; 
            width: 35%; 
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td { 
            color: #333; 
            font-size: 14px;
        }
        .discount-highlight { 
            background: #d1ecf1; 
            color: #0c5460;
            font-weight: 600;
        }
        .original-amount {
            text-decoration: line-through;
            color: #999;
        }
        .savings-amount {
            color: #dc3545;
            font-weight: 700;
        }
        .status-badge { 
            background: #000; 
            color: white; 
            padding: 6px 16px; 
            border-radius: 0; 
            font-size: 12px; 
            font-weight: 700; 
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .important-note {
            background: #fff3cd;
            border-left: 4px solid #000;
            padding: 15px 20px;
            margin: 20px 0;
            font-weight: 600;
        }
        .footer { 
            margin-top: 40px; 
            padding-top: 25px; 
            border-top: 2px solid #000; 
            text-align: center; 
            color: #666; 
            font-size: 14px; 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Booking Confirmed</h1>
            <div style="font-size: 14px; color: #666; margin-top: 8px;">Looks By Anum Professional Services</div>
            <p style="margin: 15px 0 0 0; color: #666; font-size: 14px;">Booking received on ' . $bookingDate . '</p>
        </div>

        <div class="alert">
            <strong>PAYMENT SUCCESSFUL & BOOKING CONFIRMED</strong>';
            
    if ($hasDiscount) {
        $savings = $originalTotal - $currentTotal;
        $savingsPercentage = $originalTotal > 0 ? round(($savings / $originalTotal) * 100, 1) : 0;
        $html .= '<br><span style="font-size: 16px; color: #ffd700;">🎉 CLIENT SAVED $' . number_format($savings, 2) . ' (' . $savingsPercentage . '%) WITH PROMO CODE "' . htmlspecialchars($promoCode) . '"</span>';
    }
    
    $html .= '</div>

        <!-- BOOKING INFORMATION SECTION -->
        <div class="section">
            <h3 class="section-title">Booking Information</h3>
            <table>
                <tr><th>Booking ID</th><td><strong>' . htmlspecialchars($bookingId) . '</strong></td></tr>
                <tr><th>Booking Status</th><td><span class="status-badge">' . strtoupper($status) . '</span></td></tr>
                <tr><th>Service Type</th><td><strong>' . htmlspecialchars($serviceType) . '</strong></td></tr>
                <tr><th>Artist Requested</th><td><strong>' . htmlspecialchars($artistType) . '</strong></td></tr>
                <tr><th>Region</th><td>' . htmlspecialchars($region ?: 'Not specified') . '</td></tr>';
                
    if ($hasDiscount) {
        $html .= '<tr class="discount-highlight"><th>Promotional Code</th><td><strong>' . htmlspecialchars($promoCode) . '</strong></td></tr>';
    }
    
    $html .= '</table>
        </div>

        <!-- CLIENT DETAILS SECTION -->
        <div class="section">
            <h3 class="section-title">Client Details</h3>
            <table>
                <tr><th>Client Name</th><td><strong>' . htmlspecialchars($clientName) . '</strong></td></tr>
                <tr><th>Email Address</th><td><a href="mailto:' . htmlspecialchars($clientEmail) . '" style="color: #000; text-decoration: underline;">' . htmlspecialchars($clientEmail) . '</a></td></tr>
                <tr><th>Phone Number</th><td><a href="tel:' . htmlspecialchars($clientPhone) . '" style="color: #000; text-decoration: underline;">' . htmlspecialchars($clientPhone) . '</a></td></tr>
            </table>
        </div>

        <!-- EVENT & SCHEDULE SECTION -->
        <div class="section">
            <h3 class="section-title">Event & Schedule Details</h3>
            <table>';

    if ($eventDate) {
        $html .= '<tr><th>Event Date</th><td><strong>' . htmlspecialchars($eventDate) . '</strong></td></tr>';
    }

    if ($appointmentTime) {
        $html .= '<tr><th>Appointment Start</th><td><strong>' . htmlspecialchars($appointmentTime) . '</strong></td></tr>';
    }

    if ($readyTime) {
        $html .= '<tr><th>Ready Time</th><td><strong>' . htmlspecialchars($readyTime) . '</strong></td></tr>';
    }

    if ($serviceLocation) {
        $html .= '<tr><th>Service Location</th><td><strong>' . htmlspecialchars($serviceLocation) . '</strong></td></tr>';
    }

    $html .= '</table>
        </div>

        <!-- FINANCIAL SUMMARY SECTION WITH DISCOUNT DETAILS -->
        <div class="section">
            <h3 class="section-title">Financial Summary</h3>
            <table>';
            
    if ($hasDiscount) {
        $html .= '
                <tr><th>Original Service Amount</th><td class="original-amount">$' . number_format($originalTotal, 2) . ' CAD</td></tr>
                <tr class="discount-highlight"><th>Promotional Discount (' . htmlspecialchars($promoCode) . ')</th><td class="savings-amount">-$' . number_format($discountAmount, 2) . ' CAD</td></tr>
                <tr><th><strong>Final Service Amount</strong></th><td><strong>$' . number_format($currentTotal, 2) . ' CAD</strong></td></tr>';
    } else {
        $html .= '<tr><th>Total Service Amount</th><td><strong>$' . number_format($currentTotal, 2) . ' CAD</strong></td></tr>';
    }
    
    $html .= '
                <tr><th>Subtotal (before HST)</th><td>$' . number_format($subtotal, 2) . ' CAD</td></tr>
                <tr><th>HST (13%)</th><td>$' . number_format($hst, 2) . ' CAD</td></tr>
                <tr style="background: #e7f3ff;"><th>Deposit Paid (' . $depositPercentage . ')</th><td><strong>$' . number_format($actualAmountPaid, 2) . ' CAD</strong></td></tr>';

    if ($remainingBalance > 0) {
        $html .= '<tr><th>Remaining Balance</th><td><strong>$' . number_format($remainingBalance, 2) . ' CAD</strong> <small>(due on event day)</small></td></tr>';
    } else {
        $html .= '<tr><th>Payment Status</th><td><strong>PAID IN FULL</strong></td></tr>';
    }

    $html .= '</table>';
    
    // Add savings summary if discount was applied
    if ($hasDiscount) {
        $totalSavings = $originalTotal - $currentTotal;
        $savingsPercentage = $originalTotal > 0 ? round(($totalSavings / $originalTotal) * 100, 1) : 0;
        $html .= '<div class="important-note">
                    <h3 style="margin: 0 0 10px 0; color: #000; font-size: 16px;">💰 CLIENT SAVINGS SUMMARY</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li><strong>Promotional Code Used:</strong> ' . htmlspecialchars($promoCode) . '</li>
                        <li><strong>Total Amount Saved:</strong> $' . number_format($totalSavings, 2) . ' CAD (' . $savingsPercentage . '%)</li>
                        <li><strong>Original Total:</strong> $' . number_format($originalTotal, 2) . ' CAD</li>
                        <li><strong>Final Total:</strong> $' . number_format($currentTotal, 2) . ' CAD</li>
                    </ul>
                  </div>';
    }
    
    $html .= '</div>';

    // Contract information
    if ($contractPath) {
        $html .= '<div style="background: #f8f9fa; border: 2px solid #000; border-radius: 0; padding: 20px; margin: 20px 0; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #000; font-size: 18px; text-transform: uppercase;">Contract Generated</h3>
                    <p style="margin: 0; font-size: 14px; color: #333;">
                        A signed contract has been generated and is attached to this email.<br>
                        <strong>The contract includes all terms, conditions, and discount details.</strong>
                    </p>
                  </div>';
    }

    // Important notes
    $html .= '<div class="important-note">
                <h3 style="margin: 0 0 10px 0; color: #000; font-size: 16px;">⚠️ IMPORTANT NEXT STEPS</h3>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><strong>Contact the client within 24 hours</strong> to confirm all arrangements</li>
                    <li><strong>Add the event to your calendar</strong> with all relevant details</li>' .
                    ($hasDiscount ? '<li><strong>Note the promotional discount applied</strong> - client saved $' . number_format($totalSavings, 2) . '</li>' : '') .
                    '<li><strong>Confirm the service location</strong> and parking arrangements</li>
                    <li><strong>Check for any special requirements</strong> or allergies</li>
                </ul>
            </div>';

    $html .= '<div class="footer">
                <p><strong>BOOKING CONFIRMATION COMPLETE</strong></p>
                <p style="margin: 15px 0;">
                    All payment processing has been completed successfully.<br>
                    This booking is now confirmed and secured in the system.
                </p>
                <hr style="border: none; border-top: 2px solid #000; margin: 25px 0;">
                <p style="margin: 0; font-size: 12px; color: #999;">
                    This is an automated notification from Looks By Anum.<br>
                    Generated on ' . date('F j, Y \a\t g:i A T') . '
                </p>
            </div>
    </div>
</body>
</html>';

    return $html;
}
 /* Send admin notification email with contract attachment
 */
function sendAdminNotificationEmail($bookingData, $contractPath = null) {
    $adminEmail = 'info@looksbyanum.com';
    $clientName = $bookingData['client_name'] ?? $bookingData['name'] ?? 'Unknown Client';
    $bookingId = $bookingData['booking_id'] ?? $bookingData['unique_id'] ?? 'Unknown';
    $serviceType = $bookingData['service_type'] ?? 'Service';
    
    // Email subject
    $subject = "New Booking Confirmed - {$clientName} ({$serviceType}) - Booking #{$bookingId}";
    
    // Generate HTML email content
    $htmlContent = generateAdminEmailTemplate($bookingData, $contractPath);
    
    // Email headers
    $boundary = "boundary_" . md5(uniqid(time()));
    
    $headers = "From: Looks By Anum Booking<noreply@looksbyanum.com>\r\n";
    $headers .= "Reply-To: info@looksbyanum.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // Start building email body
    $emailBody = "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $emailBody .= $htmlContent . "\r\n\r\n";
    
    // Add contract attachment if available
    if ($contractPath && file_exists($contractPath)) {
        $filename = basename($contractPath);
        $file_size = filesize($contractPath);
        $handle = fopen($contractPath, "r");
        $content = fread($handle, $file_size);
        fclose($handle);
        
        $encoded_content = chunk_split(base64_encode($content));
        
        $emailBody .= "--{$boundary}\r\n";
        $emailBody .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
        $emailBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
        $emailBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $emailBody .= $encoded_content . "\r\n\r\n";
        
        debugLog("Contract attachment added to admin email: " . $filename);
    }
    
    $emailBody .= "--{$boundary}--\r\n";
    
    // Send email
    $mailResult = mail($adminEmail, $subject, $emailBody, $headers);
    
    if ($mailResult) {
        debugLog("Admin notification email sent successfully to: " . $adminEmail);
        debugLog("Email subject: " . $subject);
        return true;
    } else {
        debugLog("Failed to send admin notification email to: " . $adminEmail);
        debugLog("Email subject: " . $subject);
        return false;
    }
}

/**
 * Alternative method using PHPMailer (if available)
 * This provides better reliability for email delivery
 */
function sendAdminNotificationEmailPHPMailer($bookingData, $contractPath = null) {
    // Check if PHPMailer is available
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        debugLog("PHPMailer not available, falling back to mail() function");
        return sendAdminNotificationEmail($bookingData, $contractPath);
    }
    
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = env('SMTP_HOST', 'smtp.gmail.com'); // Set from .env
        $mail->SMTPAuth   = true;
        $mail->Username   = env('SMTP_USERNAME', ''); // Set from .env
        $mail->Password   = env('SMTP_PASSWORD', ''); // Set from .env
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('noreply@looksbyanum.com', 'Looks By Anum');
        $mail->addAddress('info@looksbyanum.com', 'Looks By Anum Admin');
        $mail->addReplyTo('info@looksbyanum.com', 'Looks By Anum');
        
        // Content
        $clientName = $bookingData['client_name'] ?? $bookingData['name'] ?? 'Unknown Client';
        $bookingId = $bookingData['booking_id'] ?? $bookingData['unique_id'] ?? 'Unknown';
        $serviceType = $bookingData['service_type'] ?? 'Service';
        
        $mail->isHTML(true);
        $mail->Subject = "New Booking Confirmed - {$clientName} ({$serviceType}) - Booking #{$bookingId}";
        $mail->Body    = generateAdminEmailTemplate($bookingData, $contractPath);
        
        // Add contract attachment if available
        if ($contractPath && file_exists($contractPath)) {
            $mail->addAttachment($contractPath, basename($contractPath));
            debugLog("Contract attachment added to PHPMailer email: " . basename($contractPath));
        }
        
        $mail->send();
        debugLog("Admin notification email sent successfully via PHPMailer");
        return true;
        
    } catch (Exception $e) {
        debugLog("PHPMailer failed: " . $e->getMessage());
        debugLog("Falling back to mail() function");
        return sendAdminNotificationEmail($bookingData, $contractPath);
    }
}




// UPDATED: generateServicesBreakdownForIndex function
// Replace your existing generateServicesBreakdownForIndex function with this enhanced version:

function generateServicesBreakdownForIndex($bookingData) {
    global $pdo, $table;
    
    $bookingId = $bookingData['unique_id'] ?? '';
    
    if (empty($bookingId)) {
        return '<div class="services-breakdown">
                   <h3>Services Breakdown</h3>
                   <p style="text-align:center; color:#666;">Booking ID not available.</p>
               </div>';
    }
    
    try {
        // Get complete booking data from database
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            return '<div class="services-breakdown">
                       <h3>Services Breakdown</h3>
                       <p style="text-align:center; color:#666;">Booking data not found.</p>
                   </div>';
        }
        
        // Get coupon information
        $discountAmount = floatval($booking['discount_amount'] ?? 0);
        $promoCode = $booking['promo_code'] ?? '';
        $actualAmountPaid = floatval($booking['amount_paid'] ?? 0);
        $hasDiscount = ($discountAmount > 0 && !empty($promoCode));
        
        // Calculate totals
        $currentTotal = floatval($booking['quote_total'] ?? $booking['price'] ?? 0);
        $originalTotal = $hasDiscount ? ($currentTotal + $discountAmount) : $currentTotal;
        
        // Try to use quote calculation for service breakdown
        $artistType = $booking['artist'] ?? 'team';
        $artistName = (stripos($artistType, 'anum') !== false) ? 'By Anum' : 'By Team';
        $quoteData = getDetailedQuoteForArtist($artistName, $booking);
        
        $html = '<div class="services-breakdown">
                   <h3>Services Breakdown</h3>';
        
        // Show individual services if available
        if ($quoteData && !empty($quoteData['quote'])) {
            foreach ($quoteData['quote'] as $service) {
                $html .= '<div class="service-item">
                            <span class="service-name">' . htmlspecialchars($service['description']) . '</span>
                            <span class="service-price">$' . number_format($service['price'], 2) . '</span>
                          </div>';
            }
            
            // Add subtotal and HST breakdown  
            $subtotal = $hasDiscount ? ($originalTotal / 1.13) : ($currentTotal / 1.13);
            $hst = $hasDiscount ? ($originalTotal - $subtotal) : ($currentTotal - $subtotal);
            
            $html .= '<hr style="margin: 8px 0; border: 1px solid #dee2e6;">';
            
            // Show original amounts if discount was applied
            if ($hasDiscount) {
                $html .= '
                          <div class="service-item" style="padding: 4px 0;">
                            <span class="service-name">Total Before Discounts:</span>
                            <span class="service-price" style="text-decoration: line-through; color: #999;">$' . number_format($originalTotal, 2) . '</span>
                          </div>
                          <div class="service-item" style="padding: 4px 0; color: #dc3545; font-weight: 600;">
                            <span class="service-name">You saved:</span>
                            <span class="service-price">-$' . number_format($discountAmount, 2) . '</span>
                          </div>';
                
                // Recalculate after discount
                $newSubtotal = $currentTotal / 1.13;
                $newHst = $currentTotal - $newSubtotal;
                
                $html .= '<div class="service-item" style="padding: 4px 0; color: #28a745; font-weight: 600;">
                            <span class="service-name">Subtotal:</span>
                            <span class="service-price">$' . number_format($newSubtotal, 2) . '</span>
                          </div>
                          <div class="service-item" style="padding: 4px 0; color: #28a745; font-weight: 600;">
                            <span class="service-name">HST (13%):</span>
                            <span class="service-price">$' . number_format($newHst, 2) . '</span>
                          </div>';
            } else {
                // No discount - show normal breakdown
                $html .= '<div class="service-item" style="padding: 4px 0;">
                            <span class="service-name">Subtotal:</span>
                            <span class="service-price">$' . number_format($subtotal, 2) . '</span>
                          </div>
                          <div class="service-item" style="padding: 4px 0;">
                            <span class="service-name">HST (13%):</span>
                            <span class="service-price">$' . number_format($hst, 2) . '</span>
                          </div>';
            }
            
            // Final total
            $html .= '<div class="service-item total-row" style="padding-top: 8px; font-weight: 600; font-size: 16px; border-top: 1px solid #28a745;">
                        <span class="service-name">Total After Discount:</span>
                        <span class="service-price">$' . number_format($currentTotal, 2) . ' CAD</span>
                      </div>';
            
            // Payment information with actual amounts
            if ($actualAmountPaid > 0) {
                $serviceType = $booking['service_type'] ?? 'Bridal';
                $depositRate = (stripos($serviceType, 'non-bridal') !== false) ? '50%' : '30%';
                
                // Show what was actually paid (from Stripe)
                $html .= '<div class="service-item" style="background: #e7f3ff; border-top: 1px solid #28a745; font-weight: 600; color: #0056b3; padding: 8px 0;">
                            <span class="service-name">Amount Paid (' . $depositRate . ' Deposit):</span>
                            <span class="service-price">$' . number_format($actualAmountPaid, 2) . ' CAD</span>
                          </div>';
                
                // Show savings if discount was applied
                if ($hasDiscount) {
                    $originalDeposit = $originalTotal * (($depositRate === '50%') ? 0.5 : 0.3);
                    $savedOnDeposit = $originalDeposit - $actualAmountPaid;
                    
                    // if ($savedOnDeposit > 0) {
                    //     $html .= '<div class="service-item" style="background: #d4edda; padding: 4px 0; color: #155724; font-weight: 500;">
                    //                 <span class="service-name">You Saved on Deposit:</span>
                    //                 <span class="service-price">$' . number_format($savedOnDeposit, 2) . ' CAD</span>
                    //               </div>';
                    // }
                }
                
                $remainingBalance = $currentTotal - $actualAmountPaid;
                if ($remainingBalance > 0) {
                    $html .= '<div class="service-item" style="color: #dc3545; font-weight: 500; padding: 4px 0;">
                                <span class="service-name">Remaining Balance (due on event day):</span>
                                <span class="service-price">$' . number_format($remainingBalance, 2) . ' CAD</span>
                              </div>';
                }
                
                // Total savings summary if discount was applied
                // if ($hasDiscount) {
                //     $html .= '<div class="service-item" style="background: #fff3cd; padding: 8px; border-radius: 4px; margin-top: 8px; font-weight: 600; color: #856404;">
                //                 <span class="service-name">🎁 Total Savings with ' . htmlspecialchars($promoCode) . ':</span>
                //                 <span class="service-price">$' . number_format($discountAmount, 2) . ' CAD</span>
                //               </div>';
                // }
            }
        } else {
            // Fallback if quote calculation fails
            $html .= '<div class="service-item total-row">
                        <span class="service-name">Total Amount:</span>
                        <span class="service-price">$' . number_format($currentTotal, 2) . ' CAD</span>
                      </div>';
            
            if ($actualAmountPaid > 0) {
                $html .= '<div class="service-item">
                            <span class="service-name">Amount Paid:</span>
                            <span class="service-price">$' . number_format($actualAmountPaid, 2) . ' CAD</span>
                          </div>';
            }
        }
        
        $html .= '</div>';
        
        return $html;
        
    } catch (Exception $e) {
        error_log("Services breakdown error: " . $e->getMessage());
        return '<div class="services-breakdown">
                   <h3>Services Breakdown</h3>
                   <p style="text-align:center; color:#666;">Error loading services. Please contact support.</p>
               </div>';
    }
}
// Calculate quotes for both artists if not destination wedding
$anumQuote = null;
$teamQuote = null;
if (!$isDestinationWedding) {
    $anumQuote = getDetailedQuoteForArtist('By Anum', $b);
    $teamQuote = getDetailedQuoteForArtist('By Team', $b);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your Bridal Quote – <?=h($b['unique_id'])?></title>
<style>
  :root{--bg:#fafafa;--card:#ffffff;--bd:#e6e6e6;--fg:#111111;--muted:#666666;--accent:#e91e63}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;margin:0;padding:28px;background:var(--bg);color:var(--fg);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
  .container{max-width:980px;margin:0 auto;background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:28px;box-shadow:0 5px 20px rgba(0,0,0,.05)}
  h1{margin:0 0 6px 0;text-align:center;font-size:28px;letter-spacing:-0.4px;color:var(--fg)} h2{margin:24px 0 12px}
  .muted{color:var(--muted);font-size:14px}
  
  /* Quote cards */
  .quote-cards{display:grid;grid-template-columns: 1fr 1fr;gap:20px;margin-bottom:30px}
  .quote-card{background:white;padding:20px;border-radius:12px;border:2px solid var(--bd);min-height:150px}
  .quote-card h3{margin:0 0 8px 0;font-size:18px}
  .quote-card .desc{margin:0 0 12px 0;color:var(--muted);font-size:14px}
  .quote-card .items{font-size:14px;color:var(--fg);line-height:1.55;margin-bottom:12px}
  .quote-card .total{font-weight:700;font-size:16px}
  .quote-card .deposit{font-weight:700;font-size:15px;color:var(--fg)}

  /* Action buttons */
  .action-buttons{display:flex;justify-content: center;gap:16px;margin:20px 0;flex-wrap: wrap}
  .action-btn{display:flex;flex-direction:column;align-items:center;padding:12px 20px;background:#fff;border:1px solid var(--bd);border-radius:4px;cursor:pointer;text-decoration:none;color:var(--fg);transition: all 0.2s ease;text-align: center;min-width: 200px}
  .action-btn:hover{background:var(--fg);color:#fff;text-decoration:none}
  .action-btn h4{margin:0 0 4px 0;font-size:14px;font-weight:600}
  .action-btn p{margin:0;font-size:12px;color:inherit}

  /* Form styles */
  .booking-type-options{display: flex;justify-content: center;gap: 16px;margin: 16px 0;flex-wrap: wrap}
  .booking-type-options label{display: flex;align-items: center;cursor: pointer;padding: 8px 12px;border: 1px solid var(--bd);border-radius: 4px;background: white;transition: all 0.2s ease}
  .booking-type-options label span{font-size: 14px;color: var(--fg)}
  .booking-type-options label:hover{background: var(--fg);color: white}
  .booking-type-options label:hover span{color: white}
  .booking-type-options label.selected{background: var(--fg);color: white}
  .booking-type-options label.selected span{color: white}
  .city-province-grid {display: grid;grid-template-columns: 1fr 1fr;gap: 12px;margin-bottom: 16px}
  .summary-grid {display: flex;justify-content: space-between;align-items: flex-start;margin-bottom: 8px}
  .summary-grid .label {font-weight: 600;color: var(--fg);margin-right: 12px}
  .summary-grid .value {text-align: right;flex: 1}
  .spinner {border: 3px solid #f3f3f3;border-top: 3px solid var(--fg);border-radius: 50%;width: 20px;height: 20px;animation: spin 1s linear infinite;display: inline-block;margin-right: 8px}
  .date-row { 
    display: flex; 
    gap: 18px; 
    justify-content: center; 
    align-items: flex-start; 
    margin-bottom: 18px; 
}

.date-check { 
    text-align: center; 
    width: 320px;
}

.date-check label { 
    display: block; 
    margin-bottom: 8px; 
    font-weight: 600; 
    font-size: 18px;
    color: var(--fg);
}

.date-check input[type=date] {
    padding: 10px 12px; 
    font-size: 15px; 
    border: 1px solid var(--bd); 
    border-radius: 6px; 
    background: transparent; 
    color: var(--fg); 
    width: 100%;
    box-sizing: border-box;
}

.warning {
    display: block;
    width: 100%;
    box-sizing: border-box;
    margin-top: 12px;
    padding: 12px 14px;
    background: #fff;
    color: var(--fg);
    border-left: 4px solid var(--fg);
    border-radius: 6px;
    font-weight: 600;
}
.error {
    display: none;
}

.error.show {
    display: block;
    color: red;
}
/* Responsive */
@media (max-width: 820px) { 
    .date-row { 
        flex-direction: column; 
        align-items: center;
    }
}
  @keyframes spin {0% { transform: rotate(0deg); }100% { transform: rotate(360deg); }}
  
  @media (max-width:820px){
    .quote-cards{grid-template-columns: 1fr}.container{padding:18px}.booking-type-options{flex-direction: column;align-items: center}
    .city-province-grid{grid-template-columns: 1fr !important}
    .summary-grid{flex-direction: column;align-items: flex-start}
    .summary-grid .value{text-align: left;margin-top: 4px}
  }
</style>
</head>
<body>
  <div class="container">
    <h1>Looks By Anum</h1>
    <p style="text-align:center;color:var(--muted);margin:0 0 30px 0">View your personalised quote below and choose your next step.</p>
    
    <?php if (!$isDestinationWedding): ?>
    <div class="date-row">
        <div class="date-check">
            <label for="eventDate">Select your event date</label>
            <input type="date" id="eventDate" value="<?= !empty($b['event_date']) ? h($b['event_date']) : '' ?>" />
            <div id="dateMessage" aria-live="polite"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isDestinationWedding): ?>
      <div id="content">
        <div style="text-align:left; margin-bottom:12px;">
          <h2 style="margin:0 0 6px 0; font-size:20px;">Available Packages</h2>
          <p style="margin:0; color:var(--muted);">Both packages are shown below — choose how you'd like to proceed.</p>
        </div>

        <div class="quote-cards">
          <?php if ($anumQuote && $teamQuote): ?>
          <!-- Anum Package -->
          <div class="quote-card">
            <h3>Anum Package</h3>
            <div class="desc">Premium service by Anum herself</div>
            <div class="items">
              <?php foreach ($anumQuote['quote'] as $item): ?>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                <span style="font-size:13px"><?=h($item['description'])?></span>
                <span style="font-size:13px">$<?=number_format($item['price'], 2)?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="subtotal" style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">Subtotal:</span><span style="font-size:13px"> $<?=number_format($anumQuote['subtotal'], 2)?></span></div>
            <div class="hst" style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">HST (13%):</span><span style="font-size:13px"> $<?=number_format($anumQuote['hst'], 2)?></span></div>
            <div class="total" style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">Total:</span><span style="font-size:13px"> $<?=number_format($anumQuote['total'], 2)?></span></div>
            <div class="deposit" style="display:flex;justify-content:space-between;margin-bottom:4px">
              <?php 
              $depositRate = (stripos($b['service_type'], 'non-bridal') !== false) ? 0.5 : 0.3;
              $depositPercent = ($depositRate === 0.5) ? '50%' : '30%';
              $depositAmount = $anumQuote['total'] * $depositRate;
              ?>
              <span style="font-size:13px">Deposit required (<?=$depositPercent?>):</span><span style="font-size:13px"> $<?=number_format($depositAmount, 2)?></span>
            </div>
          </div>

          <!-- Team Package -->
          <div class="quote-card">
            <h3>Team Package</h3>
            <div class="desc">Professional service by trained team members</div>
            <div class="items">
              <?php foreach ($teamQuote['quote'] as $item): ?>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                <span style="font-size:13px"><?=h($item['description'])?></span>
                <span style="font-size:13px">$<?=number_format($item['price'], 2)?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="subtotal" style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">Subtotal:</span><span style="font-size:13px"> $<?=number_format($teamQuote['subtotal'], 2)?></span></div>
            <div class="hst" style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">HST (13%):</span><span style="font-size:13px"> $<?=number_format($teamQuote['hst'], 2)?></span></div>
            <div class="total" style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px">Total:</span><span style="font-size:13px"> $<?=number_format($teamQuote['total'], 2)?></span></div>
            <div class="deposit" style="display:flex;justify-content:space-between;margin-bottom:4px">
              <?php $depositAmount = $teamQuote['total'] * $depositRate; ?>
              <span style="font-size:13px">Deposit required (<?=$depositPercent?>):</span> <span style="font-size:13px">$<?=number_format($depositAmount, 2)?></span>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Next steps section -->
        <div style="margin-top:20px; padding:16px; border-radius:8px; border:1px solid var(--bd); background:transparent;">
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
        </div>
      </div>
    <?php endif; ?>

    <?php if ($isDestinationWedding): ?>
    <h2>Custom Quote Required</h2>
    <div style="padding:20px;background:#fff3cd;border:2px solid #ffc107;border-radius:8px;color:#856404">
      <p><strong>Destination Wedding Service</strong></p>
      <p>We'll contact you with a custom quote based on your specific destination wedding requirements.</p>
      <p>Please call us at <strong>(506) 789-9876</strong> to discuss your needs.</p>
    </div>
    <?php endif; ?>

  </div>
<script>
// COMPLETE JAVASCRIPT FOR INDEX.PHP - Replace the existing JavaScript section

// Global variables
const bookingData = <?php echo json_encode($b); ?>;
const anumQuote = <?php echo $anumQuote ? json_encode($anumQuote) : 'null'; ?>;
const teamQuote = <?php echo $teamQuote ? json_encode($teamQuote) : 'null'; ?>;

window.serverQuotes = { 'Anum': anumQuote, 'Team': teamQuote };
function getCurrentQuote(artistType) {
    const baseQuote = window.serverQuotes[artistType];
    if (!baseQuote) return null;
    
    // If user selected 'bridal' only, filter out trial services
    if (window.currentBooking && window.currentBooking.bookingType === 'bridal') {
        const filteredItems = baseQuote.quote.filter(item => 
            !item.description.toLowerCase().includes('trial')
        );
        
        const newSubtotal = filteredItems.reduce((sum, item) => sum + item.price, 0);
        const newHst = newSubtotal * 0.13;
        const newTotal = newSubtotal + newHst;
        
        return {
            quote: filteredItems,
            subtotal: newSubtotal,
            hst: newHst,
            total: newTotal
        };
    }
    
    // For 'both' selection or no selection, return full quote
    return baseQuote;
}
window.formData = { streetAddress: '', city: '', province: '', postalCode: '', readyTime: '' };

console.log('JavaScript loaded successfully');
console.log('Booking data:', bookingData);

// Book Now handler

function handleBookNow() {
    console.log('=== handleBookNow DEBUG START ===');
    console.log('Book Now clicked');
    console.log('bookingData:', bookingData);
    console.log('bookingData.service_type:', bookingData.service_type);
    
    // Validate that we have quotes
    if (!anumQuote || !teamQuote) {
        alert('Error: Quote data not available. Please refresh the page.');
        return;
    }
    
    const contentDiv = document.getElementById('content');
    if (!contentDiv) {
        alert('Error: Content area not found');
        return;
    }
    
    // Check service type to determine which flow to use
    const serviceType = bookingData.service_type || 'Bridal';
    console.log('Final serviceType:', serviceType);
    console.log('serviceType === "Bridal":', serviceType === 'Bridal');
    console.log('serviceType !== "Bridal":', serviceType !== 'Bridal');
    
    if (serviceType === 'Bridal') {
        console.log('🔵 TAKING BRIDAL PATH - will show booking options');
        // BRIDAL FLOW: Use selectArtist (which leads to booking options)
        contentDiv.innerHTML = `
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--fg);">Choose Your Artist</h2>
                <p style="margin: 0; color: var(--muted); font-size: 16px;">
                    Select which artist you'd like to book with for your ${serviceType}
                </p>
            </div>

            <div style="max-width: 600px; margin: 0 auto;">
                <div style="display: flex; justify-content: center; gap: 20px; margin: 16px 0; flex-wrap: wrap;">
                    <button onclick="selectArtist('anum')" style="cursor: pointer; min-width: 250px; padding: 20px; background: white; border: 1px solid var(--bd); border-radius: 4px; font-size: 16px; transition: all 0.2s;">
                        👑 Book with Anum<br>
                        <small style="color: var(--muted);">Total: $${anumQuote.total.toFixed(2)} CAD</small>
                    </button>
                    <button onclick="selectArtist('team')" style="cursor: pointer; min-width: 250px; padding: 20px; background: white; border: 1px solid var(--bd); border-radius: 4px; font-size: 16px; transition: all 0.2s;">
                        👥 Book with Team<br>
                        <small style="color: var(--muted);">Total: $${teamQuote.total.toFixed(2)} CAD</small>
                    </button>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button onclick="goBackToQuotes()" style="padding: 12px 24px; background: white; border: 2px solid var(--fg); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s;">
                        ← Back to Quotes
                    </button>
                </div>
            </div>
        `;
    } else {
        console.log('🟢 TAKING NON-BRIDAL PATH - will skip booking options');
        // NON-BRIDAL/SEMI-BRIDAL FLOW: Use selectDirectServiceArtist (skips to time selection)
        contentDiv.innerHTML = `
            <div style="text-align: center; margin-bottom: 30px;">
                <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--fg);">Choose Your Artist</h2>
                <p style="margin: 0; color: var(--muted); font-size: 16px;">
                    Select which artist you'd like to book with for your ${serviceType}
                </p>
            </div>

            <div style="max-width: 600px; margin: 0 auto;">
                <div style="display: flex; justify-content: center; gap: 20px; margin: 16px 0; flex-wrap: wrap;">
                    <button onclick="selectDirectServiceArtist('anum')" style="cursor: pointer; min-width: 250px; padding: 20px; background: white; border: 1px solid var(--bd); border-radius: 4px; font-size: 16px; transition: all 0.2s;">
                        👑 Book with Anum<br>
                        <small style="color: var(--muted);">Total: $${anumQuote.total.toFixed(2)} CAD</small>
                    </button>
                    <button onclick="selectDirectServiceArtist('team')" style="cursor: pointer; min-width: 250px; padding: 20px; background: white; border: 1px solid var(--bd); border-radius: 4px; font-size: 16px; transition: all 0.2s;">
                        👥 Book with Team<br>
                        <small style="color: var(--muted);">Total: $${teamQuote.total.toFixed(2)} CAD</small>
                    </button>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button onclick="goBackToQuotes()" style="padding: 12px 24px; background: white; border: 2px solid var(--fg); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s;">
                        ← Back to Quotes
                    </button>
                </div>
            </div>
        `;
    }
    
    console.log('=== handleBookNow DEBUG END ===');
}

function selectDirectServiceArtist(artistType) {
    console.log('🟢 selectDirectServiceArtist called with:', artistType);
    
    const artistName = artistType === 'anum' ? 'Anum' : 'Team';
    const serviceType = bookingData.service_type || 'Non-Bridal';
    
    // Set state variables for consistency
    window.currentBooking = {
        artistType: artistType,
        artistName: artistName,
        bookingType: 'service',
        serviceType: serviceType
    };
    
    window.selectedArtist = {
        type: artistType,
        name: artistName
    };
    
    console.log('🟢 Skipping booking options - calling selectBookingType directly');
    // Skip directly to time selection for non-bridal services
    selectBookingType(artistType, 'service');
}
// Schedule Call handler
function handleScheduleCall() {
    console.log('Schedule Call clicked');
    
    const contentDiv = document.getElementById('content');
    if (!contentDiv) {
        alert('Error: Content area not found');
        return;
    }
    
    contentDiv.innerHTML = `
        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--fg);">Schedule Your Consultation</h2>
            <p style="margin: 0; color: var(--muted); font-size: 16px;">
                Choose a convenient time to discuss your needs
            </p>
        </div>

        <div style="background: white; border-radius: 8px; border: 1px solid var(--bd); overflow: hidden; margin-bottom: 20px;">
            <div class="calendly-inline-widget" data-url="https://calendly.com/ismaanwar-ia?hide_landing_page_details=1" style="min-width:320px;height:630px;"></div>
        </div>
        
        <div style="text-align: center;">
            <button onclick="goBackToQuotes()" style="padding: 12px 24px; background: white; border: 2px solid var(--fg); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s;">
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
        console.log('Calendly script loaded');
    }
}

function selectArtist(artistType) {
    console.log('Artist selected:', artistType);
    
    const artistName = artistType === 'anum' ? 'Anum' : 'Team';
    const serviceType = bookingData.service_type || 'Bridal';
    
    // Store selected artist
    window.currentBooking = {
        artistType: artistType,
        artistName: artistName,
        bookingType: 'bridal' // Default booking type
    };
    
    // For Bridal service, show booking options (Bridal vs Bridal + Trial)
    // For other services, skip directly to time selection
    if (serviceType === 'Bridal') {
        showBookingOptions(artistType, artistName);
    } else {
        // Skip booking options step and go directly to time selection
        console.log('Skipping booking options for', serviceType, '- going directly to time selection');
        selectBookingType(artistType, 'bridal');
    }
}

// FIXED FUNCTION: Now checks service type to show trial option only for Bridal
function showBookingOptions(artistType, artistName) {
    const contentDiv = document.getElementById('content');
    if (!contentDiv) {
        alert('Error: Content area not found');
        return;
    }
    
    // Check if this is a Bridal service (trial option only available for Bridal)
    const serviceType = bookingData.service_type || 'Bridal';
    const showTrialOption = (serviceType === 'Bridal');
    
    const serviceLabel = serviceType === 'Bridal' ? 'Bridal Service' : serviceType + ' Service';
    
    contentDiv.innerHTML = `
        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="margin: 0 0 8px 0; font-size: 32px; color: var(--fg); font-weight: 600;">What would you like to book with ${artistName}?</h2>
            <p style="margin: 0; color: var(--muted); font-size: 18px;">
                Choose the service you'd like to book
            </p>
        </div>

        <div style="max-width: 600px; margin: 0 auto;">
            
            <!-- Main Service Option -->
            <div style="margin-bottom: 20px;">
                <label style="display: flex; align-items: center; padding: 20px; border: 2px solid #e6e6e6; border-radius: 12px; cursor: pointer; background: white; transition: all 0.2s ease;" 
                       onmouseover="this.style.borderColor='#111'; this.style.backgroundColor='#f8f9fa'" 
                       onmouseout="this.style.borderColor='#e6e6e6'; this.style.backgroundColor='white'"
                       onclick="selectBookingType('${artistType}', 'bridal')">
                    <input type="radio" name="bookingType" value="bridal" style="width: 20px; height: 20px; margin-right: 16px; cursor: pointer;">
                    <span style="font-size: 16px; font-weight: 500; color: var(--fg);">👑 ${serviceLabel}</span>
                </label>
            </div>

            ${showTrialOption ? `
            <!-- Bridal + Trial Option (only for Bridal service) -->
            <div style="margin-bottom: 40px;">
                <label style="display: flex; align-items: center; padding: 20px; border: 2px solid #e6e6e6; border-radius: 12px; cursor: pointer; background: white; transition: all 0.2s ease;" 
                       onmouseover="this.style.borderColor='#111'; this.style.backgroundColor='#f8f9fa'" 
                       onmouseout="this.style.borderColor='#e6e6e6'; this.style.backgroundColor='white'"
                       onclick="selectBookingType('${artistType}', 'both')">
                    <input type="radio" name="bookingType" value="both" style="width: 20px; height: 20px; margin-right: 16px; cursor: pointer;">
                    <span style="font-size: 16px; font-weight: 500; color: var(--fg);">🎉 Bridal + Trial</span>
                </label>
            </div>
            ` : `
            <!-- For Semi Bridal or Non-Bridal, no trial option -->
            <div style="margin-bottom: 40px; padding: 16px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                <p style="margin: 0; font-size: 14px; color: var(--muted);">Trial sessions are only available for full Bridal services.</p>
            </div>
            `}
            
            <div style="text-align: center;">
                <button onclick="goBackToArtistSelection()" style="padding: 16px 32px; background: white; border: 2px solid var(--fg); border-radius: 8px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s; font-weight: 500;">
                    ← Back to Artist Selection
                </button>
            </div>
        </div>
    `;
}

function selectBookingType(artistType, bookingType) {
    const artistName = artistType === 'anum' ? 'Anum' : 'Team';
    
    // Update current booking with type
    window.currentBooking.bookingType = bookingType;
    
    console.log('Booking type selected:', bookingType, 'with artist:', artistName);
    
    // Replace entire content with booking form
    document.getElementById('content').innerHTML = `
        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--fg);">Complete Your Booking</h2>
            <p style="margin: 0; color: var(--muted); font-size: 16px;">
                ${bookingType === 'service' ? (window.currentBooking?.serviceType || bookingData.service_type) : getServiceDescription(bookingType)} with <strong>${artistName}</strong>
            </p>
        </div>

        <div style="max-width: 500px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--bd);">
            
            ${bookingType === 'both' ? `
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--fg); font-size: 16px;">Select your trial date</label>
                    <input type="date" id="bookingTrialDate" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--bd); border-radius: 6px; background: white; color: var(--fg); box-sizing: border-box;" />
                    <div id="trialDateError" style="color: #dc3545; font-size: 14px; margin-top: 8px; text-align: center;"></div>
                </div>
            ` : ''}

            <div style="margin-bottom: 24px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--fg); font-size: 16px;">What time do you need to be ready?</label>
                <p style="font-size: 14px; color: var(--muted); margin-bottom: 12px;">This is the time you need to be completely ready by, not the start time.</p>
                <input type="time" id="readyTimeInput" value="${bookingData.ready_time || ''}" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--bd); border-radius: 6px; background: white; color: var(--fg); box-sizing: border-box;" />
            </div>

            <div id="appointmentTimeMessage" style="margin-bottom: 24px;"></div>

            <div style="display: flex; gap: 12px;">
                <button onclick="goBackToBookingOptions()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--fg); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s;">
                    ← Back
                </button>
                <button id="continueToAddressBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                    Continue to Address
                </button>
            </div>
        </div>
    `;

    // Set up date and time validation
    setupBookingFormValidation(bookingType);
}

function getServiceDescription(bookingType) {
    switch(bookingType) {
        case 'both': return 'Bridal Service + Trial';
        case 'bridal': return bookingData.service_type || 'Bridal Service';
        default: return bookingData.service_type || 'Bridal Service';
    }
}

function setupBookingFormValidation(bookingType) {
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
        if (window.formData && window.formData.trialDate) {
            trialDateInput.value = window.formData.trialDate;
        }
    }

    // Restore saved ready time
    if (window.formData && window.formData.readyTime) {
        readyTimeInput.value = window.formData.readyTime;
    } else if (bookingData.ready_time) {
        readyTimeInput.value = bookingData.ready_time;
    }

    const validateForm = () => {
        let isValid = true;
        
        // Validate trial date if needed (only for 'both' option now)
        if (bookingType === 'both') {
            const trialDate = trialDateInput.value;
            const eventDateInput = document.getElementById('eventDate');
            const eventDateValue = eventDateInput ? eventDateInput.value : bookingData.event_date;
            
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
                    if (!window.formData) window.formData = {};
                    window.formData.trialDate = trialDate;
                }
            }
        }

        // Validate ready time
        const readyTime = readyTimeInput.value;
        if (!readyTime) {
            isValid = false;
        } else {
            // Save ready time
            if (!window.formData) window.formData = {};
            window.formData.readyTime = readyTime;
        }

        // Update button state
        if (isValid && readyTime) {
            continueBtn.disabled = false;
            continueBtn.style.background = 'var(--fg)';
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
                <div style="padding: 16px; background: var(--bg); border-radius: 8px; border: 1px solid var(--bd);">
                    <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px; text-align: center; color: var(--fg);">Your Appointment Schedule</div>
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
            showAddressCollection();
        }
    });

    // Initial validation
    validateForm();
}

function showAddressCollection() {
    document.getElementById('content').innerHTML = `
        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--fg);">Service Address</h2>
            <p style="margin: 0; color: var(--muted); font-size: 16px;">
                Where should we provide the service?
            </p>
        </div>

        <div style="max-width: 500px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--bd);">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--fg); font-size: 16px;">Street Address</label>
                <input type="text" id="streetAddress" placeholder="123 Main Street" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--bd); border-radius: 6px; background: white; color: var(--fg); box-sizing: border-box;" />
                    <div id="streetAddressError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
            </div>

            <div class="city-province-grid">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--fg); font-size: 16px;">City</label>
                    <input type="text" id="city" placeholder="Toronto" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--bd); border-radius: 6px; background: white; color: var(--fg); box-sizing: border-box;" />
                    <div id="cityError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--fg); font-size: 16px;">Province</label>
                    <select id="province" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--bd); border-radius: 6px; background: white; color: var(--fg); box-sizing: border-box;">
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
                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: var(--fg); font-size: 16px;">Postal Code</label>
                <input type="text" id="postalCode" placeholder="M5V 3A8" maxlength="7" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--bd); border-radius: 6px; background: white; color: var(--fg); box-sizing: border-box;" />
                <div id="postalCodeError" style="color: #dc3545; font-size: 12px; margin-top: 4px;"></div>
                <p style="font-size: 12px; color: var(--muted); margin-top: 4px;">Canadian postal code format: A1A 1A1</p>
            </div>

            <div style="display: flex; gap: 12px;">
                <button onclick="goBackToTimeSelection()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--fg); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s;">
                    ← Back
                </button>
                <button id="confirmBookingBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                    Review Booking
                </button>
            </div>
        </div>
    `;

    setupAddressValidation();
}

function setupAddressValidation() {
    const streetAddressInput = document.getElementById('streetAddress');
    const cityInput = document.getElementById('city');
    const provinceInput = document.getElementById('province');
    const postalCodeInput = document.getElementById('postalCode');
    const confirmBtn = document.getElementById('confirmBookingBtn');

    const streetAddressError = document.getElementById('streetAddressError');
    const cityError = document.getElementById('cityError');
    const provinceError = document.getElementById('provinceError');
    const postalCodeError = document.getElementById('postalCodeError');

    const validatePostalCode = (postalCode) => {
        const canadianPostalRegex = /^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/;
        return canadianPostalRegex.test(postalCode);
    };
    
    const formatPostalCode = (value) => {
        let formatted = value.replace(/\s/g, '').toUpperCase();
        if (formatted.length > 6) {
            formatted = formatted.substring(0, 6);
        }
        if (formatted.length > 3) {
            formatted = formatted.substring(0, 3) + ' ' + formatted.substring(3);
        }
        return formatted;
    };

    postalCodeInput.addEventListener('input', (e) => {
        const formatted = formatPostalCode(e.target.value);
        e.target.value = formatted;
        window.formData.postalCode = formatted;
        validateAddressForm();
    });

    const validateAddressForm = () => {
    let isValid = true;
    
    const streetAddress = streetAddressInput.value.trim();
    if (!streetAddress) {
        isValid = false;
    } else {
        window.formData.streetAddress = streetAddress;
    }

    const city = cityInput.value.trim();
    if (!city) {
        isValid = false;
    } else {
        window.formData.city = city;
    }

    const province = provinceInput.value;
    if (!province) {
        isValid = false;
    } else {
        window.formData.province = province;
    }

    const postalCode = postalCodeInput.value.trim();
    if (!postalCode) {
        isValid = false;
    } else if (!validatePostalCode(postalCode)) {
        isValid = false;
    }

    if (isValid) {
        confirmBtn.disabled = false;
        confirmBtn.style.background = 'var(--fg)';
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

    confirmBtn.addEventListener('click', (e) => {
        if (!confirmBtn.disabled) {
            showBookingSummary(); 
        }
    });

    // Fill in any pre-existing data and validate
    if (window.formData) {
        if (window.formData.streetAddress) streetAddressInput.value = window.formData.streetAddress;
        if (window.formData.city) cityInput.value = window.formData.city;
        if (window.formData.province) provinceInput.value = window.formData.province;
        if (window.formData.postalCode) postalCodeInput.value = window.formData.postalCode;
    }

    validateAddressForm();
}

function showBookingSummary() {
    const artistType = window.currentBooking.artistType === 'anum' ? 'Anum' : 'Team';
    const quote = getCurrentQuote(artistType);
    
    if (!quote) {
        alert('Error: Quote data not available');
        return;
    }
    
    // Calculate deposit
    const depositRate = (bookingData.service_type && bookingData.service_type.toLowerCase().includes('non-bridal')) ? 0.5 : 0.3;
    const depositPercent = (depositRate === 0.5) ? '50%' : '30%';
    const depositAmount = quote.total * depositRate;
    
    const readyTime = window.formData.readyTime;
    const address = window.formData.streetAddress + ', ' + window.formData.city + ', ' + window.formData.province + ' ' + window.formData.postalCode;

    const [hours, minutes] = readyTime.split(':');
    const readyDate = new Date();
    readyDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);
    const appointmentDate = new Date(readyDate.getTime() - (2 * 60 * 60 * 1000));
    
    const appointmentTime = appointmentDate.toLocaleTimeString('en-US', {
        hour: 'numeric', minute: '2-digit', hour12: true
    });
    
    const readyTimeFormatted = readyDate.toLocaleTimeString('en-US', {
        hour: 'numeric', minute: '2-digit', hour12: true
    });

    document.getElementById('content').innerHTML = `
        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="margin: 0 0 8px 0; font-size: 24px; color: var(--fg);">Booking Summary</h2>
            <p style="margin: 0; color: var(--muted); font-size: 16px;">Please review your booking details below</p>
        </div>

        <div style="max-width: 600px; margin: 0 auto; padding: 24px; background: white; border-radius: 12px; border: 2px solid var(--bd);">
            
            <!-- Service Details -->
            <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--bd);">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--fg);">Service Details</h3>
                <div style="display: grid; gap: 12px;">
                    <div class="summary-grid">
                        <span class="label">Artist:</span>
                        <span class="value">${window.currentBooking.artistName}</span>
                    </div>
                    <div class="summary-grid">
                        <span class="label">Service:</span>
                        <span class="value">${window.currentBooking.bookingType === 'both' ? bookingData.service_type + ' + Trial' : bookingData.service_type}</span>
                    </div>
                    <div class="summary-grid">
                        <span class="label">Event Date:</span>
                        <span class="value">${bookingData.event_date ? new Date(bookingData.event_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) : 'Not specified'}</span>
                    </div>
                </div>
            </div>

            <!-- Appointment Schedule -->
            <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--bd);">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--fg);">Appointment Schedule</h3>
                <div style="padding: 16px; background: var(--bg); border-radius: 8px; border: 1px solid var(--bd);">
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
            <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--bd);">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--fg);">Pricing Summary</h3>
                <div style="display: grid; gap: 8px;">
                    ${quote.quote.map(item => `
                        <div style="display: flex; justify-content: space-between; font-size: 14px;">
                            <span>${item.description}</span>
                            <span>$${item.price.toFixed(2)}</span>
                        </div>
                    `).join('')}
                    <hr style="margin: 12px 0; border: 1px solid var(--bd);">
                    <div style="display: flex; justify-content: space-between; font-size: 14px; color: var(--muted);">
                        <span>Subtotal:</span>
                        <span>$${quote.subtotal.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 14px; color: var(--muted); margin-bottom: 8px;">
                        <span>HST (13%):</span>
                        <span>$${quote.hst.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 16px;">
                        <span>Total (with 13% HST):</span>
                        <span>$${quote.total.toFixed(2)} CAD</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: 600; margin-top: 8px; color: var(--fg);">
                        <span>Amount to Pay (${depositPercent}):</span>
                        <span>$${depositAmount.toFixed(2)} CAD</span>
                    </div>
                </div>
            </div>
            

            <!-- Terms & Conditions Section -->
            <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--bd);">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--fg);">Terms & Conditions</h3>
                <div style="max-height: 200px; overflow-y: auto; padding: 12px; background: var(--bg); border: 1px solid var(--bd); border-radius: 6px; font-size: 13px; line-height: 1.5;">
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
                    <p style="margin: 0 0 8px 0;">The total price for the makeup and hair services is <strong>$${quote.total.toFixed(2)} CAD</strong>. A non-refundable deposit of <strong>${depositPercent}</strong> is required to secure your booking. The remaining balance will be due on the day of the event.</p>
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
            <div style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 2px solid var(--bd);">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; color: var(--fg);">Digital Signature</h3>
                <p style="margin: 0 0 12px 0; font-size: 14px; color: var(--muted);">
                    Please sign below to confirm your agreement to the booking and terms:
                </p>
                
                <div style="border: 2px solid var(--bd); border-radius: 8px; background: white; padding: 12px; margin-bottom: 12px;">
                    <canvas 
                        id="signatureCanvas" 
                        width="400" 
                        height="150" 
                        style="border: 1px dashed #ccc; cursor: crosshair; width: 100%; height: 150px; display: block;"
                    ></canvas>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button onclick="clearSignature()" style="padding: 8px 16px; background: white; border: 1px solid var(--bd); border-radius: 4px; cursor: pointer; font-size: 14px;">
                        Clear Signature
                    </button>
                </div>
                
                <div id="signatureError" style="color: #dc3545; font-size: 14px; margin-top: 8px; text-align: center;"></div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px;">
                <button onclick="goBackToAddressCollection()" style="flex: 1; padding: 12px 16px; background: white; border: 2px solid var(--fg); border-radius: 6px; cursor: pointer; font-size: 16px; color: var(--fg); transition: all 0.2s;">
                    ← Edit Details
                </button>
                <button id="submitBookingBtn" disabled style="flex: 2; padding: 12px 16px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: 600;">
                    Proceed to Payment
                </button>
            </div>
            
            <div id="submitMessage" style="margin-top: 12px; text-align: center; font-size: 14px; color: var(--muted);"></div>
        </div>
    `;

    // Initialize signature pad and validation
    initializeSignature();
}

// Initialize signature pad functionality
function initializeSignature() {
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

    // Canvas setup
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;

    // Drawing functions
    const startDrawing = (e) => {
        isDrawing = true;
        const rect = canvas.getBoundingClientRect();
        const x = (e.clientX || (e.touches && e.touches[0].clientX)) - rect.left;
        const y = (e.clientY || (e.touches && e.touches[0].clientY)) - rect.top;
        
        ctx.beginPath();
        ctx.moveTo(x, y);
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
        
        if (!hasSignature) {
            hasSignature = true;
            validateSubmission();
        }
    };

    const stopDrawing = () => {
        if (isDrawing) {
            isDrawing = false;
            ctx.beginPath();
        }
    };

    // Mouse events
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);

    // Touch events
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
        validateSubmission();
    };

    // Terms checkbox validation
    termsCheckbox.addEventListener('change', () => {
        validateSubmission();
    });

    // Validation function
    const validateSubmission = () => {
        const termsAccepted = termsCheckbox.checked;
        const signatureProvided = hasSignature;

        if (termsAccepted && signatureProvided) {
            submitBtn.disabled = false;
            submitBtn.style.background = 'var(--fg)';
            submitBtn.style.color = 'white';
            submitBtn.style.cursor = 'pointer';
            submitBtn.onclick = () => submitFinalBooking();
            submitMessage.textContent = 'Ready to proceed to payment';
            submitMessage.style.color = '#28a745';
            signatureError.textContent = '';
        } else {
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
                return dataURL;
            } catch (e) {
                console.error('Error getting signature data:', e);
                return null;
            }
        }
        return null;
    };

    // Initial validation
    validateSubmission();
}

// Submit final booking with signature
async function submitFinalBooking() {
    const submitBtn = document.getElementById('submitBookingBtn');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '<span class="spinner"></span>Preparing booking...';
    submitBtn.disabled = true;

    // Collect all booking data
    const artistType = window.currentBooking.artistType === 'anum' ? 'Anum' : 'Team';
    const quote = getCurrentQuote(artistType);
    
    const depositRate = (bookingData.service_type && bookingData.service_type.toLowerCase().includes('non-bridal')) ? 0.5 : 0.3;
    const depositAmount = quote.total * depositRate;

    // Capture signature data
    const signatureData = window.getSignatureData ? window.getSignatureData() : null;

    const finalBookingData = {
        booking_id: bookingData.unique_id,
        client_name: bookingData.name,
        client_email: bookingData.email,
        client_phone: bookingData.phone || '',
        artist_type: window.currentBooking.artistType,
        booking_type: 'service',
        event_date: bookingData.event_date || '',
        ready_time: window.formData.readyTime,
        street_address: window.formData.streetAddress,
        city: window.formData.city,
        province: window.formData.province,
        postal_code: window.formData.postalCode,
        service_type: bookingData.service_type,
        region: bookingData.region,
        quote_total: quote.total,
        deposit_amount: depositAmount,
        client_signature: signatureData,
        terms_accepted: true,
        signature_date: new Date().toISOString()
    };

    try {
        // Submit booking data first
        const formData = new FormData();
        formData.append('action', 'submit_booking');
        
        Object.keys(finalBookingData).forEach(key => {
            formData.append(key, finalBookingData[key] || '');
        });

        const bookingResponse = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const bookingResult = await bookingResponse.json();
        
        if (bookingResult.success) {
            // Create Stripe payment
            const paymentData = new FormData();
            paymentData.append('action', 'create_stripe_payment');
            paymentData.append('amount', depositAmount.toFixed(2));
            paymentData.append('currency', 'CAD');
            paymentData.append('booking_id', finalBookingData.booking_id);
            paymentData.append('client_email', finalBookingData.client_email);
            paymentData.append('client_name', finalBookingData.client_name);
            paymentData.append('description', `${window.currentBooking.artistName} - ${bookingData.service_type} Deposit`);
            
            submitBtn.innerHTML = '<span class="spinner"></span>Setting up payment...';
            
            const paymentResponse = await fetch(window.location.href, {
                method: 'POST',
                body: paymentData
            });
            
            const paymentResult = await paymentResponse.json();
            
            if (paymentResult.success) {
                submitBtn.innerHTML = '<span class="spinner"></span>Redirecting to payment...';
                
                setTimeout(() => {
                    window.location.href = paymentResult.payment_url;
                }, 1500);
            } else {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                alert('Payment setup failed: ' + paymentResult.message);
            }
        } else {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            alert('Error preparing booking: ' + bookingResult.message);
        }
        
    } catch (error) {
        console.error('Error during booking submission:', error);
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        alert('An error occurred. Please try again.');
    }
}

// Navigation functions
function goBackToQuotes() {
    location.reload(); // Simple way to go back to original state
}

function goBackToArtistSelection() {
    handleBookNow();
}

function goBackToTimeSelection() {
    if (window.currentBooking && window.currentBooking.bookingType) {
        // Go back to the booking form with time selection
        selectBookingType(window.currentBooking.artistType, window.currentBooking.bookingType);
    } else {
        // Fallback to booking options
        goBackToBookingOptions();
    }
}

function goBackToBookingOptions() {
    console.log('goBackToBookingOptions called');
    console.log('currentBooking:', window.currentBooking);
    
    // Check if this is a non-bridal service
    if (window.currentBooking && 
        (window.currentBooking.serviceType === 'Non-Bridal / Photoshoot' || 
         window.currentBooking.serviceType === 'Semi Bridal' ||
         window.currentBooking.bookingType === 'service')) {
        // For non-bridal services, go back to artist selection
        console.log('Non-bridal service detected, going back to artist selection');
        handleBookNow();
        return;
    }
    
    // For bridal services, go back to booking options
    if (window.currentBooking && window.currentBooking.artistType && window.currentBooking.artistName) {
        console.log('Bridal service - going back to booking options');
        showBookingOptions(window.currentBooking.artistType, window.currentBooking.artistName);
    } else {
        // If no artist selected, go back to artist selection
        handleBookNow();
    }
}

function goBackToAddressCollection() {
    showAddressCollection();
}
function validateEventDate() {
    const eventDateInput = document.getElementById('eventDate');
    const dateMessage = document.getElementById('dateMessage');
    
    if (!eventDateInput || !dateMessage) return;
    
    const selectedDate = eventDateInput.value;
    
    if (selectedDate) {
        const eventDate = new Date(selectedDate + 'T00:00:00');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const tomorrow = new Date(today);
        tomorrow.setDate(today.getDate() + 1);
        
        if (eventDate.getTime() === today.getTime() || eventDate.getTime() === tomorrow.getTime()) {
            dateMessage.innerHTML = `
                <div class="warning" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 14px; margin-top: 12px; border-radius: 6px; font-weight: 600; color: #856404;">
                    We are not available for bookings on today or tomorrow. Please call us at (416) 275-1719.
                </div>
            `;
        } else {
            dateMessage.innerHTML = '';
        }
    } else {
        dateMessage.innerHTML = '';
    }
}
// Initialize when document is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Document ready, JavaScript initialized');
        // Set up event date validation
    const eventDateInput = document.getElementById('eventDate');
    if (eventDateInput) {
        // Set minimum date to today
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        eventDateInput.min = `${yyyy}-${mm}-${dd}`;
        
        // Add event listener for date validation
        eventDateInput.addEventListener('change', validateEventDate);
        
        // Validate on page load if date is already set
        validateEventDate();
    }
});

</script>


</body>
</html>