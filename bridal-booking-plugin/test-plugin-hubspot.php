<?php
// Simple test for plugin HubSpot integration
require_once 'hubspot-integration.php';

echo "<h1>Plugin HubSpot Test</h1>";

if (isset($_GET['test'])) {
    $result = testPluginHubSpot();
    
    if ($result) {
        echo "<p style='color: green; font-size: 18px;'>✅ SUCCESS!</p>";
        echo "<p>Test contact sent to HubSpot with email: wptest@example.com</p>";
        echo "<p><strong>Check your HubSpot contacts now!</strong></p>";
    } else {
        echo "<p style='color: red; font-size: 18px;'>❌ FAILED!</p>";
        echo "<p>Check your server error logs for details.</p>";
        
        // Check if API key is loaded
        $config = loadHubSpotConfig();
        if (empty($config['api_key'])) {
            echo "<p style='color: orange;'>⚠️ HubSpot API key not found in .env file</p>";
        } else {
            echo "<p>API key found: " . substr($config['api_key'], 0, 10) . "...</p>";
        }
    }
} else {
    echo "<p>This will test if your HubSpot integration is working.</p>";
    echo "<p><a href='?test=1' style='background: #0073aa; color: white; padding: 10px 20px; text-decoration: none;'>Run Test</a></p>";
}
?>