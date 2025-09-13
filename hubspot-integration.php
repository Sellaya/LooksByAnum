<?php
function sendQuoteToHubSpot($bookingData) {
    // Load .env file
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
    
    
    
    $apiKey = $_ENV['HUBSPOT_API_KEY'] ?? '';
    if (empty($apiKey)) {
        error_log('HubSpot: API key not found');
        return false;
    }

    // Parse name
    $nameParts = explode(' ', $bookingData['client_name'], 2);
    $firstName = trim($nameParts[0] ?? '');
    $lastName = trim($nameParts[1] ?? '');

    // Calculate financial details
    $totalAmount = floatval($bookingData['quote_total'] ?? 0);
    $paidAmount = floatval($bookingData['deposit_amount'] ?? 0);
    $remainingAmount = $totalAmount - $paidAmount;

    // Map service type to HubSpot values
    $serviceType = $bookingData['service_type'] ?? 'Bridal';
    if (stripos($serviceType, 'semi') !== false) {
        $serviceType = 'Semi Bridal';
    } elseif (stripos($serviceType, 'non') !== false) {
        $serviceType = 'Non-Bridal';
    } else {
        $serviceType = 'Bridal';
    }

    // Map artist to HubSpot values  
    $artist = $bookingData['artist_type'] ?? 'Team';
    if (stripos($artist, 'anum') !== false) {
        $artist = 'By Anum';
    } else {
        $artist = 'Team';
    }

    // Determine payment status (using correct HubSpot capitalization)
    $paymentStatus = 'Pending';
    if ($bookingData['status'] === 'paid') {
        $paymentStatus = ($remainingAmount <= 0) ? 'Paid' : 'Partial';
    }
        error_log("HubSpot received ready_time: " . ($bookingData['ready_time'] ?? 'EMPTY'));
        error_log("HubSpot received appointment_time: " . ($bookingData['appointment_time'] ?? 'EMPTY'));
    $contactData = [
        'properties' => [
            // Basic contact info
            'email' => $bookingData['client_email'],
            'firstname' => $firstName,
            'lastname' => $lastName,
            'phone' => $bookingData['client_phone'] ?? '',
            
            // Address fields
            'address' => $bookingData['street_address'] ?? '',
            'city' => $bookingData['city'] ?? '',
            'state' => $bookingData['province'] ?? '',
            'zip' => $bookingData['postal_code'] ?? '',
            
            // Booking details (existing fields)
            'booking_id' => $bookingData['booking_id'],
            'booking_type' => $serviceType,
            'preferred_artist' => $artist,
            'service_region' => $bookingData['region'] ?? '',
            'event_date' => $bookingData['event_date'] ?? '',
            'ready_time' => $bookingData['ready_time'] ?? '',
            'appointment_time' => $bookingData['appointment_time'] ?? '',
            'trial_date' => $bookingData['trial_date'] ?? '',
            'website' => 'https://quote.looksbyanum.com/?id=' . $bookingData['booking_id'],
            'hs_lead_status' => $bookingData['status'] === 'paid' ? 'CONNECTED' : 'NEW',
            
            // NEW Custom financial fields
            'quote_total' => $totalAmount,
            'deposit_amount' => $paidAmount,
            'remaining_amount' => $remainingAmount,
            'payment_status' => $paymentStatus,
            'contract_pdf_url' => $bookingData['contract_pdf'] ?? '',
            'quote_url' => 'https://quote.looksbyanum.com/?id=' . $bookingData['booking_id'],
            'makeup_style' => $bookingData['makeup_style'] ?? '',
            'inspiration_notes' => $bookingData['inspiration_notes'] ?? '',
            'inspiration_images_url' => $bookingData['inspiration_images_url'] ?? '',
            
            // Detailed notes
            'hs_content_membership_notes' => formatBookingNotes($bookingData, $totalAmount, $paidAmount, $remainingAmount)
        ]
    ];

    // Remove empty values
    $contactData['properties'] = array_filter($contactData['properties'], function($value) {
        return $value !== '' && $value !== null;
    });

    // Search for existing contact first
    $searchData = [
        'filterGroups' => [
            [
                'filters' => [
                    [
                        'propertyName' => 'email',
                        'operator' => 'EQ',
                        'value' => $bookingData['client_email']
                    ]
                ]
            ]
        ]
    ];

    // Search for existing contact
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.hubapi.com/crm/v3/objects/contacts/search');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($searchData));
    
    $searchResponse = curl_exec($ch);
    $searchHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $searchResult = json_decode($searchResponse, true);

    if ($searchHttpCode == 200 && isset($searchResult['results']) && count($searchResult['results']) > 0) {
        // Contact exists - UPDATE it
        $contactId = $searchResult['results'][0]['id'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.hubapi.com/crm/v3/objects/contacts/{$contactId}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($contactData));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("HubSpot: UPDATED contact with ALL fields for " . $bookingData['booking_id']);
            return true;
        }
    } else {
        // Contact doesn't exist - CREATE new one
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.hubapi.com/crm/v3/objects/contacts');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($contactData));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("HubSpot: CREATED contact with ALL fields for " . $bookingData['booking_id']);
            return true;
        }
    }
    
    error_log("HubSpot: Failed for " . $bookingData['booking_id'] . " - " . $response);
    return false;
}

function formatBookingNotes($bookingData, $totalAmount, $paidAmount, $remainingAmount) {
    $notes = "BOOKING DETAILS:\n";
    $notes .= "ID: " . ($bookingData['booking_id'] ?? 'N/A') . "\n";
    $notes .= "Artist: " . ($bookingData['artist_type'] ?? 'N/A') . "\n";
    $notes .= "Service: " . ($bookingData['service_type'] ?? 'N/A') . "\n";
    $notes .= "Region: " . ($bookingData['region'] ?? 'N/A') . "\n\n";
    
    $notes .= "FINANCIAL:\n";
    $notes .= "Total: $" . number_format($totalAmount, 2) . "\n";
    $notes .= "Paid: $" . number_format($paidAmount, 2) . "\n";
    $notes .= "Remaining: $" . number_format($remainingAmount, 2) . "\n\n";
    
    if (!empty($bookingData['event_date'])) {
        $notes .= "Event Date: " . $bookingData['event_date'] . "\n";
    }
    if (!empty($bookingData['ready_time'])) {
        $notes .= "Ready Time: " . $bookingData['ready_time'] . "\n";
    }
    
    $notes .= "Status: " . ($bookingData['status'] ?? 'pending') . "\n";
    $notes .= "Created: " . date('Y-m-d H:i:s');
    
    return $notes;
}
?>