<?php
/**
 * Stripe Session Database Updater
 * 
 * This script updates database records using Stripe session IDs
 * 
 * Usage: 
 * https://quote.looksbyanum.com/update_from_stripe.php?session_id=cs_test_xxxxx&booking_id=BB2025xxxxx
 * 
 * Or use the form interface
 */

// Load configuration
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception('.env file not found');
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        
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

function env($key, $default = null) {
    $value = getenv($key);
    return ($value === false) ? $default : $value;
}

// Load environment variables
try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    die('Configuration Error: ' . $e->getMessage());
}

// Database configuration
$db = [
    'host' => env('DB_HOST'),
    'name' => env('DB_NAME'),
    'user' => env('DB_USER'),
    'pass' => env('DB_PASS'),
    'prefix' => env('DB_PREFIX', 'wp_')
];

$table = $db['prefix'] . 'bridal_bookings';

// Stripe configuration
$stripeConfig = [
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY')
];

// Get parameters
$sessionId = $_GET['session_id'] ?? $_POST['session_id'] ?? '';
$bookingId = $_GET['booking_id'] ?? $_POST['booking_id'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stripe Session Database Updater</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        .warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
        input, select { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .field-group { margin: 15px 0; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        .result-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Stripe Session Database Updater</h1>
        
        <?php if (empty($sessionId) || empty($bookingId) || $action !== 'update'): ?>
            
            <div class="section info">
                <h2>Update Database from Stripe Session</h2>
                <p>This tool retrieves coupon data from a Stripe session and updates your database record.</p>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update">
                
                <div class="field-group">
                    <label for="session_id">Stripe Session ID:</label>
                    <input type="text" name="session_id" id="session_id" 
                           value="<?= htmlspecialchars($sessionId) ?>"
                           placeholder="cs_test_..." required>
                </div>
                
                <div class="field-group">
                    <label for="booking_id">Booking ID:</label>
                    <input type="text" name="booking_id" id="booking_id" 
                           value="<?= htmlspecialchars($bookingId) ?>"
                           placeholder="BB2025..." required>
                </div>
                
                <button type="submit">Update Database from Stripe Session</button>
            </form>
            
            <div class="section warning">
                <h3>Quick Test Links</h3>
                <p>Use these if you have the session ID from the debug tool:</p>
                <ul>
                    <li><strong>Session ID:</strong> cs_test_b1nxC1MQT3F5YzlU1nWq4w9gtcPvuGaMGDFxRSAkYa9cLXZ6uoEZxHdWs9</li>
                    <li><strong>Booking ID:</strong> BB2025f6421c (from the cancel URL in Stripe data)</li>
                </ul>
                <a href="?action=update&session_id=cs_test_b1nxC1MQT3F5YzlU1nWq4w9gtcPvuGaMGDFxRSAkYa9cLXZ6uoEZxHdWs9&booking_id=BB2025f6421c" 
                   style="background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;">
                   Quick Test Update
                </a>
            </div>
            
        <?php else: ?>
            
            <?php
            // Perform the update
            echo '<div class="section info">';
            echo '<h2>Processing Update</h2>';
            echo '<p>Session ID: ' . htmlspecialchars($sessionId) . '</p>';
            echo '<p>Booking ID: ' . htmlspecialchars($bookingId) . '</p>';
            echo '<p>Started at: ' . date('Y-m-d H:i:s') . '</p>';
            echo '</div>';
            
            try {
                // Step 1: Connect to database
                echo '<div class="section">';
                echo '<h3>1. Database Connection</h3>';
                
                $pdo = new PDO(
                    'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
                    $db['user'], $db['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                echo '<p class="success">✅ Database connected successfully</p>';
                echo '</div>';
                
                // Step 2: Verify booking exists
                echo '<div class="section">';
                echo '<h3>2. Verify Booking Exists</h3>';
                
                $checkStmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ?");
                $checkStmt->execute([$bookingId]);
                $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existingRecord) {
                    throw new Exception("Booking not found: " . $bookingId);
                }
                
                echo '<p class="success">✅ Booking found in database</p>';
                echo '<div class="result-box">';
                echo '<strong>Current Record:</strong><br>';
                echo 'Name: ' . htmlspecialchars($existingRecord['name'] ?? '') . '<br>';
                echo 'Email: ' . htmlspecialchars($existingRecord['email'] ?? '') . '<br>';
                echo 'Status: ' . htmlspecialchars($existingRecord['status'] ?? '') . '<br>';
                echo 'Current Total: $' . number_format(floatval($existingRecord['price'] ?? 0), 2) . '<br>';
                echo 'Current Discount: $' . number_format(floatval($existingRecord['discount_amount'] ?? 0), 2) . '<br>';
                echo 'Current Promo Code: ' . htmlspecialchars($existingRecord['promo_code'] ?? 'None') . '<br>';
                echo '</div>';
                echo '</div>';
                
                // Step 3: Get Stripe session data
                echo '<div class="section">';
                echo '<h3>3. Retrieve Stripe Session Data</h3>';
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . $sessionId . '?expand[]=total_details.breakdown');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $stripeConfig['secret_key']
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    throw new Exception('Stripe API error: HTTP ' . $httpCode);
                }
                
                $stripeSession = json_decode($response, true);
                if (!$stripeSession) {
                    throw new Exception('Invalid Stripe response');
                }
                
                echo '<p class="success">✅ Stripe session retrieved successfully</p>';
                echo '</div>';
                
                // Step 4: Extract coupon data
                echo '<div class="section">';
                echo '<h3>4. Extract Coupon Data</h3>';
                
                $amountTotal = $stripeSession['amount_total'] / 100;
                $amountSubtotal = $stripeSession['amount_subtotal'] / 100;
                $discountAmount = 0;
                $promoCode = '';
                
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
                    
                    echo '<p class="success">✅ Coupon data found in Stripe session</p>';
                } else {
                    echo '<p class="warning">⚠️ No coupon data found in Stripe session</p>';
                }
                
                echo '<div class="result-box">';
                echo '<strong>Extracted from Stripe:</strong><br>';
                echo 'Amount Total: $' . number_format($amountTotal, 2) . '<br>';
                echo 'Amount Subtotal: $' . number_format($amountSubtotal, 2) . '<br>';
                echo 'Discount Amount: $' . number_format($discountAmount, 2) . '<br>';
                echo 'Promo Code: ' . ($promoCode ?: 'None') . '<br>';
                echo '</div>';
                echo '</div>';
                
                // Step 5: Calculate new amounts
                echo '<div class="section">';
                echo '<h3>5. Calculate Updated Amounts</h3>';
                
                $originalTotal = floatval($existingRecord['price'] ?? 0);
                $newTotal = $originalTotal;
                
                if ($discountAmount > 0) {
                    $newTotal = $originalTotal - $discountAmount;
                }
                
                echo '<div class="result-box">';
                echo '<strong>Amount Calculations:</strong><br>';
                echo 'Original Database Total: $' . number_format($originalTotal, 2) . '<br>';
                echo 'Discount to Apply: $' . number_format($discountAmount, 2) . '<br>';
                echo 'New Total: $' . number_format($newTotal, 2) . '<br>';
                echo 'Amount Paid (from Stripe): $' . number_format($amountTotal, 2) . '<br>';
                echo '</div>';
                echo '</div>';
                
                // Step 6: Update database
                echo '<div class="section">';
                echo '<h3>6. Update Database</h3>';
                
                $updateSQL = "UPDATE {$table} SET 
                    quote_total = ?,
                    amount_paid = ?,
                    discount_amount = ?,
                    promo_code = ?,
                    status = 'paid',
                    stripe_session_id = ?
                    WHERE unique_id = ?";
                
                echo '<div class="result-box">';
                echo '<strong>SQL to execute:</strong><br>';
                echo '<pre>' . $updateSQL . '</pre>';
                echo '<strong>Values:</strong><br>';
                echo '1. quote_total = ' . $newTotal . '<br>';
                echo '2. amount_paid = ' . $amountTotal . '<br>';
                echo '3. discount_amount = ' . $discountAmount . '<br>';
                echo '4. promo_code = "' . $promoCode . '"<br>';
                echo '5. stripe_session_id = "' . $sessionId . '"<br>';
                echo '6. unique_id = "' . $bookingId . '"<br>';
                echo '</div>';
                
                $updateStmt = $pdo->prepare($updateSQL);
                $updateResult = $updateStmt->execute([
                    $newTotal,
                    $amountTotal,
                    $discountAmount,
                    $promoCode ?: null,
                    $sessionId,
                    $bookingId
                ]);
                
                if ($updateResult && $updateStmt->rowCount() > 0) {
                    echo '<p class="success">✅ Database updated successfully!</p>';
                    echo '<p class="success">Rows affected: ' . $updateStmt->rowCount() . '</p>';
                    
                    // Verify the update
                    $verifyStmt = $pdo->prepare("SELECT * FROM {$table} WHERE unique_id = ?");
                    $verifyStmt->execute([$bookingId]);
                    $updatedRecord = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo '<div class="result-box">';
                    echo '<strong>Updated Record:</strong><br>';
                    echo 'Status: ' . htmlspecialchars($updatedRecord['status'] ?? '') . '<br>';
                    echo 'Quote Total: $' . number_format(floatval($updatedRecord['quote_total'] ?? $updatedRecord['price'] ?? 0), 2) . '<br>';
                    echo 'Amount Paid: $' . number_format(floatval($updatedRecord['amount_paid'] ?? 0), 2) . '<br>';
                    echo 'Discount Amount: $' . number_format(floatval($updatedRecord['discount_amount'] ?? 0), 2) . '<br>';
                    echo 'Promo Code: ' . htmlspecialchars($updatedRecord['promo_code'] ?? 'None') . '<br>';
                    echo 'Stripe Session ID: ' . htmlspecialchars($updatedRecord['stripe_session_id'] ?? 'None') . '<br>';
                    echo '</div>';
                    
                } else {
                    echo '<p class="error">❌ Database update failed</p>';
                    echo '<p class="error">Rows affected: ' . $updateStmt->rowCount() . '</p>';
                }
                
                echo '</div>';
                
                // Step 7: Test the booking page
                echo '<div class="section info">';
                echo '<h3>7. Test Results</h3>';
                echo '<p>You can now test the updated booking:</p>';
                echo '<a href="index.php?id=' . urlencode($bookingId) . '" target="_blank" style="background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;">View Updated Booking</a>';
                echo '</div>';
                
            } catch (Exception $e) {
                echo '<div class="section error">';
                echo '<h3>Error</h3>';
                echo '<p>❌ Update failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
            ?>
            
        <?php endif; ?>
        
        <div class="section">
            <p><a href="?">← Back to Form</a></p>
        </div>
        
    </div>
</body>
</html>