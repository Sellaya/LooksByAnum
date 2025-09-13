<?php
/**
 * Stripe Coupon Debug Tool
 * 
 * Usage: 
 * 1. Upload this file to your quote directory
 * 2. Visit: https://quote.looksbyanum.com/stripe_debug.php?session_id=cs_test_xxxxx
 * 3. Or visit without parameters to see instructions
 */

// Load configuration manually without loading full index.php logic
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

// Helper function to get environment variable with fallback
function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

// Load environment variables
try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    die('Configuration Error: ' . $e->getMessage());
}

// Database configuration
$db = [
    'host'   => env('DB_HOST'),
    'name'   => env('DB_NAME'),
    'user'   => env('DB_USER'),
    'pass'   => env('DB_PASS'),
    'prefix' => env('DB_PREFIX', 'wp_')
];

$table = $db['prefix'] . 'bridal_bookings';

// Stripe configuration
$stripeConfig = [
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY')
];

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get session ID from URL
$sessionId = $_GET['session_id'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stripe Coupon Debug Tool</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; border-color: #c3e6cb; }
        .error { background: #f8d7da; border-color: #f5c6cb; }
        .info { background: #d1ecf1; border-color: #bee5eb; }
        .warning { background: #fff3cd; border-color: #ffeaa7; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }
        .field { margin: 5px 0; padding: 5px; background: #f8f9fa; }
        .field strong { color: #007bff; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
        .highlight { background: yellow; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Stripe Coupon Debug Tool</h1>
        
        <?php if (empty($sessionId)): ?>
            
            <div class="section info">
                <h2>Instructions</h2>
                <p><strong>How to use this debug tool:</strong></p>
                <ol>
                    <li>Complete a test booking with a promotion code in Stripe Checkout</li>
                    <li>After successful payment, copy the session ID from the URL</li>
                    <li>Visit this page with the session ID parameter:</li>
                </ol>
                <p><code>https://quote.looksbyanum.com/stripe_debug.php?session_id=cs_test_xxxxx</code></p>
                
                <form method="GET" style="margin-top: 20px;">
                    <label for="session_id">Enter Stripe Session ID:</label><br>
                    <input type="text" name="session_id" id="session_id" 
                           placeholder="cs_test_..." style="width: 400px; padding: 8px; margin: 10px 0;">
                    <br>
                    <button type="submit" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px;">
                        Debug Session
                    </button>
                </form>
            </div>
            
        <?php else: ?>
            
            <?php
            // Start debugging
            echo '<div class="section info">';
            echo '<h2>Debug Results for Session: ' . htmlspecialchars($sessionId) . '</h2>';
            echo '<p>Starting analysis at ' . date('Y-m-d H:i:s') . '</p>';
            echo '</div>';
            
            try {
                // Test database connection
                echo '<div class="section">';
                echo '<h2>1. Database Connection Test</h2>';
                
                try {
                    $pdo = new PDO(
                        'mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8mb4',
                        $db['user'], $db['pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    echo '<p class="success">✅ Database connection successful</p>';
                } catch (Exception $e) {
                    echo '<p class="error">❌ Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
                    throw $e;
                }
                echo '</div>';
                
                // Test Stripe configuration
                echo '<div class="section">';
                echo '<h2>2. Stripe Configuration Test</h2>';
                
                if (empty($stripeConfig['secret_key'])) {
                    echo '<p class="error">❌ Stripe secret key not configured</p>';
                    throw new Exception('Stripe not configured');
                }
                
                $keyType = (strpos($stripeConfig['secret_key'], 'sk_test_') === 0) ? 'TEST' : 'LIVE';
                echo '<p class="success">✅ Stripe secret key configured (' . $keyType . ' mode)</p>';
                echo '<p>Key prefix: ' . substr($stripeConfig['secret_key'], 0, 12) . '...</p>';
                echo '</div>';
                
                // Retrieve Stripe session
                echo '<div class="section">';
                echo '<h2>3. Stripe Session Retrieval</h2>';
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . $sessionId . '?expand[]=total_details.breakdown&expand[]=payment_intent');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $stripeConfig['secret_key']
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    echo '<p class="error">❌ Curl error: ' . htmlspecialchars($curlError) . '</p>';
                    throw new Exception('Curl error: ' . $curlError);
                }
                
                if ($httpCode !== 200) {
                    echo '<p class="error">❌ Stripe API error: HTTP ' . $httpCode . '</p>';
                    echo '<pre>' . htmlspecialchars($response) . '</pre>';
                    throw new Exception('Stripe API error: ' . $httpCode);
                }
                
                echo '<p class="success">✅ Stripe session retrieved successfully</p>';
                echo '<p>HTTP Status: ' . $httpCode . '</p>';
                
                $stripeSession = json_decode($response, true);
                
                if (!$stripeSession) {
                    echo '<p class="error">❌ Failed to decode JSON response</p>';
                    throw new Exception('Invalid JSON response');
                }
                
                echo '</div>';
                
                // Analyze session data
                echo '<div class="section">';
                echo '<h2>4. Session Data Analysis</h2>';
                
                // Basic amounts
                $amountTotal = ($stripeSession['amount_total'] ?? 0) / 100;
                $amountSubtotal = ($stripeSession['amount_subtotal'] ?? 0) / 100;
                $currency = strtoupper($stripeSession['currency'] ?? 'USD');
                
                echo '<div class="field"><strong>Amount Total:</strong> $' . number_format($amountTotal, 2) . ' ' . $currency . '</div>';
                echo '<div class="field"><strong>Amount Subtotal:</strong> $' . number_format($amountSubtotal, 2) . ' ' . $currency . '</div>';
                echo '<div class="field"><strong>Payment Status:</strong> ' . ($stripeSession['payment_status'] ?? 'unknown') . '</div>';
                
                // Check for discounts
                $hasDiscounts = false;
                $discountAmount = 0;
                $promoCode = '';
                
                if (isset($stripeSession['total_details']['breakdown']['discounts'])) {
                    $discounts = $stripeSession['total_details']['breakdown']['discounts'];
                    
                    if (is_array($discounts) && count($discounts) > 0) {
                        $hasDiscounts = true;
                        echo '<p class="success">✅ Discounts found in session data!</p>';
                        echo '<p>Number of discounts: ' . count($discounts) . '</p>';
                        
                        foreach ($discounts as $index => $discount) {
                            echo '<h3>Discount #' . ($index + 1) . ':</h3>';
                            echo '<pre>' . json_encode($discount, JSON_PRETTY_PRINT) . '</pre>';
                            
                            // Extract data from first discount
                            if ($index === 0) {
                                if (isset($discount['amount'])) {
                                    $discountAmount = $discount['amount'] / 100;
                                }
                                
                                // Try multiple paths for promo code
                                $promoPaths = [
                                    ['promotion_code'],
                                    ['discount', 'promotion_code'],
                                    ['coupon', 'name'],
                                    ['discount', 'coupon', 'name']
                                ];
                                
                                foreach ($promoPaths as $path) {
                                    $current = $discount;
                                    $pathStr = implode('.', $path);
                                    
                                    foreach ($path as $key) {
                                        if (isset($current[$key])) {
                                            $current = $current[$key];
                                        } else {
                                            $current = null;
                                            break;
                                        }
                                    }
                                    
                                    if ($current && is_string($current)) {
                                        $promoCode = $current;
                                        echo '<p class="highlight">Found promo code at path "' . $pathStr . '": ' . $promoCode . '</p>';
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                
                if (!$hasDiscounts) {
                    echo '<p class="warning">⚠️ No discounts found in session data</p>';
                    
                    // Check if amounts suggest a discount was applied
                    if ($amountSubtotal > $amountTotal) {
                        $calculatedDiscount = $amountSubtotal - $amountTotal;
                        echo '<p class="info">💡 Amount difference suggests discount: $' . number_format($calculatedDiscount, 2) . '</p>';
                        $discountAmount = $calculatedDiscount;
                    }
                }
                
                echo '</div>';
                
                // Show extraction results
                echo '<div class="section">';
                echo '<h2>5. Extraction Results</h2>';
                echo '<div class="field"><strong>Discount Amount:</strong> $' . number_format($discountAmount, 2) . '</div>';
                echo '<div class="field"><strong>Promo Code:</strong> ' . ($promoCode ?: 'None found') . '</div>';
                echo '<div class="field"><strong>Has Coupon Data:</strong> ' . ($discountAmount > 0 || !empty($promoCode) ? 'YES' : 'NO') . '</div>';
                echo '</div>';
                
                // Database simulation
                echo '<div class="section">';
                echo '<h2>6. Database Update Simulation</h2>';
                
                if ($discountAmount > 0 || !empty($promoCode)) {
                    echo '<p class="success">✅ Would save coupon data to database:</p>';
                    echo '<ul>';
                    echo '<li><strong>discount_amount:</strong> ' . $discountAmount . '</li>';
                    echo '<li><strong>promo_code:</strong> "' . $promoCode . '"</li>';
                    echo '<li><strong>amount_paid:</strong> ' . $amountTotal . '</li>';
                    echo '</ul>';
                    
                    // Show SQL that would be executed
                    echo '<h3>SQL that would be executed:</h3>';
                    echo '<pre>UPDATE {table} SET 
    status = "paid",
    stripe_session_id = "' . $sessionId . '",
    amount_paid = ' . $amountTotal . ',
    discount_amount = ' . $discountAmount . ',
    promo_code = "' . $promoCode . '"
WHERE unique_id = "{booking_id}"</pre>';
                } else {
                    echo '<p class="warning">⚠️ No coupon data to save</p>';
                }
                
                echo '</div>';
                
                // Full session dump
                echo '<div class="section">';
                echo '<h2>7. Complete Stripe Session Data</h2>';
                echo '<details>';
                echo '<summary>Click to expand full session data</summary>';
                echo '<pre>' . json_encode($stripeSession, JSON_PRETTY_PRINT) . '</pre>';
                echo '</details>';
                echo '</div>';
                
            } catch (Exception $e) {
                echo '<div class="section error">';
                echo '<h2>Error</h2>';
                echo '<p>❌ Debug failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
            ?>
            
        <?php endif; ?>
        
        <div class="section">
            <h2>Next Steps</h2>
            <ul>
                <li>If no discounts were found, check that promotion codes are properly configured in Stripe</li>
                <li>Verify that <code>allow_promotion_codes</code> is enabled in your Checkout Session creation</li>
                <li>Make sure the promotion code was actually applied during checkout</li>
                <li>Check Stripe Dashboard to confirm the promotion code exists and is active</li>
            </ul>
        </div>
        
    </div>
</body>
</html>