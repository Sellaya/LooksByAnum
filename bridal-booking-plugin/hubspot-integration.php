<?php
/**
 * HubSpot Integration for WordPress Plugin
 */

function loadHubSpotConfig() {
    // Load .env file from public_html/quote/.env (from plugin folder)
    $envFile = dirname(dirname(dirname(dirname(__FILE__)))) . '/quote/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
    
    return [
        'api_key' => $_ENV['HUBSPOT_API_KEY'] ?? '',
        'api_url' => 'https://api.hubapi.com'
    ];
}

function sendBookingToHubSpot($bookingData) {
    $config = loadHubSpotConfig();
    
    if (empty($config['api_key'])) {
        error_log('HubSpot: API key not found');
        return false;
    }

    // Parse name
    $nameParts = explode(' ', $bookingData['client_name'], 2);
    $firstName = trim($nameParts[0] ?? '');
    $lastName = trim($nameParts[1] ?? '');

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

    // Prepare HubSpot contact data
    $contactData = [
        'properties' => [
            'email' => $bookingData['client_email'],
            'firstname' => $firstName,
            'lastname' => $lastName,
            'phone' => $bookingData['client_phone'] ?? '',
            'booking_id' => $bookingData['booking_id'],
            'booking_type' => $serviceType,
            'preferred_artist' => $artist,
            'service_region' => $bookingData['region'] ?? '',
            'event_date' => $bookingData['event_date'] ?? '',
            'quote_url' => $bookingData['quote_url'] ?? '',
            'hs_lead_status' => 'NEW'
        ]
    ];

    // Remove empty values
    $contactData['properties'] = array_filter($contactData['properties'], function($value) {
        return $value !== '' && $value !== null;
    });

    // Send to HubSpot
    $result = sendHubSpotRequest('/crm/v3/objects/contacts', $contactData, $config);
    
    if ($result) {
        error_log("HubSpot: Successfully sent WordPress booking " . $bookingData['booking_id']);
        return true;
    } else {
        error_log("HubSpot: Failed to send WordPress booking " . $bookingData['booking_id']);
        return false;
    }
}

function sendHubSpotRequest($endpoint, $data, $config) {
    $url = $config['api_url'] . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key']
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("HubSpot cURL error: " . $error);
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    } else {
        error_log("HubSpot API error (HTTP $httpCode): " . $response);
        return false;
    }
}

function testPluginHubSpot() {
    $testData = [
        'booking_id' => 'WP_TEST_' . date('YmdHis'),
        'client_name' => 'WordPress Test User',
        'client_email' => 'wptest@example.com',
        'client_phone' => '+1-416-555-0123',
        'artist_type' => 'Team',
        'service_type' => 'Bridal',
        'region' => 'Toronto/GTA',
        'event_date' => '2025-09-15'
    ];
    
    return sendBookingToHubSpot($testData);
}
?>