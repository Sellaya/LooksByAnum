<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$bookingId = $_GET['booking_id'] ?? 'BB2025de2d71';

echo "<h2>Database Update Test</h2>";
echo "Testing with Booking ID: " . htmlspecialchars($bookingId) . "<br><br>";

try {
    // Connect to database
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=u194250183_WAsbP;charset=utf8mb4',
        'u194250183_80NGy',
        '6OuLxGYTII',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "✅ Database connected<br>";
    
    // First, let's see what bookings exist
    echo "<h3>Available Bookings:</h3>";
    $allStmt = $pdo->query("SELECT unique_id, quote_total, discount_amount, promo_code, status FROM wp_bridal_bookings ORDER BY created_at DESC LIMIT 10");
    $bookings = $allStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Booking ID</th><th>Total</th><th>Discount</th><th>Promo Code</th><th>Status</th></tr>";
    foreach ($bookings as $booking) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($booking['unique_id']) . "</td>";
        echo "<td>$" . number_format($booking['quote_total'], 2) . "</td>";
        echo "<td>" . ($booking['discount_amount'] ?? 'NULL') . "</td>";
        echo "<td>" . ($booking['promo_code'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($booking['status']) . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    // Now test updating a specific booking
    echo "<h3>Testing Update on: " . $bookingId . "</h3>";
    
    // Check if this specific booking exists
    $stmt = $pdo->prepare("SELECT * FROM wp_bridal_bookings WHERE unique_id = ?");
    $stmt->execute([$bookingId]);
    $targetBooking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($targetBooking) {
        echo "✅ Target booking found<br>";
        echo "Current values:<br>";
        echo "- quote_total: " . $targetBooking['quote_total'] . "<br>";
        echo "- discount_amount: " . ($targetBooking['discount_amount'] ?? 'NULL') . "<br>";
        echo "- promo_code: " . ($targetBooking['promo_code'] ?? 'NULL') . "<br>";
        echo "- amount_paid: " . ($targetBooking['amount_paid'] ?? 'NULL') . "<br>";
        
        // Test the update
        echo "<br>🧪 Testing update...<br>";
        
        $updateSQL = "UPDATE wp_bridal_bookings SET 
            discount_amount = ?, 
            promo_code = ?,
            amount_paid = ?
            WHERE unique_id = ?";
        
        $updateStmt = $pdo->prepare($updateSQL);
        $testDiscount = 23.14;
        $testPromo = 'TESTDATA';
        $testPaid = 154.83;
        
        $success = $updateStmt->execute([$testDiscount, $testPromo, $testPaid, $bookingId]);
        
        if ($success) {
            $rowsAffected = $updateStmt->rowCount();
            echo "✅ Update executed successfully<br>";
            echo "Rows affected: " . $rowsAffected . "<br>";
            
            if ($rowsAffected > 0) {
                echo "✅ Database was updated!<br>";
                
                // Verify the update
                $stmt->execute([$bookingId]);
                $updatedBooking = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo "<br>Updated values:<br>";
                echo "- discount_amount: " . $updatedBooking['discount_amount'] . "<br>";
                echo "- promo_code: " . $updatedBooking['promo_code'] . "<br>";
                echo "- amount_paid: " . $updatedBooking['amount_paid'] . "<br>";
                
                echo "<p style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; color: #155724;'>";
                echo "<strong>🎉 SUCCESS!</strong> Database update is working. The issue is with the payment success handler not running.";
                echo "</p>";
                
            } else {
                echo "❌ No rows were affected by the update<br>";
            }
        } else {
            echo "❌ Update failed<br>";
            echo "Error: " . print_r($updateStmt->errorInfo(), true) . "<br>";
        }
        
    } else {
        echo "❌ Booking ID '" . $bookingId . "' not found in database<br>";
        echo "Available booking IDs are listed in the table above.<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "<br>";
}

echo "<br><br><a href='index.php'>Back to Index</a>";
?>