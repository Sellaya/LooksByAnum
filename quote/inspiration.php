<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include PHPMailer (same as index.php)
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once __DIR__ . '/hubspot-integration.php';

// ---------------- ENV LOADER ---------------- //
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception('.env file not found.');
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;

        list($name, $value) = explode('=', $line, 2);
        $name  = trim($name);
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

// ---------------- CONFIG ---------------- //
$db = [
    'host'   => env('DB_HOST'),
    'name'   => env('DB_NAME'),
    'user'   => env('DB_USER'),
    'pass'   => env('DB_PASS'),
    'prefix' => env('DB_PREFIX', 'wp_')
];
$table = $db['prefix'] . 'bridal_bookings';

// ---------------- DB CONNECTION ---------------- //
try {
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ Database Connection Failed: " . $e->getMessage());
}

// ---------------- CREATE COLUMNS IF NOT EXISTS ---------------- //
try {
    // Check and create style column
    $checkStyle = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'style'");
    if ($checkStyle->rowCount() == 0) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN style TEXT");
    }
    
    // Check and create notes column
    $checkNotes = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'notes'");
    if ($checkNotes->rowCount() == 0) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN notes TEXT");
    }
    
    // Check and create inspiration_images column (plural - for JSON storage)
    $checkImages = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'inspiration_images'");
    if ($checkImages->rowCount() == 0) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN inspiration_images TEXT");
    }
    
    // Check and create inspiration_submitted_date column
    $checkDate = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'inspiration_submitted_date'");
    if ($checkDate->rowCount() == 0) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN inspiration_submitted_date DATETIME");
    }
    
    // Also check for old singular column and migrate if needed
    $checkOldImage = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'inspiration_image'");
    if ($checkOldImage->rowCount() > 0) {
        // Migrate old single image data to new format if inspiration_images is empty
        $migrateStmt = $pdo->prepare("UPDATE {$table} SET inspiration_images = CONCAT('[\"', inspiration_image, '\"]') WHERE inspiration_image IS NOT NULL AND inspiration_image != '' AND (inspiration_images IS NULL OR inspiration_images = '')");
        $migrateStmt->execute();
    }
} catch (Exception $e) {
    // Continue if columns already exist or there's an error
}

// ---------------- GET UNIQUE ID ---------------- //
if (empty($_GET['id'])) {
    die("❌ Missing booking ID.");
}
$uniqueId = $_GET['id'];

// Fetch booking row
$fetchQuery = "SELECT * FROM {$table} WHERE unique_id = ? LIMIT 1";
$fetchStmt = $pdo->prepare($fetchQuery);
$fetchStmt->execute([$uniqueId]);
$booking = $fetchStmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    die("❌ Booking not found for ID: " . htmlspecialchars($uniqueId));
}

// Parse existing images
$existingImages = [];
if (!empty($booking['inspiration_images'])) {
    $decoded = json_decode($booking['inspiration_images'], true);
    if (is_array($decoded)) {
        $existingImages = $decoded;
    } else {
        // Handle old single image format
        $existingImages = [$booking['inspiration_images']];
    }
}

// ---------------- HUBSPOT UPDATE FUNCTION ---------------- //
function updateHubSpotWithInspiration($bookingData, $inspirationData) {
    try {
        // Generate inspiration images URL for viewing
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['PHP_SELF']);
        $basePath = rtrim($scriptPath, '/');
        $inspirationUrl = $protocol . '://' . $domain . $basePath . '/inspiration-view.php?id=' . $bookingData['unique_id'];
        
        // Prepare HubSpot data with inspiration fields
        $hubspotData = [
            'booking_id' => $bookingData['unique_id'],
            'client_name' => ($bookingData['name'] ?? $bookingData['client_name'] ?? ''),
            'client_email' => ($bookingData['email'] ?? $bookingData['client_email'] ?? ''),
            'client_phone' => ($bookingData['phone'] ?? $bookingData['client_phone'] ?? ''),
            
            // Existing fields
            'artist_type' => $bookingData['artist_type'] ?? '',
            'service_type' => $bookingData['service_type'] ?? '',
            'region' => $bookingData['region'] ?? '',
            'event_date' => $bookingData['event_date'] ?? '',
            'ready_time' => $bookingData['ready_time'] ?? '',
            'appointment_time' => $bookingData['appointment_time'] ?? '',
            'trial_date' => $bookingData['trial_date'] ?? '',
            'street_address' => $bookingData['street_address'] ?? '',
            'city' => $bookingData['city'] ?? '',
            'province' => $bookingData['province'] ?? '',
            'postal_code' => $bookingData['postal_code'] ?? '',
            'quote_total' => $bookingData['quote_total'] ?? 0,
            'deposit_amount' => $bookingData['deposit_amount'] ?? 0,
            'status' => $bookingData['status'] ?? '',
            
            // NEW INSPIRATION FIELDS
            'makeup_style' => $inspirationData['style'] ?? '',
            'inspiration_notes' => $inspirationData['notes'] ?? '',
            'inspiration_images_count' => count($inspirationData['images'] ?? []),
            'inspiration_images_url' => $inspirationUrl,
            'inspiration_submitted_date' => date('Y-m-d'),
            'inspiration_status' => 'Submitted'
        ];
        
        // Log the data being sent
        error_log("HubSpot Inspiration Update - Booking ID: " . $bookingData['unique_id']);
        error_log("HubSpot Inspiration Data: " . json_encode($hubspotData));
        
        // Send to HubSpot using existing function
        $result = sendQuoteToHubSpot($hubspotData);
        
        if ($result) {
            error_log("HubSpot: Successfully updated inspiration for booking " . $bookingData['unique_id']);
            return true;
        } else {
            error_log("HubSpot: Failed to update inspiration for booking " . $bookingData['unique_id']);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("HubSpot Inspiration Update Error: " . $e->getMessage());
        return false;
    }
}

// ---------------- EMAIL FUNCTIONS (existing) ---------------- //
function generateInspirationEmailTemplate($bookingData, $inspirationData) {
    $clientName = $bookingData['name'] ?? $bookingData['client_name'] ?? 'Unknown Client';
    $bookingId = $bookingData['unique_id'] ?? 'Unknown';
    $email = $bookingData['email'] ?? $bookingData['client_email'] ?? 'Unknown';
    $phone = $bookingData['phone'] ?? $bookingData['client_phone'] ?? 'Unknown';
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspiration Submitted</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f8f9fa;">
    <div style="max-width: 600px; margin: 20px auto; background-color: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background-color: #000000; color: #ffffff; padding: 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: bold;">💄 INSPIRATION SUBMITTED</h1>
            <p style="margin: 10px 0 0 0; font-size: 16px;">Client has uploaded makeup inspiration</p>
        </div>
        
        <div style="padding: 30px;">
            <div style="margin-bottom: 25px;">
                <h2 style="margin: 0 0 15px 0; color: #000; font-size: 20px; border-bottom: 2px solid #000; padding-bottom: 5px;">CLIENT INFORMATION</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #eee; width: 30%; font-weight: bold;">Name:</th><td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . htmlspecialchars($clientName) . '</td></tr>
                    <tr><th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #eee; font-weight: bold;">Booking ID:</th><td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . htmlspecialchars($bookingId) . '</td></tr>
                    <tr><th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #eee; font-weight: bold;">Email:</th><td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . htmlspecialchars($email) . '</td></tr>
                    <tr><th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #eee; font-weight: bold;">Phone:</th><td style="padding: 8px 0; border-bottom: 1px solid #eee;">' . htmlspecialchars($phone) . '</td></tr>
                </table>
            </div>';

    if (!empty($inspirationData['style']) || !empty($inspirationData['notes'])) {
        $html .= '<div style="margin-bottom: 25px;">
                    <h3 style="margin: 0 0 15px 0; color: #000; font-size: 18px; border-bottom: 2px solid #000; padding-bottom: 5px;">INSPIRATION DETAILS</h3>';
        
        if (!empty($inspirationData['style'])) {
            $html .= '<p style="margin: 0 0 10px 0;"><strong>Style:</strong> ' . htmlspecialchars($inspirationData['style']) . '</p>';
        }
        
        if (!empty($inspirationData['notes'])) {
            $html .= '<p style="margin: 0;"><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($inspirationData['notes'])) . '</p>';
        }
        
        $html .= '</div>';
    }

    if (!empty($inspirationData['images']) && count($inspirationData['images']) > 0) {
        $html .= '<div style="margin-bottom: 25px;">
                    <h3 style="margin: 0 0 15px 0; color: #000; font-size: 18px; border-bottom: 2px solid #000; padding-bottom: 5px;">UPLOADED IMAGES (' . count($inspirationData['images']) . ')</h3>
                    <p style="margin: 0 0 15px 0; font-size: 14px; color: #666;">View inspiration images by visiting the booking admin panel or downloading the attached files.</p>
                    <ul style="margin: 0; padding-left: 20px;">';
        
        foreach ($inspirationData['images'] as $index => $image) {
            $imageName = basename($image);
            $html .= '<li style="margin: 5px 0;">' . htmlspecialchars($imageName) . '</li>';
        }
        
        $html .= '</ul></div>';
    }

    $html .= '<div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h3 style="margin: 0 0 10px 0; color: #000; font-size: 16px;">⚡ NEXT STEPS</h3>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><strong>Review the inspiration details</strong> and uploaded images</li>
                    <li><strong>Contact the client if clarification</strong> is needed</li>
                    <li><strong>Plan the makeup look</strong> based on their inspiration</li>
                    <li><strong>Prepare products and tools</strong> accordingly</li>
                </ul>
            </div>
            
            <div style="text-align: center; border-top: 2px solid #000; padding-top: 20px;">
                <p style="margin: 0; font-size: 12px; color: #999;">
                    This is an automated notification from Looks By Anum.<br>
                    Generated on ' . date('F j, Y \a\t g:i A T') . '
                </p>
            </div>
        </div>
    </div>
</body>
</html>';

    return $html;
}

function sendInspirationNotificationEmail($bookingData, $inspirationData) {
    $adminEmail = 'info@looksbyanum.com';
    $clientName = $bookingData['name'] ?? $bookingData['client_name'] ?? 'Unknown Client';
    $bookingId = $bookingData['unique_id'] ?? 'Unknown';
    
    // Check PHPMailer availability
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return false;
    }
    
    // Get SMTP credentials
    $smtpUsername = '';
    $smtpPassword = '';
    
    // Try env() function first
    if (function_exists('env')) {
        $smtpUsername = env('SMTP_USERNAME');
        $smtpPassword = env('SMTP_PASSWORD');
    }
    
    // Fallback: read .env file directly
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
        return false;
    }
    
    try {
        // Create PHPMailer instance
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configure SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // SSL options
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Set recipients
        $mail->setFrom($smtpUsername, 'Looks By Anum');
        $mail->addAddress($adminEmail, 'Looks By Anum Admin');
        $mail->addReplyTo('info@looksbyanum.com', 'Looks By Anum');
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = "💄 INSPIRATION SUBMITTED - {$clientName} - #{$bookingId}";
        $mail->Body = generateInspirationEmailTemplate($bookingData, $inspirationData);
        
        // Add image attachments
        if (!empty($inspirationData['images']) && is_array($inspirationData['images'])) {
            foreach ($inspirationData['images'] as $image) {
                $imagePath = __DIR__ . "/" . $image;
                if (file_exists($imagePath)) {
                    $mail->addAttachment($imagePath, basename($imagePath));
                }
            }
        }
        
        // Send the email
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

// ---------------- FORM SUBMISSION ---------------- //
$message = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $style = trim($_POST['style'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Handle image deletions
    if (!empty($_POST['delete_images']) && is_array($_POST['delete_images'])) {
        foreach ($_POST['delete_images'] as $deleteImage) {
            $key = array_search($deleteImage, $existingImages);
            if ($key !== false) {
                // Delete file from server
                $filePath = __DIR__ . "/" . $deleteImage;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                // Remove from array
                unset($existingImages[$key]);
            }
        }
        $existingImages = array_values($existingImages); // Reindex array
    }

    // Handle new image uploads
    if (!empty($_FILES['inspiration_images']['name'][0])) {
        $targetDir = __DIR__ . "/uploads/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        foreach ($_FILES['inspiration_images']['name'] as $key => $fileName) {
            if (!empty($fileName)) {
                $uniqueFileName = time() . "_" . $key . "_" . basename($fileName);
                $targetFile = $targetDir . $uniqueFileName;

                if (move_uploaded_file($_FILES['inspiration_images']['tmp_name'][$key], $targetFile)) {
                    $existingImages[] = "uploads/" . $uniqueFileName;
                }
            }
        }
    }

    try {
        // Convert images array to JSON
        $imagesJson = json_encode($existingImages);
        
        // Update the booking with new inspiration data including submission date
        $updateQuery = "UPDATE {$table} SET style = ?, notes = ?, inspiration_images = ?, inspiration_submitted_date = NOW() WHERE unique_id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateResult = $updateStmt->execute([$style, $notes, $imagesJson, $uniqueId]);
        
        if ($updateResult) {
            $message = "<p style='color:green;'>✅ Inspiration updated successfully!</p>";
            
            // Prepare inspiration data
            $inspirationData = [
                'style' => $style,
                'notes' => $notes,
                'images' => $existingImages
            ];
            
            // Send email notification
            $emailSent = sendInspirationNotificationEmail($booking, $inspirationData);
            
            // 🔥 NEW: Update HubSpot with inspiration data
            $hubspotUpdated = updateHubSpotWithInspiration($booking, $inspirationData);
            if ($hubspotUpdated) {
                $message .= "<p style='color:green; font-size: 14px;'>📊 HubSpot updated successfully</p>";
            } else {
                $message .= "<p style='color:orange; font-size: 14px;'>⚠️ HubSpot update failed (data saved locally)</p>";
            }
            
            // Refresh booking data
            $refreshStmt = $pdo->prepare($fetchQuery);
            $refreshStmt->execute([$uniqueId]);
            $booking = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            
            // Update existing images array
            if (!empty($booking['inspiration_images'])) {
                $decoded = json_decode($booking['inspiration_images'], true);
                if (is_array($decoded)) {
                    $existingImages = $decoded;
                }
            }
        } else {
            $message = "<p style='color:red;'>Failed to update inspiration.</p>";
        }
    } catch (Exception $e) {
        $message = "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Makeup Inspiration</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #fafafa;
            margin: 0;
            padding: 40px 20px;
            color: #333;
        }
        tbody {
            word-break: break-word;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h2 {
            text-align: center;
            margin-bottom: 25px;
            font-size: 28px;
            font-weight: 600;
        }
        .card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 20px;
        }
        .card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        label {
            font-weight: 500;
            margin-top: 10px;
            display: block;
        }
        input, textarea, select {
            width: 100%;
            padding: 10px 12px;
            margin-top: 5px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            box-sizing: border-box;
        }
        textarea { resize: vertical; }
        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 20px;
            background: #000;
            color: #fff;
            font-weight: 600;
            text-align: center;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.2s ease;
        }
        .btn:hover { background: #333; }
        .message {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo {
            display: flex;
            justify-content: center;
        }
        .logo img {
            width: 250px;
        }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .image-item {
            position: relative;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .image-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        .delete-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .image-item:hover .delete-overlay {
            opacity: 1;
        }
        .delete-checkbox {
            width: auto !important;
            margin: 0 5px 0 0 !important;
        }
        .delete-label {
            color: white;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        .no-images {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
        }
        .hubspot-status {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="logo"><img src="https://looksbyanum.com/wp-content/uploads/2025/05/Untitled-design-11.png"></div>
    <h2>Your Makeup Inspiration</h2>

    <?php if (!empty($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Your Inspiration</h3>
        
        <?php if (!empty($existingImages)): ?>
            <h4>Current Images</h4>
            <div class="image-gallery">
                <?php foreach ($existingImages as $image): ?>
                    <div class="image-item">
                        <img src="<?= htmlspecialchars($image) ?>" alt="Inspiration Image">
                        <div class="delete-overlay">
                            <label class="delete-label">
                                <input type="checkbox" name="delete_images[]" value="<?= htmlspecialchars($image) ?>" class="delete-checkbox" form="inspiration-form">
                                Delete
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p style="font-size: 14px; color: #666; margin-top: 10px;">
                <em>Hover over images and check "Delete" to remove them</em>
            </p>
        <?php else: ?>
            <div class="no-images">No inspiration images uploaded yet</div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" id="inspiration-form">
            <label for="style">Makeup Style Inspiration</label>
            <input type="text" name="style" value="<?= htmlspecialchars($booking['style'] ?? '') ?>" placeholder="e.g. Glam, Natural, Bridal">
    
            <label for="notes">Additional Notes</label>
            <textarea name="notes" rows="4"><?= htmlspecialchars($booking['notes'] ?? '') ?></textarea>
    
            <label for="inspiration_images">Upload New Inspiration Images</label>
            <input type="file" name="inspiration_images[]" accept="image/*" multiple>
            <p style="font-size: 14px; color: #666; margin-top: 5px;">
                <em>You can select multiple images at once (Ctrl/Cmd + click)</em>
            </p>
    
            <button type="submit" class="btn">Save Inspiration</button>
            
            <div class="hubspot-status">
                📊 <strong>HubSpot Integration:</strong> Style, notes, and images URL will be automatically synced to our CRM when you save.
            </div>
        </form>
    </div>

<div class="card">
    <h3>Booking Details</h3>
    <table style="width:100%; border-collapse: collapse; margin-top: 10px; font-family: Arial, sans-serif;">
        <tbody>
            <?php 
            $displayFields = [
                'Booking ID' => 'unique_id',
                'Name' => 'name',
                'Email' => 'email',
                'Phone' => 'phone'
            ];

            $fieldBuffer = [];
            foreach($displayFields as $label => $field):
                if(isset($booking[$field]) && $booking[$field] !== '' && $booking[$field] !== null):
                    $value = htmlspecialchars($booking[$field]);
                    $fieldBuffer[] = ['label' => $label, 'value' => $value];

                    // Output a row every 2 fields (4 columns)
                    if(count($fieldBuffer) === 2){
                        echo '<tr style="border-bottom:1px solid #ccc; background-color:#f9f9f9;">';
                        foreach($fieldBuffer as $item){
                            echo '<td style="padding: 10px; font-weight:600; width:20%; border:1px solid #ccc;">'.$item['label'].'</td>';
                            echo '<td style="padding: 10px; width:30%; border:1px solid #ccc;">'.$item['value'].'</td>';
                        }
                        echo '</tr>';
                        $fieldBuffer = [];
                    }
                endif;
            endforeach;

            // If leftover single field, fill remaining columns
            if(count($fieldBuffer) === 1){
                echo '<tr style="border-bottom:1px solid #ccc; background-color:#f9f9f9;">';
                echo '<td style="padding: 10px; font-weight:600; width:20%; border:1px solid #ccc;">'.$fieldBuffer[0]['label'].'</td>';
                echo '<td style="padding: 10px; width:30%; border:1px solid #ccc;">'.$fieldBuffer[0]['value'].'</td>';
                // empty cells for alignment
                echo '<td style="padding: 10px; font-weight:600; width:20%; border:1px solid #ccc;"></td>';
                echo '<td style="padding: 10px; width:30%; border:1px solid #ccc;"></td>';
                echo '</tr>';
            }
            ?>
        </tbody>
    </table>
</div>

</div>
</body>
</html>