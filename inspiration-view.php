<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$clientName = $booking['name'] ?? $booking['client_name'] ?? 'Unknown Client';
$bookingId = $booking['unique_id'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspiration View - <?= htmlspecialchars($clientName) ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #fafafa;
            margin: 0;
            padding: 40px 20px;
            color: #333;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 32px;
            font-weight: 600;
            color: #000;
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
            color: #000;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        .info-label {
            font-weight: 600;
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 16px;
            color: #000;
        }
        .style-notes {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .style-notes h4 {
            margin: 0 0 10px 0;
            color: #2e7d32;
        }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .image-item {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .image-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
        }
        .image-caption {
            padding: 10px;
            font-size: 14px;
            color: #666;
            text-align: center;
        }
        .no-images {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .logo {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .logo img {
            width: 200px;
        }
        .admin-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .download-btn {
            display: inline-block;
            background: #000;
            color: #fff;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            margin-top: 8px;
        }
        .download-btn:hover {
            background: #333;
        }
        .metadata {
            font-size: 12px;
            color: #999;
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="logo">
        <img src="https://looksbyanum.com/wp-content/uploads/2025/05/Untitled-design-11.png" alt="Looks By Anum">
    </div>
    
    <h1>💄 Makeup Inspiration</h1>
    
    <div class="admin-note">
        <strong>📋 Admin View:</strong> This page shows the client's makeup inspiration details and uploaded images.
    </div>

    <div class="card">
        <h3>Client Information</h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Client Name</div>
                <div class="info-value"><?= htmlspecialchars($clientName) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Booking ID</div>
                <div class="info-value"><?= htmlspecialchars($bookingId) ?></div>
            </div>
            <?php if (!empty($booking['email']) || !empty($booking['client_email'])): ?>
            <div class="info-item">
                <div class="info-label">Email</div>
                <div class="info-value"><?= htmlspecialchars($booking['email'] ?? $booking['client_email']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($booking['phone']) || !empty($booking['client_phone'])): ?>
            <div class="info-item">
                <div class="info-label">Phone</div>
                <div class="info-value"><?= htmlspecialchars($booking['phone'] ?? $booking['client_phone']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($booking['style']) || !empty($booking['notes'])): ?>
    <div class="card">
        <h3>Inspiration Details</h3>
        
        <?php if (!empty($booking['style'])): ?>
        <div class="style-notes">
            <h4>💄 Makeup Style</h4>
            <p><?= htmlspecialchars($booking['style']) ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($booking['notes'])): ?>
        <div class="style-notes">
            <h4>📝 Additional Notes</h4>
            <p><?= nl2br(htmlspecialchars($booking['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Inspiration Images</h3>
        
        <?php if (!empty($existingImages)): ?>
            <p style="color: #666; margin-bottom: 20px;">
                <strong><?= count($existingImages) ?></strong> image<?= count($existingImages) !== 1 ? 's' : '' ?> uploaded
            </p>
            
            <div class="image-gallery">
                <?php foreach ($existingImages as $index => $image): ?>
                    <div class="image-item">
                        <img src="<?= htmlspecialchars($image) ?>" alt="Inspiration Image <?= $index + 1 ?>" loading="lazy">
                        <div class="image-caption">
                            Image <?= $index + 1 ?>
                            <br>
                            <a href="<?= htmlspecialchars($image) ?>" class="download-btn" target="_blank" download>
                                Download
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-images">
                No inspiration images have been uploaded yet.
            </div>
        <?php endif; ?>
    </div>

    <div class="metadata">
        Generated on <?= date('F j, Y \a\t g:i A T') ?>
        <br>
        Looks By Anum - Professional Makeup Services
    </div>
</div>
</body>
</html>