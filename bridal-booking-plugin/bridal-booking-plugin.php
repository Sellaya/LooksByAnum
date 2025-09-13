<?php
/**
 * Plugin Name: Bridal Booking Form Enhanced
 * Description: A simple bridal booking form with pricing in email and unique IDs
 * Version: 1.1.0
 * Author: Your Name
 */


require_once plugin_dir_path(__FILE__) . 'hubspot-integration.php';
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BridalBookingPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_shortcode('react_form', array($this, 'render_form_shortcode'));
        add_action('wp_ajax_submit_bridal_form', array($this, 'handle_form_submission'));
        add_action('wp_ajax_nopriv_submit_bridal_form', array($this, 'handle_form_submission'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_head', function() {
            echo '<meta http-equiv="Permissions-Policy" content="payment=*, microphone=*, camera=*, fullscreen=*, geolocation=*" />' . "\n";
        });
    }
    
    public function init() {
        // Plugin initialization
    }
    
    public function render_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Bridal Booking Form'
        ), $atts);
        
        ob_start();
        ?>
        <div id="bridal-form-container" style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; background: white;">
            
            <!-- Step 1: Region Selection -->
            <div id="step-1" class="form-step">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: left; color: black;">
                    What region do you need services in? <span style="color: red;">*</span>
                </h2>
                
                <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 30px;">
                    <button type="button" onclick="selectMainRegion('Toronto/GTA')" id="toronto-btn" class="main-region-btn" 
                            style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                        Toronto/GTA
                    </button>
                    
                    <button type="button" onclick="selectMainRegion('Outside GTA')" id="outside-btn" class="main-region-btn"
                            style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                        Outside GTA
                    </button>
                    
                    <button type="button" onclick="selectMainRegion('Destination Wedding')" id="destination-btn" class="main-region-btn"
                            style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                        Destination Wedding
                    </button>
                </div>
                
                <!-- Sub-question for Outside GTA -->
                <div id="sub-regions" style="display: none;">
                    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; text-align: left; color: black;">
                        Roughly how long does it take you to drive to the GTA? <span style="color: red;">*</span>
                    </h3>
                    
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 30px;">
                        <button type="button" onclick="selectSubRegion('Immediate Neighbors (15-30 Minutes)')" class="sub-region-btn" 
                                style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                            Immediate Neighbors (15-30 Minutes)
                        </button>
                        
                        <button type="button" onclick="selectSubRegion('Moderate Distance (30 Minutes to 1 Hour Drive)')" class="sub-region-btn"
                                style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                            Moderate Distance (30 Minutes to 1 Hour Drive)
                        </button>
                        
                        <button type="button" onclick="selectSubRegion('Further Out But Still Reachable (1 Hour Plus)')" class="sub-region-btn"
                                style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                            Further Out But Still Reachable (1 Hour Plus)
                        </button>
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <button type="button" onclick="proceedToNextStep()" id="next-btn" 
                            style="display: none; padding: 15px 30px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px; font-weight: bold;" disabled>
                        Next
                    </button>
                </div>
            </div>

            <!-- Step 2: Date and Time (Toronto/GTA & Outside GTA) -->
            <div id="step-2" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Event Details
                </h2>
                
                <form id="date-time-form" style="display: flex; flex-direction: column; gap: 20px;">
                    <div>
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">What's your event date? <span style="color: red;">*</span></label>
                        <input type="date" id="event_date" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">What is your get ready time? <span style="color: red;">*</span></label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px;">This is the time you need to be ready by. It is NOT the start time.</p>
                        <input type="time" id="ready_time" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="button" onclick="goBackToRegions()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="validateDateTimeAndProceed()" id="datetime-next-btn" style="flex: 1; padding: 15px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px;" disabled>
                            Next →
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 3: Service Type Selection -->
            <div id="step-3" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    What type of service do you need?
                </h2>
                
                <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 30px;">
                    <button type="button" onclick="selectServiceType('Bridal')" class="service-type-btn" 
                            style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                        <div style="font-weight: bold;">Bridal</div>
                        <div style="font-size: 14px; color: #666; margin-top: 5px;">Full bridal makeup and hair styling</div>
                    </button>
                    
                    <button type="button" onclick="selectServiceType('Semi Bridal')" class="service-type-btn"
                            style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                        <div style="font-weight: bold;">Semi Bridal</div>
                        <div style="font-size: 14px; color: #666; margin-top: 5px;">Elegant styling for special occasions</div>
                    </button>
                    
                    <button type="button" onclick="selectServiceType('Non-Bridal / Photoshoot')" class="service-type-btn"
                            style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                        <div style="font-weight: bold;">Non-Bridal / Photoshoot</div>
                        <div style="font-size: 14px; color: #666; margin-top: 5px;">Makeup and styling for photoshoots or events</div>
                    </button>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="goBackToDateTime()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="proceedFromServiceType()" id="service-next-btn" style="flex: 1; padding: 15px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px;" disabled>
                        Next →
                    </button>
                </div>
            </div>

            <!-- Step 4: Service Details -->
            <div id="step-4" class="form-step" style="display: none;">
                <!-- Content will be dynamically loaded based on service type -->
            </div>

            <!-- Step 4-A: Service Breakdown (Non-Bridal only) -->
            <div id="step-4a" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Service Details
                </h2>
                
                <div style="margin-bottom: 30px;">
                    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                        Does everyone need both hair and makeup?
                    </h3>
                    <p style="font-size: 12px; color: #666; margin-bottom: 15px; font-style: italic;">
                        *Choose No if some people need makeup ONLY, or hair ONLY.*
                    </p>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <button type="button" onclick="selectEveryoneBoth('Yes')" class="everyone-both-btn" 
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            Yes
                        </button>
                        <button type="button" onclick="selectEveryoneBoth('No')" class="everyone-both-btn"
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            No
                        </button>
                    </div>
                </div>

                <div id="breakdown-section" style="margin-bottom: 30px; display: none;">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many people need both, Hair AND Makeup done?
                        </label>
                        <input type="number" id="non_bridal_both_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many people need ONLY makeup?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *These people don't need hair done.*
                        </p>
                        <input type="number" id="non_bridal_makeup_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many people need ONLY hair done?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *These people don't need makeup done.*
                        </p>
                        <input type="number" id="non_bridal_hair_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                        How many people need hair extensions installed?
                    </label>
                    <input type="number" id="non_bridal_extensions_count" min="0" value=""
                           style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                        How many people need Jewelry/Dupatta/Veil Setting?
                    </label>
                    <input type="number" id="non_bridal_jewelry_count" min="0" value=""
                           style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                </div>

                <div style="margin-bottom: 30px;">
                    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                        Any member wants airbrush makeup?
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <button type="button" onclick="selectNonBridalAirbrush('Yes')" class="non-bridal-airbrush-btn" 
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            Yes
                        </button>
                        <button type="button" onclick="selectNonBridalAirbrush('No')" class="non-bridal-airbrush-btn"
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            No
                        </button>
                    </div>
                </div>

                <div id="non-bridal-airbrush-count-section" style="margin-bottom: 30px; display: none;">
                    <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                        How many members need airbrush makeup?
                    </label>
                    <input type="number" id="non_bridal_airbrush_count" min="0" value=""
                           style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="goBackToNonBridalCount()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="validateNonBridalBreakdownAndProceed()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                        Next →
                    </button>
                </div>
            </div>

            <!-- Step 5: Add-ons (Bridal/Semi Bridal only) -->
            <div id="step-addons" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Additional Services
                </h2>
                
                <div style="margin-bottom: 30px;">
                    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                        Does the bride need jewelry & dupatta/veil setting?
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <button type="button" onclick="selectJewelry('Yes')" class="jewelry-btn" 
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            Yes
                        </button>
                        <button type="button" onclick="selectJewelry('No')" class="jewelry-btn"
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            No
                        </button>
                    </div>
                </div>

                <div style="margin-bottom: 30px;">
                    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                        Does the bride need hair extensions installed?
                    </h3>
                    <p style="font-size: 12px; color: #666; margin-bottom: 15px; font-style: italic;">
                        *We do not provide the hair extensions. Bride must have her own.*
                    </p>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <button type="button" onclick="selectExtensions('Yes')" class="extensions-btn" 
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            Yes
                        </button>
                        <button type="button" onclick="selectExtensions('No')" class="extensions-btn"
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            No
                        </button>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="goBackToServiceDetails()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="validateAddonsAndProceed()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                        Next →
                    </button>
                </div>
            </div>

            <!-- Step 6: Bridal Party (Bridal/Semi Bridal only) -->
            <div id="step-party" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Bridal Party Services
                </h2>
                
                <div style="margin-bottom: 30px;">
                    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                        Aside from the bride, are there other bridal party members also requiring hair and/or makeup services?
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <button type="button" onclick="selectPartyMembers('Yes')" class="party-members-btn" 
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            Yes
                        </button>
                        <button type="button" onclick="selectPartyMembers('No')" class="party-members-btn"
                                style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                            No
                        </button>
                    </div>
                </div>

                <div id="party-details-section" style="margin-bottom: 30px; display: none;">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many bridal party members need both hair & makeup?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *This does not include the bride.*
                        </p>
                        <input type="number" id="party_both_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many bridal party members need makeup done only?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *These people do not need hair done. This does not include the bride.*
                        </p>
                        <input type="number" id="party_makeup_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many bridal party members need hair done only?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *These people do not need makeup done. This does not include the bride.*
                        </p>
                        <input type="number" id="party_hair_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many bridal party members need dupatta/veil setting?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *This does not include the bride.*
                        </p>
                        <input type="number" id="party_dupatta_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many bridal party members need hair extensions installed?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *We do not provide the hair extensions. They must have their own. This does not include the bride.*
                        </p>
                        <input type="number" id="party_extensions_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 15px; color: black;">
                            Do any bridal party members need airbrush makeup?
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <button type="button" onclick="selectAirbrush('Yes')" class="airbrush-btn" 
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Yes
                            </button>
                            <button type="button" onclick="selectAirbrush('No')" class="airbrush-btn"
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                No
                            </button>
                        </div>
                    </div>

                    <div id="airbrush-count-section" style="margin-bottom: 20px; display: none;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            How many bridal party members need airbrush makeup?
                        </label>
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px; font-style: italic;">
                            *This is an upgrade for makeup services. This does not include the bride.*
                        </p>
                        <input type="number" id="airbrush_count" min="0" value=""
                               style="width: 100%; padding: 10px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;">
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="goBackToAddons()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="validatePartyAndProceed()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                        Next →
                    </button>
                </div>
            </div>

            <!-- Step 5: Artist Selection -->
            <div id="step-5" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Choose Your Artist
                </h2>
                
                <div id="artist-quotes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                    <!-- Quotes will be generated dynamically -->
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="goBackToServiceDetails()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="proceedToContact()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                        Next →
                    </button>
                </div>
            </div>

            <!-- Step 6: Contact Information -->
            <div id="step-6" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Contact Information
                </h2>
                
                <form id="contact-form" style="display: flex; flex-direction: column; gap: 20px;">
                    <div>
                        <input type="text" id="first_name" placeholder="First Name *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <input type="text" id="last_name" placeholder="Last Name *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <input type="email" id="email" placeholder="Email Address *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <input type="tel" id="phone" placeholder="Phone/Mobile (Canadian numbers only) *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div style="padding: 15px; background: #e3f2fd; border: 1px solid #2196f3; border-radius: 6px; font-size: 12px; color: #1976d2;">
                        <strong>SMS Consent:</strong> You agree to receive automated booking confirmations and reminders via SMS. Message & data rates may apply. Your number will only be used for booking-related communication.
                    </div>
                    
                    <div id="contact-buttons" style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="button" onclick="goBackToContactPrevious()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="validateContactAndProceedNext()" id="contact-next-btn" style="flex: 1; padding: 15px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px;" disabled>
                            Next →
                        </button>
                    </div>
                </form>
            </div>

            <!-- Destination Wedding Steps (unchanged) -->
            <!-- Step 1.5: Event Dates (Only for Destination Wedding) -->
            <div id="step-1-5" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Event Dates
                </h2>
                
                <form id="event-dates-form" style="display: flex; flex-direction: column; gap: 20px;">
                    <div>
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">Event Starting Date <span style="color: red;">*</span></label>
                        <input type="date" id="event_start_date" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">Event Ending Date <span style="color: red;">*</span></label>
                        <input type="date" id="event_end_date" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="button" onclick="goBackToRegionsFromDates()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="validateDatesAndProceed()" style="flex: 1; padding: 15px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px;" disabled>
                            Next →
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 1.6: Destination Wedding Details -->
            <div id="step-1-6" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Destination Wedding Details
                </h2>
                
                <form id="destination-details-form" style="display: flex; flex-direction: column; gap: 20px;">
                    <div>
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">Additional details about your destination wedding: <span style="color: red;">*</span></label>
                        <textarea id="destination_details" rows="6" placeholder="Please share any additional details about your destination wedding, such as location, venue, special requirements, or any other information that would help us serve you better..." required
                                  style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white; resize: vertical; font-family: Arial, sans-serif;"></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="button" onclick="goBackToEventDates()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="proceedToUserDetails()" id="dest-details-next-btn" style="flex: 1; padding: 15px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px;" disabled>
                            Next →
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 2: User Details (Destination Wedding) -->
            <div id="step-dest-contact" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Your Contact Information
                </h2>
                
                <form id="dest-contact-form" style="display: flex; flex-direction: column; gap: 20px;">
                    <div>
                        <input type="text" id="dest_first_name" placeholder="First Name *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <input type="text" id="dest_last_name" placeholder="Last Name *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <input type="email" id="dest_email" placeholder="Email Address *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div>
                        <input type="tel" id="dest_phone" placeholder="Phone Number *" required 
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; box-sizing: border-box; color: black; background: white;">
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="button" onclick="goBackToDestDetails()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="validateDestContactAndProceed()" style="flex: 1; padding: 15px; background: #ccc; color: #666; border: none; border-radius: 6px; cursor: not-allowed; font-size: 16px;" disabled>
                            Next →
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 2.5: Calendly Booking (Only for Destination Wedding) -->
            <div id="step-2-5" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Schedule Your Consultation
                </h2>
                
                <!-- Calendly inline widget begin -->
                <iframe 
                    src="https://calendly.com/ismaanwar-ia?embed_domain=<?php echo $_SERVER['HTTP_HOST']; ?>&embed_type=Inline&hide_landing_page_details=1" 
                    width="100%" 
                    height="700" 
                    frameborder="0"
                    allow="microphone; camera; payment; fullscreen; geolocation"
                    sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-top-navigation"
                    style="border-radius: 8px;">
                </iframe>
                <!-- Calendly inline widget end -->
                
                <div style="display: flex; gap: 15px; margin-top: 20px;">
                    <button type="button" onclick="goBackToDestContact()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="proceedToDestSummary()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                        Continue to Summary →
                    </button>
                </div>
            </div>

            <!-- Step 7: Summary -->
            <div id="step-7" class="form-step" style="display: none;">
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Booking Summary
                </h2>
                
                <div id="booking-summary" style="padding: 25px; background: #f9f9f9; border-radius: 8px; margin-bottom: 25px; border: 2px solid black; color: black;">
                    <!-- Booking details will be displayed here -->
                </div>
                
                <div id="form-message" style="margin-bottom: 20px; text-align: center;"></div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="goBackToEdit()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Edit Details
                    </button>
                    <button type="button" onclick="submitBooking()" id="submit-btn" style="flex: 2; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold;">
                        Confirm Booking ✓
                    </button>
                </div>
                
                <button type="button" onclick="startOver()" style="width: 100%; margin-top: 15px; padding: 8px; background: transparent; border: none; color: black; cursor: pointer; text-decoration: underline; font-size: 14px;">
                    Start Over
                </button>
            </div>
        </div>

        <style>
        .main-region-btn:hover, .sub-region-btn:hover, .service-type-btn:hover, 
        .bride-service-btn:hover, .trial-btn:hover, .trial-service-btn:hover,
        .jewelry-btn:hover, .extensions-btn:hover, .party-members-btn:hover,
        .airbrush-btn:hover, .semi-service-btn:hover, .everyone-both-btn:hover,
        .non-bridal-airbrush-btn:hover, .artist-selection-btn:hover {
            background-color: #f0f0f0 !important;
            color: black !important;
        }
        
        .main-region-btn.selected, .sub-region-btn.selected, .service-type-btn.selected,
        .bride-service-btn.selected, .trial-btn.selected, .trial-service-btn.selected,
        .jewelry-btn.selected, .extensions-btn.selected, .party-members-btn.selected,
        .airbrush-btn.selected, .semi-service-btn.selected, .everyone-both-btn.selected,
        .non-bridal-airbrush-btn.selected {
            background-color: black !important;
            color: white !important;
        }
        
        .artist-option.selected {
            border-color: #e91e63 !important;
            background-color: #fce4ec !important;
        }
        .artist-option:hover {
            border-color: #e91e63 !important;
            background-color: #fce4ec !important;
        }
        
        /* Ensure selected state takes priority over hover */
        .main-region-btn.selected:hover, .sub-region-btn.selected:hover, .service-type-btn.selected:hover,
        .bride-service-btn.selected:hover, .trial-btn.selected:hover, .trial-service-btn.selected:hover,
        .jewelry-btn.selected:hover, .extensions-btn.selected:hover, .party-members-btn.selected:hover,
        .airbrush-btn.selected:hover, .semi-service-btn.selected:hover, .everyone-both-btn.selected:hover,
        .non-bridal-airbrush-btn.selected:hover {
            background-color: black !important;
            color: white !important;
        }
        
        /* Ensure nested content in selected buttons also gets white text - IMPORTANT for trial buttons */
        .service-type-btn.selected div,
        .trial-btn.selected div {
            color: white !important;
        }
        
        /* Specific fix for trial buttons with nested divs */
        .trial-btn.selected > div:first-child {
            color: white !important;
        }
        .trial-btn.selected > div:last-child {
            color: white !important;
        }
        
        /* Override inline styles for selected trial buttons */
        .trial-btn.selected {
            background-color: black !important;
            color: white !important;
        }
        </style>

        <script>
        // Form data storage
        let formData = {
            region: '',
            subRegion: '',
            eventDate: '',
            readyTime: '',
            serviceType: '',
            artist: '',
            // Service details
            brideService: '',
            needsTrial: '',
            trialService: '',
            needsJewelry: '',
            needsExtensions: '',
            hasPartyMembers: '',
            partyBothCount: 0,
            partyMakeupCount: 0,
            partyHairCount: 0,
            partyDupattaCount: 0,
            partyExtensionsCount: 0,
            hasAirbrush: '',
            airbrushCount: 0,
            nonBridalCount: 0,
            nonBridalEveryoneBoth: '',
            nonBridalBothCount: 0,
            nonBridalMakeupOnlyCount: 0,
            nonBridalHairOnlyCount: 0,
            nonBridalExtensionsCount: 0,
            nonBridalJewelryCount: 0,
            nonBridalHasAirbrush: '',
            nonBridalAirbrushCount: 0,
            // Contact info
            firstName: '',
            lastName: '',
            email: '',
            phone: '',
            // Destination wedding
            startDate: '',
            endDate: '',
            destinationDetails: ''
        };

        // Set minimum date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                if (input) input.min = today;
            });
            
            // Add event listeners for date and time inputs
            const eventDateInput = document.getElementById('event_date');
            const readyTimeInput = document.getElementById('ready_time');
            
            if (eventDateInput && readyTimeInput) {
                eventDateInput.addEventListener('change', checkDateTimeComplete);
                readyTimeInput.addEventListener('change', checkDateTimeComplete);
            }
            
            // Add event listener for destination details textarea
            const destDetailsTextarea = document.getElementById('destination_details');
            if (destDetailsTextarea) {
                destDetailsTextarea.addEventListener('input', checkDestinationDetailsComplete);
            }
            
            // Add event listeners for contact form inputs
            const contactInputs = ['first_name', 'last_name', 'email', 'phone'];
            contactInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', checkContactComplete);
                }
            });
            
            // Add event listeners for destination contact form inputs
            const destContactInputs = ['dest_first_name', 'dest_last_name', 'dest_email', 'dest_phone'];
            destContactInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', checkDestContactComplete);
                }
            });
            
            // Add event listener for destination wedding date validation
            const startDateInput = document.getElementById('event_start_date');
            const endDateInput = document.getElementById('event_end_date');
            
            if (startDateInput && endDateInput) {
                startDateInput.addEventListener('change', function() {
                    const startDate = this.value;
                    if (startDate) {
                        // Set minimum end date to the day after start date
                        const startDateObj = new Date(startDate);
                        startDateObj.setDate(startDateObj.getDate() + 1);
                        const minEndDate = startDateObj.toISOString().split('T')[0];
                        endDateInput.min = minEndDate;
                        
                        // If end date is already selected and is before or equal to start date, clear it
                        if (endDateInput.value && endDateInput.value <= startDate) {
                            endDateInput.value = '';
                        }
                    }
                    checkDestinationDatesComplete();
                });
                
                endDateInput.addEventListener('change', checkDestinationDatesComplete);
            }
        });

        function checkDateTimeComplete() {
            const eventDate = document.getElementById('event_date').value;
            const readyTime = document.getElementById('ready_time').value;
            const nextBtn = document.getElementById('datetime-next-btn');
            
            if (eventDate && readyTime && nextBtn) {
                nextBtn.style.background = 'black';
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            } else if (nextBtn) {
                nextBtn.style.background = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }

        function checkDestinationDatesComplete() {
            const startDate = document.getElementById('event_start_date').value;
            const endDate = document.getElementById('event_end_date').value;
            const nextBtn = document.querySelector('#step-1-5 button[onclick="validateDatesAndProceed()"]');
            
            if (startDate && endDate && nextBtn) {
                nextBtn.style.background = 'black';
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            } else if (nextBtn) {
                nextBtn.style.background = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }

        function checkDestinationDetailsComplete() {
            const details = document.getElementById('destination_details').value.trim();
            const nextBtn = document.getElementById('dest-details-next-btn');
            
            if (details && nextBtn) {
                nextBtn.style.background = 'black';
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            } else if (nextBtn) {
                nextBtn.style.background = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }

        function checkContactComplete() {
            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const phone = document.getElementById('phone').value.trim();
            const nextBtn = document.getElementById('contact-next-btn');
            
            if (firstName && lastName && email && phone && nextBtn) {
                if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                    nextBtn.style.background = '#28a745';
                } else {
                    nextBtn.style.background = 'black';
                }
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            } else if (nextBtn) {
                nextBtn.style.background = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }

        function checkDestContactComplete() {
            const firstName = document.getElementById('dest_first_name').value.trim();
            const lastName = document.getElementById('dest_last_name').value.trim();
            const email = document.getElementById('dest_email').value.trim();
            const phone = document.getElementById('dest_phone').value.trim();
            const nextBtn = document.querySelector('#step-dest-contact button[onclick="validateDestContactAndProceed()"]');
            
            if (firstName && lastName && email && phone && nextBtn) {
                nextBtn.style.background = 'black';
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            } else if (nextBtn) {
                nextBtn.style.background = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }

        // Canadian phone validation
        function validateCanadianPhone(phone) {
            const digits = phone.replace(/\D/g, '');
            if (digits.length === 10) {
                return digits.match(/^[2-9]\d{2}[2-9]\d{2}\d{4}$/);
            } else if (digits.length === 11 && digits[0] === '1') {
                return digits.match(/^1[2-9]\d{2}[2-9]\d{2}\d{4}$/);
            }
            return false;
        }

        // Pricing calculations
        function getDetailedQuoteForArtist(artistType) {
            const quote = [];
            let subtotal = 0;
            
            // Travel fee
            let travelFee = 0;
            if (formData.region === 'Toronto/GTA') {
                travelFee = 25;
            } else if (formData.region === 'Outside GTA') {
                if (formData.subRegion === 'Immediate Neighbors (15-30 Minutes)') {
                    travelFee = 40;
                } else if (formData.subRegion === 'Moderate Distance (30 Minutes to 1 Hour Drive)') {
                    travelFee = 80;
                } else if (formData.subRegion === 'Further Out But Still Reachable (1 Hour Plus)') {
                    travelFee = 120;
                }
            }
            
            const isAnumArtist = artistType === 'By Anum';
            const artistText = isAnumArtist ? '(with Anum)' : '(with Team)';
            
            // Main service pricing
            if (formData.serviceType === 'Bridal') {
                if (formData.brideService === 'Both Hair & Makeup') {
                    const price = isAnumArtist ? 450 : 360;
                    quote.push({ description: `Bridal Makeup & Hair ${artistText}:`, price });
                    subtotal += price;
                } else if (formData.brideService === 'Hair Only') {
                    const price = isAnumArtist ? 200 : 160;
                    quote.push({ description: `Bridal Hair Only ${artistText}:`, price });
                    subtotal += price;
                } else if (formData.brideService === 'Makeup Only') {
                    const price = isAnumArtist ? 275 : 220;
                    quote.push({ description: `Bridal Makeup Only ${artistText}:`, price });
                    subtotal += price;
                }
                
                // Trial services
                if (formData.needsTrial === 'Yes') {
                    if (formData.trialService === 'Both Hair & Makeup') {
                        const price = isAnumArtist ? 250 : 200;
                        quote.push({ description: `Bridal Trial (Hair & Makeup):`, price });
                        subtotal += price;
                    } else if (formData.trialService === 'Hair Only') {
                        const price = isAnumArtist ? 150 : 120;
                        quote.push({ description: `Bridal Trial (Hair Only):`, price });
                        subtotal += price;
                    } else if (formData.trialService === 'Makeup Only') {
                        const price = isAnumArtist ? 150 : 120;
                        quote.push({ description: `Bridal Trial (Makeup Only):`, price });
                        subtotal += price;
                    }
                }
                
                // Add-ons
                if (formData.needsJewelry === 'Yes') {
                    quote.push({ description: `Bridal Jewelry & Dupatta/Veil Setting:`, price: 50 });
                    subtotal += 50;
                }
                if (formData.needsExtensions === 'Yes') {
                    quote.push({ description: `Bridal Hair Extensions Installation:`, price: 30 });
                    subtotal += 30;
                }
                
                // Bridal party
                if (formData.hasPartyMembers === 'Yes') {
                    if (formData.partyBothCount > 0) {
                        const totalPrice = formData.partyBothCount * 200;
                        quote.push({ description: `Bridal Party Hair and Makeup (200 CAD x ${formData.partyBothCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyMakeupCount > 0) {
                        const totalPrice = formData.partyMakeupCount * 100;
                        quote.push({ description: `Bridal Party Makeup Only (100 CAD x ${formData.partyMakeupCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyHairCount > 0) {
                        const totalPrice = formData.partyHairCount * 100;
                        quote.push({ description: `Bridal Party Hair Only (100 CAD x ${formData.partyHairCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyDupattaCount > 0) {
                        const totalPrice = formData.partyDupattaCount * 20;
                        quote.push({ description: `Bridal Party Dupatta/Veil Setting (20 CAD x ${formData.partyDupattaCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyExtensionsCount > 0) {
                        const totalPrice = formData.partyExtensionsCount * 20;
                        quote.push({ description: `Bridal Party Hair Extensions Installation (20 CAD x ${formData.partyExtensionsCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.hasAirbrush === 'Yes' && formData.airbrushCount > 0) {
                        const totalPrice = formData.airbrushCount * 50;
                        quote.push({ description: `Bridal Party Airbrush Makeup (50 CAD x ${formData.airbrushCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                }
            } else if (formData.serviceType === 'Semi Bridal') {
                if (formData.brideService === 'Both Hair & Makeup') {
                    const price = isAnumArtist ? 350 : 280;
                    quote.push({ description: `Semi Bridal Makeup & Hair ${artistText}:`, price });
                    subtotal += price;
                } else if (formData.brideService === 'Hair Only') {
                    const price = isAnumArtist ? 175 : 140;
                    quote.push({ description: `Semi Bridal Hair Only ${artistText}:`, price });
                    subtotal += price;
                } else if (formData.brideService === 'Makeup Only') {
                    const price = isAnumArtist ? 225 : 180;
                    quote.push({ description: `Semi Bridal Makeup Only ${artistText}:`, price });
                    subtotal += price;
                }
                
                // Add-ons (same as bridal)
                if (formData.needsJewelry === 'Yes') {
                    quote.push({ description: `Bridal Jewelry & Dupatta/Veil Setting:`, price: 50 });
                    subtotal += 50;
                }
                if (formData.needsExtensions === 'Yes') {
                    quote.push({ description: `Bridal Hair Extensions Installation:`, price: 30 });
                    subtotal += 30;
                }
                
                // Bridal party (same logic as bridal)
                if (formData.hasPartyMembers === 'Yes') {
                    if (formData.partyBothCount > 0) {
                        const totalPrice = formData.partyBothCount * 200;
                        quote.push({ description: `Bridal Party Hair and Makeup (200 CAD x ${formData.partyBothCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyMakeupCount > 0) {
                        const totalPrice = formData.partyMakeupCount * 100;
                        quote.push({ description: `Bridal Party Makeup Only (100 CAD x ${formData.partyMakeupCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyHairCount > 0) {
                        const totalPrice = formData.partyHairCount * 100;
                        quote.push({ description: `Bridal Party Hair Only (100 CAD x ${formData.partyHairCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyDupattaCount > 0) {
                        const totalPrice = formData.partyDupattaCount * 20;
                        quote.push({ description: `Bridal Party Dupatta/Veil Setting (20 CAD x ${formData.partyDupattaCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.partyExtensionsCount > 0) {
                        const totalPrice = formData.partyExtensionsCount * 20;
                        quote.push({ description: `Bridal Party Hair Extensions Installation (20 CAD x ${formData.partyExtensionsCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.hasAirbrush === 'Yes' && formData.airbrushCount > 0) {
                        const totalPrice = formData.airbrushCount * 50;
                        quote.push({ description: `Bridal Party Airbrush Makeup (50 CAD x ${formData.airbrushCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                }
            } else if (formData.serviceType === 'Non-Bridal / Photoshoot') {
                if (formData.nonBridalEveryoneBoth === 'Yes') {
                    const pricePerPerson = isAnumArtist ? 250 : 200;
                    const totalPrice = formData.nonBridalCount * pricePerPerson;
                    quote.push({ description: `${formData.serviceType} Hair & Makeup ${artistText} (${pricePerPerson} CAD x ${formData.nonBridalCount}):`, price: totalPrice });
                    subtotal += totalPrice;
                } else if (formData.nonBridalEveryoneBoth === 'No') {
                    if (formData.nonBridalBothCount > 0) {
                        const pricePerPerson = isAnumArtist ? 250 : 200;
                        const totalPrice = formData.nonBridalBothCount * pricePerPerson;
                        quote.push({ description: `${formData.serviceType} Hair & Makeup ${artistText} (${pricePerPerson} CAD x ${formData.nonBridalBothCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.nonBridalMakeupOnlyCount > 0) {
                        const pricePerPerson = isAnumArtist ? 140 : 110;
                        const totalPrice = formData.nonBridalMakeupOnlyCount * pricePerPerson;
                        quote.push({ description: `${formData.serviceType} Makeup Only ${artistText} (${pricePerPerson} CAD x ${formData.nonBridalMakeupOnlyCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                    if (formData.nonBridalHairOnlyCount > 0) {
                        const pricePerPerson = isAnumArtist ? 130 : 110;
                        const totalPrice = formData.nonBridalHairOnlyCount * pricePerPerson;
                        quote.push({ description: `${formData.serviceType} Hair Only ${artistText} (${pricePerPerson} CAD x ${formData.nonBridalHairOnlyCount}):`, price: totalPrice });
                        subtotal += totalPrice;
                    }
                }
                
                // Add-ons
                if (formData.nonBridalExtensionsCount > 0) {
                    const totalPrice = formData.nonBridalExtensionsCount * 20;
                    quote.push({ description: `Hair Extensions Installation (20 CAD x ${formData.nonBridalExtensionsCount}):`, price: totalPrice });
                    subtotal += totalPrice;
                }
                if (formData.nonBridalJewelryCount > 0) {
                    const totalPrice = formData.nonBridalJewelryCount * 20;
                    quote.push({ description: `Jewelry/Dupatta Setting (20 CAD x ${formData.nonBridalJewelryCount}):`, price: totalPrice });
                    subtotal += totalPrice;
                }
                if (formData.nonBridalHasAirbrush === 'Yes' && formData.nonBridalAirbrushCount > 0) {
                    const totalPrice = formData.nonBridalAirbrushCount * 50;
                    quote.push({ description: `Airbrush Makeup (50 CAD x ${formData.nonBridalAirbrushCount}):`, price: totalPrice });
                    subtotal += totalPrice;
                }
            }
            
            // Travel fee
            if (travelFee > 0) {
                const travelDescription = formData.region === 'Toronto/GTA' ? 'Travel Fee (Toronto/GTA):' : `Travel Fee (${formData.subRegion}):`;
                quote.push({ description: travelDescription, price: travelFee });
                subtotal += travelFee;
            }
            
            const hst = subtotal * 0.13;
            const total = subtotal + hst;
            
            return { quote, subtotal, hst, total };
        }

        // Step 1: Region Selection
        function selectMainRegion(region) {
            formData.region = region;
            
            // Clear all main region selections
            document.querySelectorAll('.main-region-btn').forEach(btn => btn.classList.remove('selected'));
            
            // Highlight selected region
            if (region === 'Toronto/GTA') {
                document.getElementById('toronto-btn').classList.add('selected');
                document.getElementById('sub-regions').style.display = 'none';
                enableNextButton();
            } else if (region === 'Outside GTA') {
                document.getElementById('outside-btn').classList.add('selected');
                document.getElementById('sub-regions').style.display = 'block';
                // Show next button only if sub-region is already selected
                if (formData.subRegion) {
                    enableNextButton();
                } else {
                    disableNextButton();
                }
            } else if (region === 'Destination Wedding') {
                document.getElementById('destination-btn').classList.add('selected');
                document.getElementById('sub-regions').style.display = 'none';
                enableNextButton();
            }
        }

        function selectSubRegion(subRegion) {
            formData.subRegion = subRegion;
            
            // Clear all sub-region selections
            document.querySelectorAll('.sub-region-btn').forEach(btn => btn.classList.remove('selected'));
            
            // Highlight selected sub-region
            event.currentTarget.classList.add('selected');
            
            // Enable next button
            enableNextButton();
        }

        function enableNextButton() {
            const nextBtn = document.getElementById('next-btn');
            if (nextBtn) {
                nextBtn.style.display = 'inline-block';
                nextBtn.style.background = 'black';
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            }
        }

        function disableNextButton() {
            const nextBtn = document.getElementById('next-btn');
            if (nextBtn) {
                nextBtn.style.display = 'inline-block';
                nextBtn.style.background = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
        }

        function proceedToNextStep() {
            if (!formData.region) {
                alert('Please select a region.');
                return;
            }
            
            if (formData.region === 'Outside GTA' && !formData.subRegion) {
                alert('Please select how long it takes to drive to the GTA.');
                return;
            }
            
            // Hide step 1
            document.getElementById('step-1').style.display = 'none';
            
            if (formData.region === 'Destination Wedding') {
                // Go to destination wedding flow
                document.getElementById('step-1-5').style.display = 'block';
            } else {
                // Go to regular flow - date/time step
                document.getElementById('step-2').style.display = 'block';
            }
        }

        // Step 2: Date and Time
        function validateDateTimeAndProceed() {
            const eventDate = document.getElementById('event_date').value;
            const readyTime = document.getElementById('ready_time').value;
            const today = new Date().toISOString().split('T')[0];

            if (!eventDate) {
                alert('Please select an event date.');
                return;
            }

            if (eventDate < today) {
                alert('Event date cannot be in the past.');
                return;
            }

            if (!readyTime) {
                alert('Please select a ready time.');
                return;
            }

            formData.eventDate = eventDate;
            formData.readyTime = readyTime;

            document.getElementById('step-2').style.display = 'none';
            showServiceTypeStep();
        }

        function goBackToRegions() {
            document.getElementById('step-2').style.display = 'none';
            
            // Restore region selections when going back to step-1
            setTimeout(() => {
                if (formData.region) {
                    // Restore main region selection
                    if (formData.region === 'Toronto/GTA') {
                        document.getElementById('toronto-btn').classList.add('selected');
                        document.getElementById('sub-regions').style.display = 'none';
                        enableNextButton();
                    } else if (formData.region === 'Outside GTA') {
                        document.getElementById('outside-btn').classList.add('selected');
                        document.getElementById('sub-regions').style.display = 'block';
                        
                        // Restore sub-region selection
                        if (formData.subRegion) {
                            document.querySelectorAll('.sub-region-btn').forEach(btn => {
                                if (btn.textContent.trim() === formData.subRegion) {
                                    btn.classList.add('selected');
                                }
                            });
                            enableNextButton();
                        } else {
                            disableNextButton();
                        }
                    } else if (formData.region === 'Destination Wedding') {
                        document.getElementById('destination-btn').classList.add('selected');
                        document.getElementById('sub-regions').style.display = 'none';
                        enableNextButton();
                    }
                }
            }, 10);
            
            document.getElementById('step-1').style.display = 'block';
        }

        function goBackToDateTime() {
            document.getElementById('step-3').style.display = 'none';
            
            // Restore date and time values when going back to step-2
            setTimeout(() => {
                if (formData.eventDate) {
                    document.getElementById('event_date').value = formData.eventDate;
                }
                if (formData.readyTime) {
                    document.getElementById('ready_time').value = formData.readyTime;
                }
                // Check if button should be enabled
                checkDateTimeComplete();
            }, 10);
            
            document.getElementById('step-2').style.display = 'block';
        }

        // Function to restore service type selection when navigating to step-3
        function showServiceTypeStep() {
            document.getElementById('step-3').style.display = 'block';
            
            // Restore previously selected service type with a slight delay for DOM rendering
            setTimeout(() => {
                if (formData.serviceType) {
                    document.querySelectorAll('.service-type-btn').forEach(btn => {
                        btn.classList.remove('selected'); // Clear first
                        const btnText = btn.querySelector('div').textContent.trim();
                        if (btnText === formData.serviceType) {
                            btn.classList.add('selected');
                        }
                    });
                    
                    // Enable the next button if a service type is already selected
                    const nextBtn = document.getElementById('service-next-btn');
                    if (nextBtn) {
                        nextBtn.style.background = 'black';
                        nextBtn.style.color = 'white';
                        nextBtn.style.cursor = 'pointer';
                        nextBtn.disabled = false;
                    }
                } else {
                    // Ensure next button is disabled if no selection
                    const nextBtn = document.getElementById('service-next-btn');
                    if (nextBtn) {
                        nextBtn.style.background = '#ccc';
                        nextBtn.style.color = '#666';
                        nextBtn.style.cursor = 'not-allowed';
                        nextBtn.disabled = true;
                    }
                }
            }, 50);
        }

        function proceedFromServiceType() {
            if (!formData.serviceType) {
                alert('Please select a service type.');
                return;
            }
            
            document.getElementById('step-3').style.display = 'none';
            
            // Load appropriate service details form
            loadServiceDetailsForm(formData.serviceType);
            
            document.getElementById('step-4').style.display = 'block';
        }

        // Helper function to check if service type step is complete
        function isServiceTypeComplete() {
            return formData.serviceType !== '';
        }

        // Step 3: Service Type Selection
        function selectServiceType(serviceType) {
            formData.serviceType = serviceType;
            
            // Clear all service type selections and highlight the selected one
            document.querySelectorAll('.service-type-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            
            // Enable the next button
            const nextBtn = document.getElementById('service-next-btn');
            if (nextBtn) {
                nextBtn.style.background = 'black';
                nextBtn.style.color = 'white';
                nextBtn.style.cursor = 'pointer';
                nextBtn.disabled = false;
            }
        }

        function loadServiceDetailsForm(serviceType) {
            const step4 = document.getElementById('step-4');
            
            if (serviceType === 'Bridal') {
                step4.innerHTML = `
                    <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                        Bridal Service Details
                    </h2>
                    
                    <div style="margin-bottom: 30px;">
                        <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                            What service does the bride need?
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <button type="button" onclick="selectBrideService('Both Hair & Makeup')" class="bride-service-btn" 
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Both Hair & Makeup
                            </button>
                            <button type="button" onclick="selectBrideService('Hair Only')" class="bride-service-btn"
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Hair Only
                            </button>
                            <button type="button" onclick="selectBrideService('Makeup Only')" class="bride-service-btn"
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Makeup Only
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 30px;">
                        <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                            Do you need a Bridal Trial?
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <button type="button" onclick="selectTrial('Yes')" class="trial-btn" 
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                <div><span style="font-weight: bold;">Yes</span> <span style="font-size: 14px; color: #666; margin-top: 5px;">(I would like to add a bridal trial)</span></div>
                            </button>
                            <button type="button" onclick="selectTrial('No')" class="trial-btn"
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                <div><span style="font-weight: bold;">No</span> <span style="font-size: 14px; color: #666; margin-top: 5px;">(No trial needed)</span></div>
                                
                            </button>
                        </div>
                    </div>

                    <div id="trial-service-section" style="margin-bottom: 30px; display: none;">
                        <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 20px; color: black;">
                            What trials does the bride need?
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <button type="button" onclick="selectTrialService('Both Hair & Makeup')" class="trial-service-btn" 
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Both Hair & Makeup
                            </button>
                            <button type="button" onclick="selectTrialService('Hair Only')" class="trial-service-btn"
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Hair Only
                            </button>
                            <button type="button" onclick="selectTrialService('Makeup Only')" class="trial-service-btn"
                                    style="padding: 15px; border: 2px solid black; border-radius: 6px; background: white; cursor: pointer; text-align: left; font-size: 16px; color: black;">
                                Makeup Only
                            </button>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <button type="button" onclick="goBackToServiceType()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="validateBridalServiceAndProceed()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                            Next →
                        </button>
                    </div>
                `;
            } else if (serviceType === 'Semi Bridal') {
                step4.innerHTML = `
                    <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                        What service does the bride need?
                    </h2>
                    
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 30px;">
                        <button type="button" onclick="selectSemiBridalService('Both Hair & Makeup')" class="semi-service-btn" 
                                style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                            Both Hair & Makeup
                        </button>
                        
                        <button type="button" onclick="selectSemiBridalService('Hair Only')" class="semi-service-btn"
                                style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                            Hair Only
                        </button>
                        
                        <button type="button" onclick="selectSemiBridalService('Makeup Only')" class="semi-service-btn"
                                style="padding: 20px; border: 2px solid black; border-radius: 8px; background: white; cursor: pointer; text-align: left; font-size: 16px; font-weight: 500; color: black; transition: all 0.3s;">
                            Makeup Only
                        </button>
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <button type="button" onclick="goBackToServiceType()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="proceedFromSemiBridal()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                            Next →
                        </button>
                    </div>
                `;
            } else if (serviceType === 'Non-Bridal / Photoshoot') {
                step4.innerHTML = `
                    <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                        How many people need my service?
                    </h2>
                    
                    <div style="margin-bottom: 30px;">
                        <label style="display: block; font-weight: bold; margin-bottom: 8px; color: black;">
                            Number of people requiring hair & makeup services
                        </label>
                        <input type="number" id="non_bridal_count" min="1" value="1"
                               style="width: 100%; padding: 15px; border: 2px solid black; border-radius: 6px; font-size: 16px; color: black; background: white;"
                               placeholder="Enter number of people">
                    </div>
                    
                    <div style="display: flex; gap: 15px;">
                        <button type="button" onclick="goBackToServiceType()" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                            ← Back
                        </button>
                        <button type="button" onclick="validateNonBridalCountAndProceed()" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                            Next →
                        </button>
                    </div>
                `;
            }
        }

        // Service detail handlers
        function selectBrideService(service) {
            formData.brideService = service;
            document.querySelectorAll('.bride-service-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
        }

        function selectTrial(trial) {
            formData.needsTrial = trial;
            
            // Clear all trial button selections first
            document.querySelectorAll('.trial-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
            
            // Add selected class to the clicked button
            event.currentTarget.classList.add('selected');
            
            // Force style update to ensure it takes effect
            setTimeout(() => {
                event.currentTarget.classList.add('selected');
            }, 10);
            
            if (trial === 'Yes') {
                document.getElementById('trial-service-section').style.display = 'block';
                // Restore previously selected trial service if any
                setTimeout(() => {
                    if (formData.trialService) {
                        document.querySelectorAll('.trial-service-btn').forEach(btn => {
                            btn.classList.remove('selected'); // Clear first
                            if (btn.textContent.trim() === formData.trialService) {
                                btn.classList.add('selected');
                            }
                        });
                    }
                }, 50);
            } else {
                document.getElementById('trial-service-section').style.display = 'none';
                // Don't clear formData.trialService - just hide the section
            }
        }

        function selectTrialService(service) {
            formData.trialService = service;
            document.querySelectorAll('.trial-service-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
        }

        function selectJewelry(jewelry) {
            formData.needsJewelry = jewelry;
            document.querySelectorAll('.jewelry-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
        }

        function selectExtensions(extensions) {
            formData.needsExtensions = extensions;
            document.querySelectorAll('.extensions-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
        }

        function selectPartyMembers(hasParty) {
            formData.hasPartyMembers = hasParty;
            document.querySelectorAll('.party-members-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            
            if (hasParty === 'Yes') {
                document.getElementById('party-details-section').style.display = 'block';
                // Restore previously selected airbrush option if any
                setTimeout(() => {
                    if (formData.hasAirbrush) {
                        document.querySelectorAll('.airbrush-btn').forEach(btn => {
                            btn.classList.remove('selected'); // Clear first
                            if (btn.textContent.trim() === formData.hasAirbrush) {
                                btn.classList.add('selected');
                            }
                        });
                        // Show airbrush count section if previously selected "Yes"
                        if (formData.hasAirbrush === 'Yes') {
                            document.getElementById('airbrush-count-section').style.display = 'block';
                        }
                    }
                }, 50);
                
                // Restore input values
                if (formData.partyBothCount > 0) {
                    document.getElementById('party_both_count').value = formData.partyBothCount;
                }
                if (formData.partyMakeupCount > 0) {
                    document.getElementById('party_makeup_count').value = formData.partyMakeupCount;
                }
                if (formData.partyHairCount > 0) {
                    document.getElementById('party_hair_count').value = formData.partyHairCount;
                }
                if (formData.partyDupattaCount > 0) {
                    document.getElementById('party_dupatta_count').value = formData.partyDupattaCount;
                }
                if (formData.partyExtensionsCount > 0) {
                    document.getElementById('party_extensions_count').value = formData.partyExtensionsCount;
                }
                if (formData.airbrushCount > 0) {
                    document.getElementById('airbrush_count').value = formData.airbrushCount;
                }
            } else {
                document.getElementById('party-details-section').style.display = 'none';
                // Don't clear the data - just hide the section
            }
        }

        function selectAirbrush(hasAirbrush) {
            formData.hasAirbrush = hasAirbrush;
            document.querySelectorAll('.airbrush-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            
            if (hasAirbrush === 'Yes') {
                document.getElementById('airbrush-count-section').style.display = 'block';
                // Restore previously entered count if any
                if (formData.airbrushCount > 0) {
                    document.getElementById('airbrush_count').value = formData.airbrushCount;
                }
            } else {
                document.getElementById('airbrush-count-section').style.display = 'none';
                // Don't clear formData.airbrushCount - just hide the section
            }
        }

        function selectSemiBridalService(service) {
            formData.brideService = service;
            document.getElementById('step-4').style.display = 'none';
            loadArtistSelection();
            document.getElementById('step-5').style.display = 'block';
        }

        function selectSemiBridalService(service) {
            formData.brideService = service;
            document.getElementById('step-4').style.display = 'none';
            
            // Restore previously selected add-ons when showing the add-ons step
            setTimeout(() => {
                if (formData.needsJewelry) {
                    document.querySelectorAll('.jewelry-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.needsJewelry) {
                            btn.classList.add('selected');
                        }
                    });
                }
                if (formData.needsExtensions) {
                    document.querySelectorAll('.extensions-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.needsExtensions) {
                            btn.classList.add('selected');
                        }
                    });
                }
            }, 10);
            
            document.getElementById('step-addons').style.display = 'block';
        }

        function selectEveryoneBoth(everyoneBoth) {
            formData.nonBridalEveryoneBoth = everyoneBoth;
            document.querySelectorAll('.everyone-both-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            
            if (everyoneBoth === 'Yes') {
                document.getElementById('breakdown-section').style.display = 'none';
                // Don't clear the breakdown data - just hide the section
            } else {
                document.getElementById('breakdown-section').style.display = 'block';
                // Restore previously entered values if any
                if (formData.nonBridalBothCount > 0) {
                    document.getElementById('non_bridal_both_count').value = formData.nonBridalBothCount;
                }
                if (formData.nonBridalMakeupOnlyCount > 0) {
                    document.getElementById('non_bridal_makeup_count').value = formData.nonBridalMakeupOnlyCount;
                }
                if (formData.nonBridalHairOnlyCount > 0) {
                    document.getElementById('non_bridal_hair_count').value = formData.nonBridalHairOnlyCount;
                }
            }
        }

        function selectNonBridalAirbrush(hasAirbrush) {
            formData.nonBridalHasAirbrush = hasAirbrush;
            document.querySelectorAll('.non-bridal-airbrush-btn').forEach(btn => btn.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            
            if (hasAirbrush === 'Yes') {
                document.getElementById('non-bridal-airbrush-count-section').style.display = 'block';
                // Restore previously entered count if any
                if (formData.nonBridalAirbrushCount > 0) {
                    document.getElementById('non_bridal_airbrush_count').value = formData.nonBridalAirbrushCount;
                }
            } else {
                document.getElementById('non-bridal-airbrush-count-section').style.display = 'none';
                // Don't clear formData.nonBridalAirbrushCount - just hide the section
            }
        }

        function goBackToServiceType() {
            document.getElementById('step-4').style.display = 'none';
            showServiceTypeStep();
        }

        function validateBridalServiceAndProceed() {
            if (!formData.brideService) {
                alert('Please select a bride service.');
                return;
            }
            
            if (!formData.needsTrial) {
                alert('Please select if you need a trial.');
                return;
            }
            
            if (formData.needsTrial === 'Yes' && !formData.trialService) {
                alert('Please select a trial service.');
                return;
            }
            
            document.getElementById('step-4').style.display = 'none';
            document.getElementById('step-addons').style.display = 'block';
            
            // Restore previously selected add-ons when showing the add-ons step
            setTimeout(() => {
                if (formData.needsJewelry) {
                    document.querySelectorAll('.jewelry-btn').forEach(btn => {
                        btn.classList.remove('selected');
                        if (btn.textContent.trim() === formData.needsJewelry) {
                            btn.classList.add('selected');
                        }
                    });
                }
                if (formData.needsExtensions) {
                    document.querySelectorAll('.extensions-btn').forEach(btn => {
                        btn.classList.remove('selected');
                        if (btn.textContent.trim() === formData.needsExtensions) {
                            btn.classList.add('selected');
                        }
                    });
                }
            }, 50);
        }

        function selectSemiBridalService(service) {
            formData.brideService = service;
            document.getElementById('step-4').style.display = 'none';
            document.getElementById('step-addons').style.display = 'block';
        }

        function validateNonBridalCountAndProceed() {
            formData.nonBridalCount = parseInt(document.getElementById('non_bridal_count').value) || 0;
            
            if (formData.nonBridalCount <= 0) {
                alert('Please enter how many people need service (minimum 1).');
                return;
            }
            
            document.getElementById('step-4').style.display = 'none';
            
            // Restore non-bridal breakdown selections when moving to step-4a
            setTimeout(() => {
                // Restore "everyone both" selection
                if (formData.nonBridalEveryoneBoth) {
                    document.querySelectorAll('.everyone-both-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.nonBridalEveryoneBoth) {
                            btn.classList.add('selected');
                        }
                    });
                    
                    // Show breakdown section if "No" was selected
                    if (formData.nonBridalEveryoneBoth === 'No') {
                        document.getElementById('breakdown-section').style.display = 'block';
                        // Restore breakdown input values
                        if (formData.nonBridalBothCount > 0) {
                            document.getElementById('non_bridal_both_count').value = formData.nonBridalBothCount;
                        }
                        if (formData.nonBridalMakeupOnlyCount > 0) {
                            document.getElementById('non_bridal_makeup_count').value = formData.nonBridalMakeupOnlyCount;
                        }
                        if (formData.nonBridalHairOnlyCount > 0) {
                            document.getElementById('non_bridal_hair_count').value = formData.nonBridalHairOnlyCount;
                        }
                    }
                }
                
                // Restore other non-bridal values
                if (formData.nonBridalExtensionsCount > 0) {
                    document.getElementById('non_bridal_extensions_count').value = formData.nonBridalExtensionsCount;
                }
                if (formData.nonBridalJewelryCount > 0) {
                    document.getElementById('non_bridal_jewelry_count').value = formData.nonBridalJewelryCount;
                }
                
                // Restore airbrush selection
                if (formData.nonBridalHasAirbrush) {
                    document.querySelectorAll('.non-bridal-airbrush-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.nonBridalHasAirbrush) {
                            btn.classList.add('selected');
                        }
                    });
                    
                    if (formData.nonBridalHasAirbrush === 'Yes') {
                        document.getElementById('non-bridal-airbrush-count-section').style.display = 'block';
                        if (formData.nonBridalAirbrushCount > 0) {
                            document.getElementById('non_bridal_airbrush_count').value = formData.nonBridalAirbrushCount;
                        }
                    }
                }
            }, 10);
            
            document.getElementById('step-4a').style.display = 'block';
        }

        function goBackToNonBridalCount() {
            document.getElementById('step-4a').style.display = 'none';
            
            // Regenerate the non-bridal form to ensure Next button is present
            loadServiceDetailsForm(formData.serviceType);
            
            // Restore non-bridal count when going back
            setTimeout(() => {
                if (formData.nonBridalCount > 0) {
                    document.getElementById('non_bridal_count').value = formData.nonBridalCount;
                }
            }, 10);
            
            document.getElementById('step-4').style.display = 'block';
        }

        function validateNonBridalBreakdownAndProceed() {
            if (!formData.nonBridalEveryoneBoth) {
                alert('Please select if everyone needs both hair and makeup.');
                return;
            }
            
            if (formData.nonBridalEveryoneBoth === 'No') {
                formData.nonBridalBothCount = parseInt(document.getElementById('non_bridal_both_count').value) || 0;
                formData.nonBridalMakeupOnlyCount = parseInt(document.getElementById('non_bridal_makeup_count').value) || 0;
                formData.nonBridalHairOnlyCount = parseInt(document.getElementById('non_bridal_hair_count').value) || 0;
                
                const totalSelected = formData.nonBridalBothCount + formData.nonBridalMakeupOnlyCount + formData.nonBridalHairOnlyCount;
                if (totalSelected === 0) {
                    alert('Please specify how many people need each service.');
                    return;
                }
            }
            
            formData.nonBridalExtensionsCount = parseInt(document.getElementById('non_bridal_extensions_count').value) || 0;
            formData.nonBridalJewelryCount = parseInt(document.getElementById('non_bridal_jewelry_count').value) || 0;
            
            if (formData.nonBridalHasAirbrush === 'Yes') {
                formData.nonBridalAirbrushCount = parseInt(document.getElementById('non_bridal_airbrush_count').value) || 0;
            }
            
            document.getElementById('step-4a').style.display = 'none';
            
            // For GTA and Outside GTA, go directly to contact
            if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                updateContactFormForRegion();
                document.getElementById('step-6').style.display = 'block';
            } else {
                // For other regions, go to artist selection
                loadArtistSelection();
                document.getElementById('step-5').style.display = 'block';
            }
        }

        function validateAddonsAndProceed() {
            if (!formData.needsJewelry) {
                alert('Please select if you need jewelry & dupatta setting.');
                return;
            }
            
            if (!formData.needsExtensions) {
                alert('Please select if you need hair extensions.');
                return;
            }
            
            document.getElementById('step-addons').style.display = 'none';
            
            // Restore previously selected party options when showing the party step
            setTimeout(() => {
                if (formData.hasPartyMembers) {
                    document.querySelectorAll('.party-members-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.hasPartyMembers) {
                            btn.classList.add('selected');
                        }
                    });
                    // Show party details if previously selected "Yes"
                    if (formData.hasPartyMembers === 'Yes') {
                        document.getElementById('party-details-section').style.display = 'block';
                        // Restore input values
                        if (formData.partyBothCount > 0) {
                            document.getElementById('party_both_count').value = formData.partyBothCount;
                        }
                        if (formData.partyMakeupCount > 0) {
                            document.getElementById('party_makeup_count').value = formData.partyMakeupCount;
                        }
                        if (formData.partyHairCount > 0) {
                            document.getElementById('party_hair_count').value = formData.partyHairCount;
                        }
                        if (formData.partyDupattaCount > 0) {
                            document.getElementById('party_dupatta_count').value = formData.partyDupattaCount;
                        }
                        if (formData.partyExtensionsCount > 0) {
                            document.getElementById('party_extensions_count').value = formData.partyExtensionsCount;
                        }
                        // Restore airbrush selection
                        if (formData.hasAirbrush) {
                            document.querySelectorAll('.airbrush-btn').forEach(btn => {
                                if (btn.textContent.trim() === formData.hasAirbrush) {
                                    btn.classList.add('selected');
                                }
                            });
                            if (formData.hasAirbrush === 'Yes') {
                                document.getElementById('airbrush-count-section').style.display = 'block';
                                if (formData.airbrushCount > 0) {
                                    document.getElementById('airbrush_count').value = formData.airbrushCount;
                                }
                            }
                        }
                    }
                }
            }, 10);
            
            document.getElementById('step-party').style.display = 'block';
        }

        function goBackToAddons() {
            document.getElementById('step-party').style.display = 'none';
            
            // Restore previously selected add-ons when returning to add-ons step
            setTimeout(() => {
                if (formData.needsJewelry) {
                    document.querySelectorAll('.jewelry-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.needsJewelry) {
                            btn.classList.add('selected');
                        }
                    });
                }
                if (formData.needsExtensions) {
                    document.querySelectorAll('.extensions-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.needsExtensions) {
                            btn.classList.add('selected');
                        }
                    });
                }
            }, 10);
            
            document.getElementById('step-addons').style.display = 'block';
        }

        function validatePartyAndProceed() {
            if (!formData.hasPartyMembers) {
                alert('Please select if you have bridal party members.');
                return;
            }
            
            // Collect party counts
            if (formData.hasPartyMembers === 'Yes') {
                formData.partyBothCount = parseInt(document.getElementById('party_both_count').value) || 0;
                formData.partyMakeupCount = parseInt(document.getElementById('party_makeup_count').value) || 0;
                formData.partyHairCount = parseInt(document.getElementById('party_hair_count').value) || 0;
                formData.partyDupattaCount = parseInt(document.getElementById('party_dupatta_count').value) || 0;
                formData.partyExtensionsCount = parseInt(document.getElementById('party_extensions_count').value) || 0;
                
                if (formData.hasAirbrush === 'Yes') {
                    formData.airbrushCount = parseInt(document.getElementById('airbrush_count').value) || 0;
                }
            }
            
            document.getElementById('step-party').style.display = 'none';
            updateContactFormForRegion();
            document.getElementById('step-6').style.display = 'block';
        }

        function updateContactFormForRegion() {
            const nextBtn = document.getElementById('contact-next-btn');
            if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                if (nextBtn) {
                    nextBtn.innerHTML = 'Get a Quote ✓';
                    // Keep the current state (gray if incomplete, green if complete)
                    if (!nextBtn.disabled) {
                        nextBtn.style.background = '#28a745';
                    }
                }
            } else {
                if (nextBtn) {
                    nextBtn.innerHTML = 'Next →';
                    // Keep the current state (gray if incomplete, black if complete)
                    if (!nextBtn.disabled) {
                        nextBtn.style.background = 'black';
                    }
                }
            }
        }

        function loadArtistSelection() {
            const anumQuote = getDetailedQuoteForArtist('By Anum');
            const teamQuote = getDetailedQuoteForArtist('By Team');
            
            // Different back button logic based on service type
            let backButtonFunction = '';
            let nextButtonFunction = '';
            
            if (formData.serviceType === 'Non-Bridal / Photoshoot') {
                backButtonFunction = 'goBackToNonBridalBreakdown()';
                nextButtonFunction = 'proceedToContact()';
            } else {
                // Bridal/Semi Bridal
                backButtonFunction = 'goBackToContact()';
                nextButtonFunction = 'completeArtistSelection()';
            }
            
            document.getElementById('artist-quotes').innerHTML = `
                <div class="artist-option" data-artist="By Anum" onclick="selectArtist('By Anum')" 
                     style="border: 2px solid black; border-radius: 8px; padding: 20px; cursor: pointer; background: white;">
                    <h3 style="font-size: 20px; font-weight: bold; text-align: center; margin-bottom: 15px; color: black;">Anum's Quote</h3>
                    <hr style="margin-bottom: 15px; border: 1px solid #ccc;">
                    <div style="font-size: 14px; margin-bottom: 15px;">
                        ${anumQuote.quote.map(item => `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #333; flex: 1; padding-right: 10px;">${item.description}</span>
                                <span style="color: black; font-weight: bold;">${item.price} CAD</span>
                            </div>
                        `).join('')}
                        <hr style="margin: 10px 0; border: 1px solid #ccc;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: bold; color: black;">Subtotal:</span>
                            <span style="font-weight: bold; color: black;">${anumQuote.subtotal.toFixed(2)} CAD</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 16px;">
                            <span style="font-weight: bold; color: black;">Total (with 13% HST):</span>
                            <span style="font-weight: bold; color: #e91e63; font-size: 18px;">${anumQuote.total.toFixed(2)} CAD</span>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 15px;">
                        <div style="font-weight: bold; color: black;">Select Anum</div>
                        <div style="font-size: 12px; color: #666; margin-top: 5px;">Premium service by Anum herself</div>
                    </div>
                </div>

                <div class="artist-option" data-artist="By Team" onclick="selectArtist('By Team')" 
                     style="border: 2px solid black; border-radius: 8px; padding: 20px; cursor: pointer; background: white;">
                    <h3 style="font-size: 20px; font-weight: bold; text-align: center; margin-bottom: 15px; color: black;">Team Quote</h3>
                    <hr style="margin-bottom: 15px; border: 1px solid #ccc;">
                    <div style="font-size: 14px; margin-bottom: 15px;">
                        ${teamQuote.quote.map(item => `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #333; flex: 1; padding-right: 10px;">${item.description}</span>
                                <span style="color: black; font-weight: bold;">${item.price} CAD</span>
                            </div>
                        `).join('')}
                        <hr style="margin: 10px 0; border: 1px solid #ccc;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: bold; color: black;">Subtotal:</span>
                            <span style="font-weight: bold; color: black;">${teamQuote.subtotal.toFixed(2)} CAD</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 16px;">
                            <span style="font-weight: bold; color: black;">Total (with 13% HST):</span>
                            <span style="font-weight: bold; color: #e91e63; font-size: 18px;">${teamQuote.total.toFixed(2)} CAD</span>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 15px;">
                        <div style="font-weight: bold; color: black;">Select Team</div>
                        <div style="font-size: 12px; color: #666; margin-top: 5px;">Professional service by trained team members</div>
                    </div>
                </div>
            `;
            
            // Update the back and next buttons
            const step5 = document.getElementById('step-5');
            step5.innerHTML = `
                <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                    Choose Your Artist
                </h2>
                
                <div id="artist-quotes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                    ${document.getElementById('artist-quotes').innerHTML}
                </div>
                
                <div style="display: flex; gap: 15px;">
                    <button type="button" onclick="${backButtonFunction}" style="flex: 1; padding: 15px; background: white; border: 2px solid black; border-radius: 6px; cursor: pointer; font-size: 16px; color: black;">
                        ← Back
                    </button>
                    <button type="button" onclick="${nextButtonFunction}" style="flex: 1; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                        Next →
                    </button>
                </div>
            `;
        }

        function goBackToNonBridalBreakdown() {
            document.getElementById('step-5').style.display = 'none';
            
            // Restore non-bridal breakdown selections when going back
            setTimeout(() => {
                // Restore "everyone both" selection
                if (formData.nonBridalEveryoneBoth) {
                    document.querySelectorAll('.everyone-both-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.nonBridalEveryoneBoth) {
                            btn.classList.add('selected');
                        }
                    });
                    
                    // Show breakdown section if "No" was selected
                    if (formData.nonBridalEveryoneBoth === 'No') {
                        document.getElementById('breakdown-section').style.display = 'block';
                        // Restore breakdown input values
                        if (formData.nonBridalBothCount > 0) {
                            document.getElementById('non_bridal_both_count').value = formData.nonBridalBothCount;
                        }
                        if (formData.nonBridalMakeupOnlyCount > 0) {
                            document.getElementById('non_bridal_makeup_count').value = formData.nonBridalMakeupOnlyCount;
                        }
                        if (formData.nonBridalHairOnlyCount > 0) {
                            document.getElementById('non_bridal_hair_count').value = formData.nonBridalHairOnlyCount;
                        }
                    }
                }
                
                // Restore other non-bridal values
                if (formData.nonBridalExtensionsCount > 0) {
                    document.getElementById('non_bridal_extensions_count').value = formData.nonBridalExtensionsCount;
                }
                if (formData.nonBridalJewelryCount > 0) {
                    document.getElementById('non_bridal_jewelry_count').value = formData.nonBridalJewelryCount;
                }
                
                // Restore airbrush selection
                if (formData.nonBridalHasAirbrush) {
                    document.querySelectorAll('.non-bridal-airbrush-btn').forEach(btn => {
                        if (btn.textContent.trim() === formData.nonBridalHasAirbrush) {
                            btn.classList.add('selected');
                        }
                    });
                    
                    if (formData.nonBridalHasAirbrush === 'Yes') {
                        document.getElementById('non-bridal-airbrush-count-section').style.display = 'block';
                        if (formData.nonBridalAirbrushCount > 0) {
                            document.getElementById('non_bridal_airbrush_count').value = formData.nonBridalAirbrushCount;
                        }
                    }
                }
            }, 10);
            
            document.getElementById('step-4a').style.display = 'block';
        }

        function goBackToContact() {
            document.getElementById('step-5').style.display = 'none';
            document.getElementById('step-6').style.display = 'block';
        }

        function selectArtist(artist) {
            formData.artist = artist;
            document.querySelectorAll('.artist-option').forEach(option => option.classList.remove('selected'));
            document.querySelector(`[data-artist="${artist}"]`).classList.add('selected');
        }

        function goBackToServiceDetails() {
            if (formData.serviceType === 'Bridal' || formData.serviceType === 'Semi Bridal') {
                document.getElementById('step-addons').style.display = 'none';
                
                // Regenerate the service details form to ensure Next button is present
                loadServiceDetailsForm(formData.serviceType);
                
                document.getElementById('step-4').style.display = 'block';
                
                // Restore service details selections when going back to step-4
                setTimeout(() => {
                    // Restore bride service selection
                    if (formData.brideService) {
                        document.querySelectorAll('.bride-service-btn, .semi-service-btn').forEach(btn => {
                            btn.classList.remove('selected');
                            if (btn.textContent.trim() === formData.brideService) {
                                btn.classList.add('selected');
                            }
                        });
                    }
                    
                    // For Bridal service type, restore trial selections
                    if (formData.serviceType === 'Bridal') {
                        if (formData.needsTrial) {
                            document.querySelectorAll('.trial-btn').forEach(btn => {
                                btn.classList.remove('selected');
                                const btnText = btn.querySelector('div').textContent.trim();
                                if (btnText === formData.needsTrial) {
                                    btn.classList.add('selected');
                                    // Force style application for trial buttons
                                    setTimeout(() => {
                                        btn.classList.add('selected');
                                    }, 10);
                                }
                            });
                            
                            // Show trial service section if "Yes" was selected
                            if (formData.needsTrial === 'Yes') {
                                document.getElementById('trial-service-section').style.display = 'block';
                                
                                // Restore trial service selection
                                if (formData.trialService) {
                                    setTimeout(() => {
                                        document.querySelectorAll('.trial-service-btn').forEach(btn => {
                                            btn.classList.remove('selected');
                                            if (btn.textContent.trim() === formData.trialService) {
                                                btn.classList.add('selected');
                                            }
                                        });
                                    }, 50);
                                }
                            }
                        }
                    }
                }, 50);
            } else {
                document.getElementById('step-5').style.display = 'none';
                document.getElementById('step-4a').style.display = 'block';
            }
        }

        function proceedToContact() {
            if (!formData.artist) {
                alert('Please select an artist.');
                return;
            }
            
            document.getElementById('step-5').style.display = 'none';
            
            if (formData.serviceType === 'Non-Bridal / Photoshoot') {
                // Non-bridal goes to contact info collection
                document.getElementById('step-6').style.display = 'block';
            } else {
                // This should not happen for Bridal/Semi-bridal
                alert('Error in navigation flow');
            }
        }

        function goBackToContactPrevious() {
            document.getElementById('step-6').style.display = 'none';
            
            // For GTA and Outside GTA regions
            if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                if (formData.serviceType === 'Non-Bridal / Photoshoot') {
                    // Non-bridal: go back to breakdown step
                    document.getElementById('step-4a').style.display = 'block';
                } else {
                    // Bridal/Semi-bridal: go back to bridal party step
                    document.getElementById('step-party').style.display = 'block';
                }
            } else {
                // For other regions, use original logic
                if (formData.serviceType === 'Non-Bridal / Photoshoot') {
                    // Non-bridal: go back to artist selection
                    document.getElementById('step-5').style.display = 'block';
                } else {
                    // Bridal/Semi-bridal: go back to bridal party step
                    document.getElementById('step-party').style.display = 'block';
                }
            }
        }

        function validateContactAndProceedNext() {
    // Start loading animation immediately on click
    const submitBtn = document.getElementById('contact-next-btn');
    if (submitBtn) {
        submitBtn.disabled = true;
        
        // Start animated dots
        let dots = '';
        let dotCount = 0;
        
        const updateDots = () => {
            dotCount = (dotCount + 1) % 4; // 0, 1, 2, 3
            dots = '.'.repeat(dotCount);
            submitBtn.innerHTML = 'Generating Quote' + dots;
        };
        
        updateDots(); // Start immediately
        window.loadingInterval = setInterval(updateDots, 600);
    }
    
    // Collect form data
    formData.firstName = document.getElementById('first_name').value.trim();
    formData.lastName = document.getElementById('last_name').value.trim();
    formData.email = document.getElementById('email').value.trim();
    formData.phone = document.getElementById('phone').value.trim();

    // Validate form data
    if (!formData.firstName) {
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.disabled = false;
        alert('Please enter your first name.');
        return;
    }

    if (!formData.lastName) {
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.disabled = false;
        alert('Please enter your last name.');
        return;
    }

    if (!formData.email) {
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.disabled = false;
        alert('Please enter your email address.');
        return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.disabled = false;
        alert('Please enter a valid email address.');
        return;
    }

    if (!formData.phone) {
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.disabled = false;
        alert('Please enter your phone number.');
        return;
    }

    if (!validateCanadianPhone(formData.phone)) {
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.disabled = false;
        alert('Please enter a valid Canadian phone number.');
        return;
    }

    // For GTA and Outside GTA options, submit directly without artist selection
    if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
        // Call submitBooking but don't start animation again since it's already running
        submitBookingWithoutAnimation();
    } else {
        // Clear animation and proceed with original flow for other regions
        clearInterval(window.loadingInterval);
        submitBtn.innerHTML = 'Next →';
        submitBtn.disabled = false;
        
        // Keep original flow for other regions (like destination weddings)
        document.getElementById('step-6').style.display = 'none';
        
        if (formData.serviceType === 'Non-Bridal / Photoshoot') {
            // Non-bridal: go to summary
            showSummary();
            document.getElementById('step-7').style.display = 'block';
        } else {
            // Bridal/Semi-bridal: go to artist selection
            loadArtistSelection();
            document.getElementById('step-5').style.display = 'block';
        }
    }
}

// Modified submitBooking function that doesn't start its own animation
function submitBookingWithoutAnimation() {
    const finalRegion = formData.subRegion || formData.region;
    const isDestination = formData.region === 'Destination Wedding';
    
    let totalPrice = 0;
    if (!isDestination) {
        // For GTA and Outside GTA, set default artist if not selected
        if ((formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') && !formData.artist) {
            formData.artist = 'By Team'; // Default artist for GTA/Outside GTA
        }
        
        if (formData.artist) {
            const { total } = getDetailedQuoteForArtist(formData.artist);
            totalPrice = total;
        }
    }

    const formDataToSubmit = new FormData();
    formDataToSubmit.append('action', 'submit_bridal_form');
    formDataToSubmit.append('bridal_nonce', '<?php echo wp_create_nonce('bridal_form_nonce'); ?>');
    formDataToSubmit.append('region', finalRegion);
    formDataToSubmit.append('first_name', formData.firstName);
    formDataToSubmit.append('last_name', formData.lastName);
    formDataToSubmit.append('email', formData.email);
    formDataToSubmit.append('phone', formData.phone);
    formDataToSubmit.append('price', totalPrice);
    
    // Add service details for regular bookings
    if (!isDestination) {
        formDataToSubmit.append('event_date', formData.eventDate);
        formDataToSubmit.append('ready_time', formData.readyTime);
        formDataToSubmit.append('service_type', formData.serviceType);
        formDataToSubmit.append('artist', formData.artist);
        
        // Add detailed service information
        if (formData.serviceType === 'Bridal' || formData.serviceType === 'Semi Bridal') {
            formDataToSubmit.append('bride_service', formData.brideService);
            formDataToSubmit.append('needs_trial', formData.needsTrial);
            formDataToSubmit.append('trial_service', formData.trialService);
            formDataToSubmit.append('needs_jewelry', formData.needsJewelry);
            formDataToSubmit.append('needs_extensions', formData.needsExtensions);
            formDataToSubmit.append('has_party_members', formData.hasPartyMembers);
            formDataToSubmit.append('party_both_count', formData.partyBothCount);
            formDataToSubmit.append('party_makeup_count', formData.partyMakeupCount);
            formDataToSubmit.append('party_hair_count', formData.partyHairCount);
            formDataToSubmit.append('party_dupatta_count', formData.partyDupattaCount);
            formDataToSubmit.append('party_extensions_count', formData.partyExtensionsCount);
            formDataToSubmit.append('has_airbrush', formData.hasAirbrush);
            formDataToSubmit.append('airbrush_count', formData.airbrushCount);
        } else if (formData.serviceType === 'Non-Bridal / Photoshoot') {
            formDataToSubmit.append('non_bridal_count', formData.nonBridalCount);
            formDataToSubmit.append('non_bridal_everyone_both', formData.nonBridalEveryoneBoth);
            formDataToSubmit.append('non_bridal_both_count', formData.nonBridalBothCount);
            formDataToSubmit.append('non_bridal_makeup_count', formData.nonBridalMakeupOnlyCount);
            formDataToSubmit.append('non_bridal_hair_count', formData.nonBridalHairOnlyCount);
            formDataToSubmit.append('non_bridal_extensions_count', formData.nonBridalExtensionsCount);
            formDataToSubmit.append('non_bridal_jewelry_count', formData.nonBridalJewelryCount);
            formDataToSubmit.append('non_bridal_has_airbrush', formData.nonBridalHasAirbrush);
            formDataToSubmit.append('non_bridal_airbrush_count', formData.nonBridalAirbrushCount);
        }
    }
    
    // Add event dates for destination wedding
    if (isDestination) {
        formDataToSubmit.append('event_start_date', formData.startDate);
        formDataToSubmit.append('event_end_date', formData.endDate);
        formDataToSubmit.append('destination_details', formData.destinationDetails);
    }

    // Use the correct WordPress AJAX URL
    const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formDataToSubmit,
        credentials: 'same-origin'
    })
    .then(response => {
        clearInterval(window.loadingInterval); // Stop animation
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        const submitBtn = document.getElementById('contact-next-btn');
        
        if (data.success) {
            // Success handling
            if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                if (data.data && data.data.redirect_url) {
                    submitBtn.innerHTML = 'Quote Generated ✓';
                    submitBtn.style.background = '#28a745';
                    
                    document.getElementById('step-6').innerHTML = `
                        <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                            Thank You! Redirecting to Your Quote...
                        </h2>
                        <div style="color: black; padding: 20px; background: #f0f0f0; border-radius: 6px; border: 2px solid black; text-align: center; margin-bottom: 20px;">
                            <p style="font-size: 18px; margin-bottom: 10px;">✅ Quote generated successfully</p>
                            <p style="font-size: 16px; margin-bottom: 10px;">📧 Confirmation email sent to your inbox</p>
                            <p style="font-size: 14px; color: #666;">You will be redirected to view your personalized quote in 3 seconds...</p>
                        </div>
                    `;
                    
                    setTimeout(() => {
                        window.location.href = data.data.redirect_url;
                    }, 3000);
                } else {
                    submitBtn.innerHTML = 'Quote Generated ✓';
                    submitBtn.style.background = '#28a745';
                    
                    document.getElementById('step-6').innerHTML = `
                        <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                            Booking Submitted Successfully!
                        </h2>
                        <div style="color: black; padding: 20px; background: #f0f0f0; border-radius: 6px; border: 2px solid black; text-align: center; margin-bottom: 20px;">
                            <p style="font-size: 18px; margin-bottom: 10px;">Thank you for your booking!</p>
                            <p>We will contact you soon to confirm your appointment.</p>
                            <p>Check your email for your personalized quote!</p>
                        </div>
                        <button type="button" onclick="startOver()" style="width: 100%; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                            Submit Another Quote
                        </button>
                    `;
                }
            }
        } else {
            // Error handling
            alert('Error: ' + data.data);
            submitBtn.innerHTML = 'Get a Quote ✓';
            submitBtn.style.background = '#dc3545';
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        clearInterval(window.loadingInterval); // Stop animation on error
        console.error('Fetch error:', error);
        
        const submitBtn = document.getElementById('contact-next-btn');
        alert('Network error. Please check your internet connection and try again.');
        submitBtn.innerHTML = 'Get a Quote ✓';
        submitBtn.style.background = '#dc3545';
        submitBtn.disabled = false;
    });
}

        // New function for artist completion in Bridal/Semi Bridal flow
        function completeArtistSelection() {
            if (!formData.artist) {
                alert('Please select an artist.');
                return;
            }
            
            document.getElementById('step-5').style.display = 'none';
            showSummary();
            document.getElementById('step-7').style.display = 'block';
        }

        function showSummary() {
            const finalRegion = formData.subRegion || formData.region;
            const { total } = getDetailedQuoteForArtist(formData.artist);

            // For Non-Bridal, contact info is not collected yet, only artist is selected
            const contactInfo = (formData.serviceType !== 'Non-Bridal / Photoshoot') ? `
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Client:</span>
                    <span style="color: black;">${formData.firstName} ${formData.lastName}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Email:</span>
                    <span style="color: black;">${formData.email}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Phone:</span>
                    <span style="color: black;">${formData.phone}</span>
                </div>
            ` : '';

            document.getElementById('booking-summary').innerHTML = `
                ${contactInfo}
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Service Region:</span>
                    <span style="color: black;">${finalRegion}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Event Date:</span>
                    <span style="color: black;">${new Date(formData.eventDate).toLocaleDateString()}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Ready Time:</span>
                    <span style="color: black;">${formData.readyTime}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Service Type:</span>
                    <span style="color: black;">${formData.serviceType}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Artist:</span>
                    <span style="color: black;">${formData.artist}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 15px; padding-top: 15px; border-top: 2px solid black; font-size: 18px; font-weight: bold; color: black;">
                    <span>Total Service Fee:</span>
                    <span style="color: black;">${total.toFixed(2)} CAD</span>
                </div>
                <p style="text-align: center; margin-top: 10px; font-size: 12px; color: #666;">
                    (Includes 13% HST)
                </p>
            `;
        }

        function goBackToEdit() {
            if (formData.serviceType === 'Non-Bridal / Photoshoot') {
                // Non-bridal: go back to artist selection
                document.getElementById('step-7').style.display = 'none';
                document.getElementById('step-5').style.display = 'block';
            } else {
                // Bridal/Semi Bridal: go back to artist selection
                document.getElementById('step-7').style.display = 'none';
                document.getElementById('step-5').style.display = 'block';
            }
        }

        // Destination Wedding Flow Functions
        function goBackToRegionsFromDates() {
            document.getElementById('step-1-5').style.display = 'none';
            document.getElementById('step-1').style.display = 'block';
        }

        function validateDatesAndProceed() {
            const startDate = document.getElementById('event_start_date').value;
            const endDate = document.getElementById('event_end_date').value;
            const today = new Date().toISOString().split('T')[0];

            if (!startDate) {
                alert('Please select an event starting date.');
                return;
            }

            if (!endDate) {
                alert('Please select an event ending date.');
                return;
            }

            if (startDate < today) {
                alert('Event starting date cannot be in the past.');
                return;
            }

            if (endDate < today) {
                alert('Event ending date cannot be in the past.');
                return;
            }

            if (endDate <= startDate) {
                alert('Event ending date must be after the event starting date. Please select a valid date range for your destination wedding.');
                return;
            }

            formData.startDate = startDate;
            formData.endDate = endDate;

            document.getElementById('step-1-5').style.display = 'none';
            document.getElementById('step-1-6').style.display = 'block';
        }

        function goBackToEventDates() {
            document.getElementById('step-1-6').style.display = 'none';
            document.getElementById('step-1-5').style.display = 'block';
        }

        function proceedToUserDetails() {
            formData.destinationDetails = document.getElementById('destination_details').value.trim();
            
            if (!formData.destinationDetails) {
                alert('Please provide additional details about your destination wedding.');
                return;
            }
            
            document.getElementById('step-1-6').style.display = 'none';
            document.getElementById('step-dest-contact').style.display = 'block';
        }

        function goBackToDestDetails() {
            document.getElementById('step-dest-contact').style.display = 'none';
            document.getElementById('step-1-6').style.display = 'block';
        }

        function validateDestContactAndProceed() {
            const firstName = document.getElementById('dest_first_name').value.trim();
            const lastName = document.getElementById('dest_last_name').value.trim();
            const email = document.getElementById('dest_email').value.trim();
            const phone = document.getElementById('dest_phone').value.trim();

            if (!firstName) {
                alert('Please enter your first name.');
                return;
            }

            if (!lastName) {
                alert('Please enter your last name.');
                return;
            }

            if (!email) {
                alert('Please enter your email address.');
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address.');
                return;
            }

            if (!phone) {
                alert('Please enter your phone number.');
                return;
            }

            formData.firstName = firstName;
            formData.lastName = lastName;
            formData.email = email;
            formData.phone = phone;

            document.getElementById('step-dest-contact').style.display = 'none';
            document.getElementById('step-2-5').style.display = 'block';
        }

        function goBackToDestContact() {
            document.getElementById('step-2-5').style.display = 'none';
            document.getElementById('step-dest-contact').style.display = 'block';
        }

        function proceedToDestSummary() {
            document.getElementById('step-2-5').style.display = 'none';
            showDestinationSummary();
            document.getElementById('step-7').style.display = 'block';
        }

        function showDestinationSummary() {
            let eventDatesRows = '';
            if (formData.startDate && formData.endDate) {
                eventDatesRows = `
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                        <span style="font-weight: bold; color: black;">Event Start Date:</span>
                        <span style="color: black;">${new Date(formData.startDate).toLocaleDateString()}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                        <span style="font-weight: bold; color: black;">Event End Date:</span>
                        <span style="color: black;">${new Date(formData.endDate).toLocaleDateString()}</span>
                    </div>
                `;
            }

            let destinationDetailsRow = '';
            if (formData.destinationDetails) {
                destinationDetailsRow = `
                    <div style="margin-bottom: 10px; color: black;">
                        <div style="font-weight: bold; color: black; margin-bottom: 5px;">Destination Wedding Details:</div>
                        <div style="color: black; padding: 10px; background: white; border-radius: 4px; border: 1px solid #ccc;">${formData.destinationDetails}</div>
                    </div>
                `;
            }

            document.getElementById('booking-summary').innerHTML = `
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Client:</span>
                    <span style="color: black;">${formData.firstName} ${formData.lastName}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Email:</span>
                    <span style="color: black;">${formData.email}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Phone:</span>
                    <span style="color: black;">${formData.phone}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: black;">
                    <span style="font-weight: bold; color: black;">Service Region:</span>
                    <span style="color: black;">${formData.region}</span>
                </div>
                ${eventDatesRows}
                ${destinationDetailsRow}
                <div style="display: flex; justify-content: space-between; margin-top: 15px; padding-top: 15px; border-top: 2px solid black; font-size: 18px; font-weight: bold; color: black;">
                    <span>Service Fee:</span>
                    <span style="color: black;">Custom Quote</span>
                </div>
                <p style="text-align: center; margin-top: 10px; font-size: 12px; color: #666;">
                    We'll contact you with a custom quote for destination wedding services.
                </p>
            `;
        }

        function submitBooking() {
    const submitBtn = document.getElementById('submit-btn') || document.getElementById('contact-next-btn');
    submitBtn.disabled = true;

    // Start animated loading with dots
    let dotCount = 0;
    const baseText = 'Generating Quote';
    const maxDots = 3;
    
    const animateLoading = () => {
        dotCount = (dotCount % maxDots) + 1;
        submitBtn.innerHTML = baseText + '.'.repeat(dotCount);
    };
    
    // Start the animation
    animateLoading();
    const loadingInterval = setInterval(animateLoading, 500); // Update every 500ms

    const finalRegion = formData.subRegion || formData.region;
    const isDestination = formData.region === 'Destination Wedding';
    
    let totalPrice = 0;
    if (!isDestination) {
        // For GTA and Outside GTA, set default artist if not selected
        if ((formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') && !formData.artist) {
            formData.artist = 'By Team'; // Default artist for GTA/Outside GTA
        }
        
        if (formData.artist) {
            const { total } = getDetailedQuoteForArtist(formData.artist);
            totalPrice = total;
        }
    }

    const formDataToSubmit = new FormData();
    formDataToSubmit.append('action', 'submit_bridal_form');
    formDataToSubmit.append('bridal_nonce', '<?php echo wp_create_nonce('bridal_form_nonce'); ?>');
    formDataToSubmit.append('region', finalRegion);
    formDataToSubmit.append('first_name', formData.firstName);
    formDataToSubmit.append('last_name', formData.lastName);
    formDataToSubmit.append('email', formData.email);
    formDataToSubmit.append('phone', formData.phone);
    formDataToSubmit.append('price', totalPrice);
    
    // Add service details for regular bookings
    if (!isDestination) {
        formDataToSubmit.append('event_date', formData.eventDate);
        formDataToSubmit.append('ready_time', formData.readyTime);
        formDataToSubmit.append('service_type', formData.serviceType);
        formDataToSubmit.append('artist', formData.artist);
        
        // Add detailed service information
        if (formData.serviceType === 'Bridal' || formData.serviceType === 'Semi Bridal') {
            formDataToSubmit.append('bride_service', formData.brideService);
            formDataToSubmit.append('needs_trial', formData.needsTrial);
            formDataToSubmit.append('trial_service', formData.trialService);
            formDataToSubmit.append('needs_jewelry', formData.needsJewelry);
            formDataToSubmit.append('needs_extensions', formData.needsExtensions);
            formDataToSubmit.append('has_party_members', formData.hasPartyMembers);
            formDataToSubmit.append('party_both_count', formData.partyBothCount);
            formDataToSubmit.append('party_makeup_count', formData.partyMakeupCount);
            formDataToSubmit.append('party_hair_count', formData.partyHairCount);
            formDataToSubmit.append('party_dupatta_count', formData.partyDupattaCount);
            formDataToSubmit.append('party_extensions_count', formData.partyExtensionsCount);
            formDataToSubmit.append('has_airbrush', formData.hasAirbrush);
            formDataToSubmit.append('airbrush_count', formData.airbrushCount);
        } else if (formData.serviceType === 'Non-Bridal / Photoshoot') {
            formDataToSubmit.append('non_bridal_count', formData.nonBridalCount);
            formDataToSubmit.append('non_bridal_everyone_both', formData.nonBridalEveryoneBoth);
            formDataToSubmit.append('non_bridal_both_count', formData.nonBridalBothCount);
            formDataToSubmit.append('non_bridal_makeup_count', formData.nonBridalMakeupOnlyCount);
            formDataToSubmit.append('non_bridal_hair_count', formData.nonBridalHairOnlyCount);
            formDataToSubmit.append('non_bridal_extensions_count', formData.nonBridalExtensionsCount);
            formDataToSubmit.append('non_bridal_jewelry_count', formData.nonBridalJewelryCount);
            formDataToSubmit.append('non_bridal_has_airbrush', formData.nonBridalHasAirbrush);
            formDataToSubmit.append('non_bridal_airbrush_count', formData.nonBridalAirbrushCount);
        }
    }
    
    // Add event dates for destination wedding
    if (isDestination) {
        formDataToSubmit.append('event_start_date', formData.startDate);
        formDataToSubmit.append('event_end_date', formData.endDate);
        formDataToSubmit.append('destination_details', formData.destinationDetails);
    }

    // Use the correct WordPress AJAX URL
    const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formDataToSubmit,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        // Clear the loading animation
        clearInterval(loadingInterval);
        
        if (data.success) {
            // For GTA/Outside GTA, show success message in current step
            if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                // Check if there's a redirect URL for non-destination weddings
                if (data.data && data.data.redirect_url) {
                    // Show success message before redirect
                    submitBtn.innerHTML = 'Quote Generated ✓';
                    submitBtn.style.background = '#28a745';
                    
                    // Show loading message before redirect
                    document.getElementById('step-6').innerHTML = `
                        <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                            Thank You! Redirecting to Your Quote...
                        </h2>
                        <div style="color: black; padding: 20px; background: #f0f0f0; border-radius: 6px; border: 2px solid black; text-align: center; margin-bottom: 20px;">
                            <p style="font-size: 18px; margin-bottom: 10px;">✅  Generating your quotation</p>
                            <p style="font-size: 16px; margin-bottom: 10px;">📧 Confirmation email sent to your inbox</p>
                            <p style="font-size: 14px; color: #666;">You will be redirected to view your personalized quote in 3 seconds...</p>
                        </div>
                    `;
                    
                    // Redirect after 3 seconds
                    setTimeout(() => {
                        window.location.href = data.data.redirect_url;
                    }, 3000);
                } else {
                    // Replace the form content with success message
                    submitBtn.innerHTML = 'Quote Generated ✓';
                    submitBtn.style.background = '#28a745';
                    
                    document.getElementById('step-6').innerHTML = `
                        <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 30px; text-align: center; color: black;">
                            Booking Submitted Successfully!
                        </h2>
                        <div style="color: black; padding: 20px; background: #f0f0f0; border-radius: 6px; border: 2px solid black; text-align: center; margin-bottom: 20px;">
                            <p style="font-size: 18px; margin-bottom: 10px;">Thank you for your booking!</p>
                            <p>We will contact you soon to confirm your appointment.</p>
                            <p>Check your email for your personalized quote!</p>
                        </div>
                        <button type="button" onclick="startOver()" style="width: 100%; padding: 15px; background: black; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px;">
                            Submit Another quotation
                        </button>
                    `;
                }
            } else {
                // For other regions, use the original flow
                const messageDiv = document.getElementById('form-message');
                messageDiv.innerHTML = '<div style="color: black; padding: 15px; background: #f0f0f0; border-radius: 6px; border: 2px solid black;">' + data.data + '</div>';
                setTimeout(() => {
                    startOver();
                }, 3000);
            }
        } else {
            // For GTA/Outside GTA, show error in current step
            if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
                alert('Error: ' + data.data);
                submitBtn.innerHTML = 'Get a Quote ✓';
                submitBtn.style.background = '#28a745';
                submitBtn.disabled = false;
            } else {
                const messageDiv = document.getElementById('form-message');
                messageDiv.innerHTML = '<div style="color: black; padding: 15px; background: #f8f8f8; border-radius: 6px; border: 2px solid black;">Error: ' + data.data + '</div>';
                submitBtn.innerHTML = 'Confirm Booking ✓';
                submitBtn.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        // Clear the loading animation
        clearInterval(loadingInterval);
        
        if (formData.region === 'Toronto/GTA' || formData.region === 'Outside GTA') {
            alert('Network error. Please check your internet connection and try again. Error: ' + error.message);
            submitBtn.innerHTML = 'Get a Quote ✓';
            submitBtn.style.background = '#28a745';
            submitBtn.disabled = false;
        } else {
            document.getElementById('form-message').innerHTML = '<div style="color: black; padding: 15px; background: #f8f8f8; border-radius: 6px; border: 2px solid black;">Network error. Please check your internet connection and try again.</div>';
            submitBtn.innerHTML = 'Confirm Booking ✓';
            submitBtn.disabled = false;
        }
    });
}
        function startOver() {
            // Reset all form data
            formData = {
                region: '',
                subRegion: '',
                eventDate: '',
                readyTime: '',
                serviceType: '',
                artist: '',
                brideService: '',
                needsTrial: '',
                trialService: '',
                needsJewelry: '',
                needsExtensions: '',
                hasPartyMembers: '',
                partyBothCount: 0,
                partyMakeupCount: 0,
                partyHairCount: 0,
                partyDupattaCount: 0,
                partyExtensionsCount: 0,
                hasAirbrush: '',
                airbrushCount: 0,
                nonBridalCount: 0,
                nonBridalEveryoneBoth: '',
                nonBridalBothCount: 0,
                nonBridalMakeupOnlyCount: 0,
                nonBridalHairOnlyCount: 0,
                nonBridalExtensionsCount: 0,
                nonBridalJewelryCount: 0,
                nonBridalHasAirbrush: '',
                nonBridalAirbrushCount: 0,
                firstName: '',
                lastName: '',
                email: '',
                phone: '',
                startDate: '',
                endDate: '',
                destinationDetails: ''
            };
            
            // Reset all forms
            document.querySelectorAll('form').forEach(form => form.reset());
            document.getElementById('form-message').innerHTML = '';
            
            // Clear all visual selections including artist buttons
            document.querySelectorAll('.selected').forEach(element => element.classList.remove('selected'));
            
            // Reset artist selection if it exists
            const anumBtn = document.getElementById('artist-anum-btn');
            const teamBtn = document.getElementById('artist-team-btn');
            if (anumBtn) {
                anumBtn.style.borderColor = '#333';
                anumBtn.style.backgroundColor = 'white';
                anumBtn.style.color = 'black';
            }
            if (teamBtn) {
                teamBtn.style.borderColor = '#333';
                teamBtn.style.backgroundColor = 'white';
                teamBtn.style.color = 'black';
            }
            
            // Hide quote container
            const quoteContainer = document.getElementById('selected-quote-container');
            if (quoteContainer) quoteContainer.style.display = 'none';
            
            // Disable next button
            const nextBtn = document.getElementById('artist-next-btn');
            if (nextBtn) {
                nextBtn.style.backgroundColor = '#ccc';
                nextBtn.style.color = '#666';
                nextBtn.style.cursor = 'not-allowed';
                nextBtn.disabled = true;
            }
            
            // Hide sub-regions and buttons
            document.getElementById('sub-regions').style.display = 'none';
            document.getElementById('next-btn').style.display = 'none';
            
            // Hide all steps except step 1
            document.querySelectorAll('.form-step').forEach(step => step.style.display = 'none');
            document.getElementById('step-1').style.display = 'block';
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    public function handle_form_submission() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['bridal_nonce'], 'bridal_form_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Get and sanitize form data
        $region = sanitize_text_field($_POST['region']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $price = floatval($_POST['price']);
        
        // Generate unique ID for this submission
        $unique_id = 'BB' . date('Y') . substr(uniqid(), -6);
        
        // Get event dates and destination details if they exist (for destination wedding)
        $event_start_date = isset($_POST['event_start_date']) ? sanitize_text_field($_POST['event_start_date']) : null;
        $event_end_date = isset($_POST['event_end_date']) ? sanitize_text_field($_POST['event_end_date']) : null;
        $destination_details = isset($_POST['destination_details']) ? sanitize_textarea_field($_POST['destination_details']) : null;
        
        // Get regular booking details
        $event_date = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : null;
        $ready_time = isset($_POST['ready_time']) ? sanitize_text_field($_POST['ready_time']) : null;
        $service_type = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : null;
        $artist = isset($_POST['artist']) ? sanitize_text_field($_POST['artist']) : null;
        
        // Get detailed service information
        $bride_service = isset($_POST['bride_service']) ? sanitize_text_field($_POST['bride_service']) : null;
        $needs_trial = isset($_POST['needs_trial']) ? sanitize_text_field($_POST['needs_trial']) : null;
        $trial_service = isset($_POST['trial_service']) ? sanitize_text_field($_POST['trial_service']) : null;
        $needs_jewelry = isset($_POST['needs_jewelry']) ? sanitize_text_field($_POST['needs_jewelry']) : null;
        $needs_extensions = isset($_POST['needs_extensions']) ? sanitize_text_field($_POST['needs_extensions']) : null;
        $has_party_members = isset($_POST['has_party_members']) ? sanitize_text_field($_POST['has_party_members']) : null;
        $party_both_count = isset($_POST['party_both_count']) ? intval($_POST['party_both_count']) : 0;
        $party_makeup_count = isset($_POST['party_makeup_count']) ? intval($_POST['party_makeup_count']) : 0;
        $party_hair_count = isset($_POST['party_hair_count']) ? intval($_POST['party_hair_count']) : 0;
        $party_dupatta_count = isset($_POST['party_dupatta_count']) ? intval($_POST['party_dupatta_count']) : 0;
        $party_extensions_count = isset($_POST['party_extensions_count']) ? intval($_POST['party_extensions_count']) : 0;
        $has_airbrush = isset($_POST['has_airbrush']) ? sanitize_text_field($_POST['has_airbrush']) : null;
        $airbrush_count = isset($_POST['airbrush_count']) ? intval($_POST['airbrush_count']) : 0;
        $non_bridal_count = isset($_POST['non_bridal_count']) ? intval($_POST['non_bridal_count']) : 0;
        $non_bridal_everyone_both = isset($_POST['non_bridal_everyone_both']) ? sanitize_text_field($_POST['non_bridal_everyone_both']) : null;
        $non_bridal_both_count = isset($_POST['non_bridal_both_count']) ? intval($_POST['non_bridal_both_count']) : 0;
        $non_bridal_makeup_count = isset($_POST['non_bridal_makeup_count']) ? intval($_POST['non_bridal_makeup_count']) : 0;
        $non_bridal_hair_count = isset($_POST['non_bridal_hair_count']) ? intval($_POST['non_bridal_hair_count']) : 0;
        $non_bridal_extensions_count = isset($_POST['non_bridal_extensions_count']) ? intval($_POST['non_bridal_extensions_count']) : 0;
        $non_bridal_jewelry_count = isset($_POST['non_bridal_jewelry_count']) ? intval($_POST['non_bridal_jewelry_count']) : 0;
        $non_bridal_has_airbrush = isset($_POST['non_bridal_has_airbrush']) ? sanitize_text_field($_POST['non_bridal_has_airbrush']) : null;
        $non_bridal_airbrush_count = isset($_POST['non_bridal_airbrush_count']) ? intval($_POST['non_bridal_airbrush_count']) : 0;
        
        // Basic validation
        if (empty($first_name) || empty($last_name) || empty($email) || empty($region)) {
            wp_send_json_error('Please fill in all required fields');
            return;
        }
        
        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address');
            return;
        }
        
        // Save to database
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bridal_bookings';
        
        // Check if table exists and has the required columns
        $this->ensure_table_structure();
        
        // Prepare data for insertion
        $insert_data = array(
            'unique_id' => $unique_id,
            'name' => $first_name . ' ' . $last_name,
            'email' => $email,
            'phone' => $phone,
            'region' => $region,
            'price' => $price,
            'submission_date' => current_time('mysql')
        );
        
        $insert_format = array('%s', '%s', '%s', '%s', '%s', '%f', '%s');
        
        // Add regular booking details
        if ($event_date) {
            $insert_data['event_date'] = $event_date;
            $insert_format[] = '%s';
        }
        
        if ($ready_time) {
            $insert_data['ready_time'] = $ready_time;
            $insert_format[] = '%s';
        }
        
        if ($service_type) {
            $insert_data['service_type'] = $service_type;
            $insert_format[] = '%s';
        }
        
        if ($artist) {
            $insert_data['artist'] = $artist;
            $insert_format[] = '%s';
        }
        
        // Add event dates if they exist
        if ($event_start_date) {
            $insert_data['event_start_date'] = $event_start_date;
            $insert_format[] = '%s';
        }
        
        if ($event_end_date) {
            $insert_data['event_end_date'] = $event_end_date;
            $insert_format[] = '%s';
        }
        
        // Add destination details if they exist
        if ($destination_details) {
            $insert_data['destination_details'] = $destination_details;
            $insert_format[] = '%s';
        }
        
        // Add detailed service information
        if ($bride_service) {
            $insert_data['bride_service'] = $bride_service;
            $insert_format[] = '%s';
        }
        
        if ($needs_trial) {
            $insert_data['needs_trial'] = $needs_trial;
            $insert_format[] = '%s';
        }
        
        if ($trial_service) {
            $insert_data['trial_service'] = $trial_service;
            $insert_format[] = '%s';
        }
        
        if ($needs_jewelry) {
            $insert_data['needs_jewelry'] = $needs_jewelry;
            $insert_format[] = '%s';
        }
        
        if ($needs_extensions) {
            $insert_data['needs_extensions'] = $needs_extensions;
            $insert_format[] = '%s';
        }
        
        if ($has_party_members) {
            $insert_data['has_party_members'] = $has_party_members;
            $insert_format[] = '%s';
        }
        
        if ($party_both_count > 0) {
            $insert_data['party_both_count'] = $party_both_count;
            $insert_format[] = '%d';
        }
        
        if ($party_makeup_count > 0) {
            $insert_data['party_makeup_count'] = $party_makeup_count;
            $insert_format[] = '%d';
        }
        
        if ($party_hair_count > 0) {
            $insert_data['party_hair_count'] = $party_hair_count;
            $insert_format[] = '%d';
        }
        
        if ($party_dupatta_count > 0) {
            $insert_data['party_dupatta_count'] = $party_dupatta_count;
            $insert_format[] = '%d';
        }
        
        if ($party_extensions_count > 0) {
            $insert_data['party_extensions_count'] = $party_extensions_count;
            $insert_format[] = '%d';
        }
        
        if ($has_airbrush) {
            $insert_data['has_airbrush'] = $has_airbrush;
            $insert_format[] = '%s';
        }
        
        if ($airbrush_count > 0) {
            $insert_data['airbrush_count'] = $airbrush_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_count > 0) {
            $insert_data['non_bridal_count'] = $non_bridal_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_everyone_both) {
            $insert_data['non_bridal_everyone_both'] = $non_bridal_everyone_both;
            $insert_format[] = '%s';
        }
        
        if ($non_bridal_both_count > 0) {
            $insert_data['non_bridal_both_count'] = $non_bridal_both_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_makeup_count > 0) {
            $insert_data['non_bridal_makeup_count'] = $non_bridal_makeup_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_hair_count > 0) {
            $insert_data['non_bridal_hair_count'] = $non_bridal_hair_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_extensions_count > 0) {
            $insert_data['non_bridal_extensions_count'] = $non_bridal_extensions_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_jewelry_count > 0) {
            $insert_data['non_bridal_jewelry_count'] = $non_bridal_jewelry_count;
            $insert_format[] = '%d';
        }
        
        if ($non_bridal_has_airbrush) {
            $insert_data['non_bridal_has_airbrush'] = $non_bridal_has_airbrush;
            $insert_format[] = '%s';
        }
        
        if ($non_bridal_airbrush_count > 0) {
            $insert_data['non_bridal_airbrush_count'] = $non_bridal_airbrush_count;
            $insert_format[] = '%d';
        }
        
        $result = $wpdb->insert($table_name, $insert_data, $insert_format);
        
        if ($result !== false) {
            
    
                // HubSpot integration runs AFTER the response (won't interfere)
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // Ensures response is sent to user first
                }
                
                // Now run HubSpot integration in background
                if (file_exists(__DIR__ . '/hubspot-integration.php')) {
                    require_once __DIR__ . '/hubspot-integration.php';
                    
                    $hubspotData = [
                        'booking_id' => $unique_id,
                        'client_name' => $first_name . ' ' . $last_name,
                        'client_email' => $email,
                        'client_phone' => $phone,
                        'artist_type' => $artist ?? 'Team',
                        'service_type' => $service_type ?? 'Bridal',
                        'region' => $region,
                        'event_date' => $event_date ?? '',
                    ];
                    
                    $hubspotResult = sendBookingToHubSpot($hubspotData);
                    
                    if ($hubspotResult) {
                        error_log("HubSpot: WordPress form sent successfully for " . $unique_id);
                    }
                }
            
            // Send email to user if it's not a destination wedding
            if ($region !== 'Destination Wedding') {
                $this->send_user_email($first_name, $email, $insert_data);
                
                // Build redirect URL for quote page
                $redirect_url = $this->build_quote_url($insert_data);
                wp_send_json_success(array(
                    'message' => ' Generating quote! We will contact you soon.',
                    'redirect_url' => $redirect_url
                ));
            } else {
                wp_send_json_success('Generating quote! We will contact you soon.');
            }
        } else {
            // Log the error for debugging
            error_log('Database insert failed: ' . $wpdb->last_error);
            wp_send_json_error('Failed to save booking. Please try again.');
        }
    }
    
    private function send_user_email($first_name, $email, $booking_data) {
        $subject = 'Thank You for Your Booking Inquiry - Looks By Anum';
        
        // Calculate pricing for both artists
        $anumQuote = $this->getDetailedQuoteForArtist('By Anum', $booking_data);
        $teamQuote = $this->getDetailedQuoteForArtist('By Team', $booking_data);
        
        $anumTotal = number_format($anumQuote['total'], 2);
        $teamTotal = number_format($teamQuote['total'], 2);
        
        // Build quote link for user using unique_id
        $quote_link = '';
        if (!empty($booking_data['unique_id'])) {
            $quote_link = 'https://quote.looksbyanum.com/?id=' . urlencode($booking_data['unique_id']);
        }
        
        $message = "Dear {$first_name},

Thank you for choosing Looks By Anum for your makeup and hair services. We would be delighted to contribute to making your special day elegant and memorable.

Based on your details, here are the package options:

<strong>By Anum Package (Premium service by Anum) –  \${$anumTotal} CAD</strong>
<strong>By Team Package (Professional service by trained team members) – \${$teamTotal} CAD</strong>

" . (!empty($quote_link) 
    ? '<a href="' . $quote_link . '" target="_blank">' . "Click here to view the complete breakdown of each package" . '</a>' 
    : 'Link unavailable') . ".

At Looks By Anum, we believe bridal appointments are a privilege and a responsibility. From introductory calls and trials to the big day itself, we’re here to guide and support you every step of the way.

Whether you’d like to ask a quick question, explore trial pricing, book a trial or appointment, or schedule a call, we’re ready to help.

<strong>You can " . (!empty($quote_link) 
    ? '<a href="' . $quote_link . '" target="_blank">' . "click here" . '</a>' 
    : 'Link unavailable') . " to take your next step.</strong>

It would be an honour to walk alongside you on this journey and bring your bridal vision to life.

Warm Regards,
Team LBA

See the Portfolio: <a href='https://looksbyanum.com/portfolio'>Here</a>
Follow us on: <a href='https://www.instagram.com/looksbyanum/'>Instagram</a>

Booking ID: {$booking_data['unique_id']}";

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Makeup by Anum & Team <noreply@looksbyanum.com>'
        );
        
        // Convert to HTML
        $html_message = nl2br($message);
        
        wp_mail($email, $subject, $html_message, $headers);
    }
    
    private function getDetailedQuoteForArtist($artistType, $booking_data) {
        $quote = [];
        $subtotal = 0;
        
        // Travel fee
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
        
        // Main service pricing
        if (isset($booking_data['service_type'])) {
            if ($booking_data['service_type'] === 'Bridal') {
                if (isset($booking_data['bride_service'])) {
                    if ($booking_data['bride_service'] === 'Both Hair & Makeup') {
                        $price = $isAnumArtist ? 450 : 360;
                        $subtotal += $price;
                    } else if ($booking_data['bride_service'] === 'Hair Only') {
                        $price = $isAnumArtist ? 200 : 160;
                        $subtotal += $price;
                    } else if ($booking_data['bride_service'] === 'Makeup Only') {
                        $price = $isAnumArtist ? 275 : 220;
                        $subtotal += $price;
                    }
                }
                
                // Trial services
                if (isset($booking_data['needs_trial']) && $booking_data['needs_trial'] === 'Yes') {
                    if (isset($booking_data['trial_service'])) {
                        if ($booking_data['trial_service'] === 'Both Hair & Makeup') {
                            $price = $isAnumArtist ? 250 : 200;
                            $subtotal += $price;
                        } else if ($booking_data['trial_service'] === 'Hair Only') {
                            $price = $isAnumArtist ? 150 : 120;
                            $subtotal += $price;
                        } else if ($booking_data['trial_service'] === 'Makeup Only') {
                            $price = $isAnumArtist ? 150 : 120;
                            $subtotal += $price;
                        }
                    }
                }
                
                // Add-ons
                if (isset($booking_data['needs_jewelry']) && $booking_data['needs_jewelry'] === 'Yes') {
                    $subtotal += 50;
                }
                if (isset($booking_data['needs_extensions']) && $booking_data['needs_extensions'] === 'Yes') {
                    $subtotal += 30;
                }
                
                // Bridal party
                if (isset($booking_data['has_party_members']) && $booking_data['has_party_members'] === 'Yes') {
                    if (isset($booking_data['party_both_count']) && $booking_data['party_both_count'] > 0) {
                        $subtotal += $booking_data['party_both_count'] * 200;
                    }
                    if (isset($booking_data['party_makeup_count']) && $booking_data['party_makeup_count'] > 0) {
                        $subtotal += $booking_data['party_makeup_count'] * 100;
                    }
                    if (isset($booking_data['party_hair_count']) && $booking_data['party_hair_count'] > 0) {
                        $subtotal += $booking_data['party_hair_count'] * 100;
                    }
                    if (isset($booking_data['party_dupatta_count']) && $booking_data['party_dupatta_count'] > 0) {
                        $subtotal += $booking_data['party_dupatta_count'] * 20;
                    }
                    if (isset($booking_data['party_extensions_count']) && $booking_data['party_extensions_count'] > 0) {
                        $subtotal += $booking_data['party_extensions_count'] * 20;
                    }
                    if (isset($booking_data['has_airbrush']) && $booking_data['has_airbrush'] === 'Yes' && isset($booking_data['airbrush_count']) && $booking_data['airbrush_count'] > 0) {
                        $subtotal += $booking_data['airbrush_count'] * 50;
                    }
                }
            } else if ($booking_data['service_type'] === 'Semi Bridal') {
                if (isset($booking_data['bride_service'])) {
                    if ($booking_data['bride_service'] === 'Both Hair & Makeup') {
                        $price = $isAnumArtist ? 350 : 280;
                        $subtotal += $price;
                    } else if ($booking_data['bride_service'] === 'Hair Only') {
                        $price = $isAnumArtist ? 175 : 140;
                        $subtotal += $price;
                    } else if ($booking_data['bride_service'] === 'Makeup Only') {
                        $price = $isAnumArtist ? 225 : 180;
                        $subtotal += $price;
                    }
                }
                
                // Add-ons (same as bridal)
                if (isset($booking_data['needs_jewelry']) && $booking_data['needs_jewelry'] === 'Yes') {
                    $subtotal += 50;
                }
                if (isset($booking_data['needs_extensions']) && $booking_data['needs_extensions'] === 'Yes') {
                    $subtotal += 30;
                }
                
                // Bridal party (same logic as bridal)
                if (isset($booking_data['has_party_members']) && $booking_data['has_party_members'] === 'Yes') {
                    if (isset($booking_data['party_both_count']) && $booking_data['party_both_count'] > 0) {
                        $subtotal += $booking_data['party_both_count'] * 200;
                    }
                    if (isset($booking_data['party_makeup_count']) && $booking_data['party_makeup_count'] > 0) {
                        $subtotal += $booking_data['party_makeup_count'] * 100;
                    }
                    if (isset($booking_data['party_hair_count']) && $booking_data['party_hair_count'] > 0) {
                        $subtotal += $booking_data['party_hair_count'] * 100;
                    }
                    if (isset($booking_data['party_dupatta_count']) && $booking_data['party_dupatta_count'] > 0) {
                        $subtotal += $booking_data['party_dupatta_count'] * 20;
                    }
                    if (isset($booking_data['party_extensions_count']) && $booking_data['party_extensions_count'] > 0) {
                        $subtotal += $booking_data['party_extensions_count'] * 20;
                    }
                    if (isset($booking_data['has_airbrush']) && $booking_data['has_airbrush'] === 'Yes' && isset($booking_data['airbrush_count']) && $booking_data['airbrush_count'] > 0) {
                        $subtotal += $booking_data['airbrush_count'] * 50;
                    }
                }
            } else if ($booking_data['service_type'] === 'Non-Bridal / Photoshoot') {
                if (isset($booking_data['non_bridal_everyone_both']) && $booking_data['non_bridal_everyone_both'] === 'Yes') {
                    $pricePerPerson = $isAnumArtist ? 250 : 200;
                    if (isset($booking_data['non_bridal_count'])) {
                        $subtotal += $booking_data['non_bridal_count'] * $pricePerPerson;
                    }
                } else if (isset($booking_data['non_bridal_everyone_both']) && $booking_data['non_bridal_everyone_both'] === 'No') {
                    if (isset($booking_data['non_bridal_both_count']) && $booking_data['non_bridal_both_count'] > 0) {
                        $pricePerPerson = $isAnumArtist ? 250 : 200;
                        $subtotal += $booking_data['non_bridal_both_count'] * $pricePerPerson;
                    }
                    if (isset($booking_data['non_bridal_makeup_count']) && $booking_data['non_bridal_makeup_count'] > 0) {
                        $pricePerPerson = $isAnumArtist ? 140 : 110;
                        $subtotal += $booking_data['non_bridal_makeup_count'] * $pricePerPerson;
                    }
                    if (isset($booking_data['non_bridal_hair_count']) && $booking_data['non_bridal_hair_count'] > 0) {
                        $pricePerPerson = $isAnumArtist ? 130 : 110;
                        $subtotal += $booking_data['non_bridal_hair_count'] * $pricePerPerson;
                    }
                }
                
                // Add-ons
                if (isset($booking_data['non_bridal_extensions_count']) && $booking_data['non_bridal_extensions_count'] > 0) {
                    $subtotal += $booking_data['non_bridal_extensions_count'] * 20;
                }
                if (isset($booking_data['non_bridal_jewelry_count']) && $booking_data['non_bridal_jewelry_count'] > 0) {
                    $subtotal += $booking_data['non_bridal_jewelry_count'] * 20;
                }
                if (isset($booking_data['non_bridal_has_airbrush']) && $booking_data['non_bridal_has_airbrush'] === 'Yes' && isset($booking_data['non_bridal_airbrush_count']) && $booking_data['non_bridal_airbrush_count'] > 0) {
                    $subtotal += $booking_data['non_bridal_airbrush_count'] * 50;
                }
            }
        }
        
        // Travel fee
        if ($travelFee > 0) {
            $subtotal += $travelFee;
        }
        
        $hst = $subtotal * 0.13;
        $total = $subtotal + $hst;
        
        return array('quote' => $quote, 'subtotal' => $subtotal, 'hst' => $hst, 'total' => $total);
    }
    
    private function build_quote_url($booking_data) {
        $base_url = 'https://quote.looksbyanum.com/custom-quote.php';
        
        $params = array();
        
        // Map form data to URL parameters
        if (isset($booking_data['event_date'])) {
            $params['date'] = $booking_data['event_date'];
        }
        
        // For Non-Bridal / Photoshoot services
        if (isset($booking_data['service_type']) && $booking_data['service_type'] === 'Non-Bridal / Photoshoot') {
            $params['servicetype'] = 'nonbridal';  // ← ADDED THIS LINE!
            
            if (isset($booking_data['non_bridal_count'])) {
                $params['nbcount'] = $booking_data['non_bridal_count'];
            }
            
            if (isset($booking_data['non_bridal_everyone_both'])) {
                $params['nbeveryoneboth'] = $booking_data['non_bridal_everyone_both'];
            }
            
            if (isset($booking_data['non_bridal_both_count'])) {
                $params['nbboth'] = $booking_data['non_bridal_both_count'];
            }
            
            if (isset($booking_data['non_bridal_makeup_count'])) {
                $params['nbmakeup'] = $booking_data['non_bridal_makeup_count'];
            }
            
            if (isset($booking_data['non_bridal_hair_count'])) {
                $params['nbhair'] = $booking_data['non_bridal_hair_count'];
            }
            
            if (isset($booking_data['non_bridal_extensions_count'])) {
                $params['nbextensions'] = $booking_data['non_bridal_extensions_count'];
            }
            
            if (isset($booking_data['non_bridal_jewelry_count'])) {
                $params['nbjewelry'] = $booking_data['non_bridal_jewelry_count'];
            }
            
            if (isset($booking_data['non_bridal_has_airbrush'])) {
                $params['nbhasairbrush'] = $booking_data['non_bridal_has_airbrush'];
            }
            
            if (isset($booking_data['non_bridal_airbrush_count'])) {
                $params['nbairbrush'] = $booking_data['non_bridal_airbrush_count'];
            }
        } else {
            // For Bridal and Semi Bridal services - ADD SERVICETYPE PARAMETER
            if (isset($booking_data['service_type'])) {
                if ($booking_data['service_type'] === 'Semi Bridal') {
                    $params['bridalorsemi'] = 'semi';
                    $params['servicetype'] = 'bridal'; // Both use 'bridal' servicetype
                } elseif ($booking_data['service_type'] === 'Bridal') {
                    $params['bridalorsemi'] = 'bridal';
                    $params['servicetype'] = 'bridal';
                }
            }
            
            // ALWAYS add brideservice - use default if not set
            if (isset($booking_data['bride_service']) && !empty($booking_data['bride_service'])) {
                $params['brideservice'] = urlencode($booking_data['bride_service']);
            } else {
                $params['brideservice'] = urlencode('Both Hair & Makeup'); // Default value
            }
            
            // Trial information
            if (isset($booking_data['needs_trial'])) {
                $params['needstrial'] = $booking_data['needs_trial'];
            }
            
            // ALWAYS add trialservice if trial is needed
            if (isset($booking_data['needs_trial']) && $booking_data['needs_trial'] === 'Yes') {
                if (isset($booking_data['trial_service']) && !empty($booking_data['trial_service'])) {
                    $params['trialservice'] = urlencode($booking_data['trial_service']);
                } else {
                    $params['trialservice'] = urlencode('Both Hair & Makeup'); // Default value
                }
            }
            
            if (isset($booking_data['needs_jewelry'])) {
                $params['brideveilsetting'] = $booking_data['needs_jewelry'];
            }
            
            if (isset($booking_data['needs_extensions'])) {
                $params['brideextensions'] = $booking_data['needs_extensions'];
            }
            
            if (isset($booking_data['has_party_members'])) {
                $params['anybp'] = $booking_data['has_party_members'];
            }
            
            if (isset($booking_data['party_both_count'])) {
                $params['bphm'] = $booking_data['party_both_count'];
            }
            
            if (isset($booking_data['party_makeup_count'])) {
                $params['bpm'] = $booking_data['party_makeup_count'];
            }
            
            if (isset($booking_data['party_hair_count'])) {
                $params['bph'] = $booking_data['party_hair_count'];
            }
            
            if (isset($booking_data['party_extensions_count'])) {
                $params['bphextensions'] = $booking_data['party_extensions_count'];
            }
            
            if (isset($booking_data['party_dupatta_count'])) {
                $params['bpsetting'] = $booking_data['party_dupatta_count'];
            }
            
            // Bridal party airbrush
            if (isset($booking_data['has_airbrush'])) {
                $params['bphasairbrush'] = $booking_data['has_airbrush'];
            }
            
            if (isset($booking_data['airbrush_count'])) {
                $params['bpairbrush'] = $booking_data['airbrush_count'];
            }
        }
        
        // Extract first and last name from the full name
        $name_parts = explode(' ', $booking_data['name'], 2);
        $params['fname'] = urlencode($name_parts[0]);
        if (isset($name_parts[1])) {
            $params['lname'] = urlencode($name_parts[1]);
        }
        
        $params['email'] = urlencode($booking_data['email']);
        $params['phone'] = urlencode($booking_data['phone']);
        
        // Include region information - FIXED FOR OUTSIDE GTA
        if (isset($booking_data['region'])) {
            // The region should be the final selected region (including sub-region for Outside GTA)
            $region = $booking_data['region'];
            
            // Debug: Log what region we're working with
            error_log('Building quote URL with region: ' . $region);
            
            $params['region'] = urlencode($region);
        } else {
            error_log('No region found in booking_data');
        }
        
        // Generate a unique form ID
        $params['pfid'] = uniqid();
        
        // Add unique booking ID
        if (isset($booking_data['unique_id'])) {
            $params['booking_id'] = $booking_data['unique_id'];
        }
        
        // Set variant based on artist selection
        if (isset($booking_data['artist'])) {
            $params['varp'] = ($booking_data['artist'] === 'By Anum') ? 'A' : 'B';
        } else {
            $params['varp'] = 'B'; // Default to team
        }
        
        // Build the final URL
        $query_string = http_build_query($params);
        return $base_url . '?' . $query_string;
    }
    
    private function ensure_table_structure() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bridal_bookings';
        
        // Check if unique_id column exists
        $unique_id_column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'unique_id'");
        
        if (empty($unique_id_column_exists)) {
            // Add the unique_id column
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN unique_id varchar(50) DEFAULT NULL AFTER id");
        }
        
        // Check if event_start_date column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'event_start_date'");
        
        if (empty($column_exists)) {
            // Add the missing columns
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN event_start_date date DEFAULT NULL");
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN event_end_date date DEFAULT NULL");
        }
        
        // Check if destination_details column exists
        $destination_column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'destination_details'");
        
        if (empty($destination_column_exists)) {
            // Add the destination details column
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN destination_details text DEFAULT NULL");
        }
        
        // Check if new regular booking columns exist
        $regular_columns = array('event_date', 'ready_time', 'service_type', 'artist');
        
        foreach ($regular_columns as $column) {
            $column_check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (empty($column_check)) {
                if ($column === 'event_date') {
                    $wpdb->query("ALTER TABLE $table_name ADD COLUMN event_date date DEFAULT NULL");
                } else {
                    $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column varchar(255) DEFAULT NULL");
                }
            }
        }
        
        // Check if detailed service columns exist
        $service_columns = array(
            'bride_service' => 'varchar(255)',
            'needs_trial' => 'varchar(10)',
            'trial_service' => 'varchar(255)',
            'needs_jewelry' => 'varchar(10)',
            'needs_extensions' => 'varchar(10)',
            'has_party_members' => 'varchar(10)',
            'party_both_count' => 'int(11)',
            'party_makeup_count' => 'int(11)',
            'party_hair_count' => 'int(11)',
            'party_dupatta_count' => 'int(11)',
            'party_extensions_count' => 'int(11)',
            'has_airbrush' => 'varchar(10)',
            'airbrush_count' => 'int(11)',
            'non_bridal_count' => 'int(11)',
            'non_bridal_everyone_both' => 'varchar(10)',
            'non_bridal_both_count' => 'int(11)',
            'non_bridal_makeup_count' => 'int(11)',
            'non_bridal_hair_count' => 'int(11)',
            'non_bridal_extensions_count' => 'int(11)',
            'non_bridal_jewelry_count' => 'int(11)',
            'non_bridal_has_airbrush' => 'varchar(10)',
            'non_bridal_airbrush_count' => 'int(11)'
        );
        
        foreach ($service_columns as $column => $type) {
            $column_check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (empty($column_check)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $type DEFAULT NULL");
            }
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Bridal Bookings',
            'Bridal Bookings',
            'manage_options',
            'bridal-bookings',
            array($this, 'admin_page'),
            'dashicons-heart',
            30
        );
    }
    
    public function admin_page() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bridal_bookings';
        
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            if (current_user_can('manage_options')) {
                $wpdb->delete($table_name, array('id' => $id), array('%d'));
                echo '<div class="notice notice-success"><p>Booking deleted successfully.</p></div>';
            }
        }
        
        // Handle view details action
        if (isset($_GET['action']) && $_GET['action'] == 'view' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
            
            if ($booking) {
                $this->show_booking_details($booking);
                return;
            }
        }
        
        // Get bookings
        $bookings = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submission_date DESC");
        $total_bookings = count($bookings);
        $total_revenue = 0;
        
        foreach ($bookings as $booking) {
            $total_revenue += $booking->price;
        }
        
        ?>
        <div class="wrap">
            <h1>Bridal Bookings</h1>
            
            <div style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h3>Statistics</h3>
                <p><strong>Total Bookings:</strong> <?php echo $total_bookings; ?></p>
                <p><strong>Total Revenue:</strong> $<?php echo number_format($total_revenue, 2); ?> CAD</p>
            </div>
            
            <?php if ($bookings): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Unique ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Service Type</th>
                            <th>Event Date</th>
                            <th>Price</th>
                            <th>Booking Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><?php echo $booking->id; ?></td>
                                <td><?php echo isset($booking->unique_id) ? esc_html($booking->unique_id) : 'N/A'; ?></td>
                                <td><?php echo esc_html($booking->name); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($booking->email); ?>"><?php echo esc_html($booking->email); ?></a></td>
                                <td><?php echo esc_html($booking->phone); ?></td>
                                <td>
                                    <?php if (isset($booking->service_type) && !empty($booking->service_type)): ?>
                                        <?php echo esc_html($booking->service_type); ?>
                                    <?php else: ?>
                                        Destination Wedding
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($booking->event_date) && !empty($booking->event_date)): ?>
                                        <?php echo date('M j, Y', strtotime($booking->event_date)); ?>
                                    <?php elseif ($booking->event_start_date && $booking->event_end_date): ?>
                                        <?php echo date('M j, Y', strtotime($booking->event_start_date)); ?> - <?php echo date('M j, Y', strtotime($booking->event_end_date)); ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>$<?php echo number_format($booking->price, 2); ?> CAD</td>
                                <td><?php echo date('M j, Y g:i a', strtotime($booking->submission_date)); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=bridal-bookings&action=view&id=' . $booking->id); ?>" 
                                       style="color: #0073aa; margin-right: 10px;">View Details</a>
                                    <a href="<?php echo admin_url('admin.php?page=bridal-bookings&action=delete&id=' . $booking->id); ?>" 
                                       onclick="return confirm('Are you sure you want to delete this booking?')" 
                                       style="color: #a00;">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-info">
                    <p>No bookings found. Use the shortcode <code>[react_form]</code> to display the form.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function show_booking_details($booking) {
        ?>
        <div class="wrap">
            <h1>Booking Details - <?php echo esc_html($booking->name); ?></h1>
            
            <p><a href="<?php echo admin_url('admin.php?page=bridal-bookings'); ?>" class="button">← Back to All Bookings</a></p>
            
            <div style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px 0; border-radius: 4px;">
                
                <!-- Client Information -->
                <h3>Client Information</h3>
                <table class="form-table">
                    <?php if (isset($booking->unique_id) && !empty($booking->unique_id)): ?>
                        <tr>
                            <th>Unique ID:</th>
                            <td><strong><?php echo esc_html($booking->unique_id); ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Name:</th>
                        <td><?php echo esc_html($booking->name); ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><a href="mailto:<?php echo esc_attr($booking->email); ?>"><?php echo esc_html($booking->email); ?></a></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?php echo esc_html($booking->phone); ?></td>
                    </tr>
                    <tr>
                        <th>Region:</th>
                        <td><?php echo esc_html($booking->region); ?></td>
                    </tr>
                    <tr>
                        <th>Booking Date:</th>
                        <td><?php echo date('M j, Y g:i a', strtotime($booking->submission_date)); ?></td>
                    </tr>
                </table>
                
                <!-- Event Information -->
                <h3>Event Information</h3>
                <table class="form-table">
                    <?php if (!empty($booking->event_date)): ?>
                        <tr>
                            <th>Event Date:</th>
                            <td><?php echo date('M j, Y', strtotime($booking->event_date)); ?></td>
                        </tr>
                        <tr>
                            <th>Ready Time:</th>
                            <td><?php echo esc_html($booking->ready_time); ?></td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php if (!empty($booking->event_start_date) && !empty($booking->event_end_date)): ?>
                        <tr>
                            <th>Event Start Date:</th>
                            <td><?php echo date('M j, Y', strtotime($booking->event_start_date)); ?></td>
                        </tr>
                        <tr>
                            <th>Event End Date:</th>
                            <td><?php echo date('M j, Y', strtotime($booking->event_end_date)); ?></td>
                        </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <th>Service Type:</th>
                        <td><?php echo !empty($booking->service_type) ? esc_html($booking->service_type) : 'Destination Wedding'; ?></td>
                    </tr>
                    
                    <?php if (!empty($booking->artist)): ?>
                        <tr>
                            <th>Artist:</th>
                            <td><?php echo esc_html($booking->artist); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
                
                <!-- Service Details -->
                <?php if (!empty($booking->service_type) && $booking->service_type !== 'Destination Wedding'): ?>
                    <h3>Service Details</h3>
                    <table class="form-table">
                        
                        <?php if ($booking->service_type === 'Bridal' || $booking->service_type === 'Semi Bridal'): ?>
                            
                            <?php if (!empty($booking->bride_service)): ?>
                                <tr>
                                    <th>Bride Service:</th>
                                    <td><?php echo esc_html($booking->bride_service); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->needs_trial)): ?>
                                <tr>
                                    <th>Needs Trial:</th>
                                    <td><?php echo esc_html($booking->needs_trial); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->trial_service)): ?>
                                <tr>
                                    <th>Trial Service:</th>
                                    <td><?php echo esc_html($booking->trial_service); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->needs_jewelry)): ?>
                                <tr>
                                    <th>Needs Jewelry Setting:</th>
                                    <td><?php echo esc_html($booking->needs_jewelry); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->needs_extensions)): ?>
                                <tr>
                                    <th>Needs Hair Extensions:</th>
                                    <td><?php echo esc_html($booking->needs_extensions); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->has_party_members)): ?>
                                <tr>
                                    <th>Has Bridal Party:</th>
                                    <td><?php echo esc_html($booking->has_party_members); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->party_both_count > 0): ?>
                                <tr>
                                    <th>Party Members (Hair & Makeup):</th>
                                    <td><?php echo $booking->party_both_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->party_makeup_count > 0): ?>
                                <tr>
                                    <th>Party Members (Makeup Only):</th>
                                    <td><?php echo $booking->party_makeup_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->party_hair_count > 0): ?>
                                <tr>
                                    <th>Party Members (Hair Only):</th>
                                    <td><?php echo $booking->party_hair_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->party_dupatta_count > 0): ?>
                                <tr>
                                    <th>Party Dupatta/Veil Setting:</th>
                                    <td><?php echo $booking->party_dupatta_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->party_extensions_count > 0): ?>
                                <tr>
                                    <th>Party Hair Extensions:</th>
                                    <td><?php echo $booking->party_extensions_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->has_airbrush)): ?>
                                <tr>
                                    <th>Has Airbrush Makeup:</th>
                                    <td><?php echo esc_html($booking->has_airbrush); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->airbrush_count > 0): ?>
                                <tr>
                                    <th>Airbrush Count:</th>
                                    <td><?php echo $booking->airbrush_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                        <?php elseif ($booking->service_type === 'Non-Bridal / Photoshoot'): ?>
                            
                            <?php if ($booking->non_bridal_count > 0): ?>
                                <tr>
                                    <th>Total People Count:</th>
                                    <td><?php echo $booking->non_bridal_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->non_bridal_everyone_both)): ?>
                                <tr>
                                    <th>Everyone Needs Both Hair & Makeup:</th>
                                    <td><?php echo esc_html($booking->non_bridal_everyone_both); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->non_bridal_both_count > 0): ?>
                                <tr>
                                    <th>People Needing Hair & Makeup:</th>
                                    <td><?php echo $booking->non_bridal_both_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->non_bridal_makeup_count > 0): ?>
                                <tr>
                                    <th>People Needing Makeup Only:</th>
                                    <td><?php echo $booking->non_bridal_makeup_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->non_bridal_hair_count > 0): ?>
                                <tr>
                                    <th>People Needing Hair Only:</th>
                                    <td><?php echo $booking->non_bridal_hair_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->non_bridal_extensions_count > 0): ?>
                                <tr>
                                    <th>Hair Extensions Count:</th>
                                    <td><?php echo $booking->non_bridal_extensions_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->non_bridal_jewelry_count > 0): ?>
                                <tr>
                                    <th>Jewelry/Dupatta Setting Count:</th>
                                    <td><?php echo $booking->non_bridal_jewelry_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking->non_bridal_has_airbrush)): ?>
                                <tr>
                                    <th>Has Airbrush Makeup:</th>
                                    <td><?php echo esc_html($booking->non_bridal_has_airbrush); ?></td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php if ($booking->non_bridal_airbrush_count > 0): ?>
                                <tr>
                                    <th>Airbrush Count:</th>
                                    <td><?php echo $booking->non_bridal_airbrush_count; ?></td>
                                </tr>
                            <?php endif; ?>
                            
                        <?php endif; ?>
                    </table>
                <?php endif; ?>
                
                <!-- Destination Wedding Details -->
                <?php if (!empty($booking->destination_details)): ?>
                    <h3>Destination Wedding Details</h3>
                    <table class="form-table">
                        <tr>
                            <th>Details:</th>
                            <td><?php echo nl2br(esc_html($booking->destination_details)); ?></td>
                        </tr>
                    </table>
                <?php endif; ?>
                
                <!-- Pricing Information -->
                <h3>Pricing Information</h3>
                <table class="form-table">
                    <tr>
                        <th>Total Price:</th>
                        <td><strong>$<?php echo number_format($booking->price, 2); ?> CAD</strong></td>
                    </tr>
                </table>
                
            </div>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=bridal-bookings'); ?>" class="button">← Back to All Bookings</a>
                <a href="<?php echo admin_url('admin.php?page=bridal-bookings&action=delete&id=' . $booking->id); ?>" 
                   onclick="return confirm('Are you sure you want to delete this booking?')" 
                   class="button button-secondary" style="margin-left: 10px;">Delete Booking</a>
            </p>
        </div>
        <?php
    }
    
    // Create database table on plugin activation
    public static function activate() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bridal_bookings';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            unique_id varchar(50) DEFAULT NULL,
            name tinytext NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) DEFAULT '',
            region tinytext DEFAULT '',
            price decimal(10,2) DEFAULT 0.00,
            event_date date DEFAULT NULL,
            ready_time varchar(10) DEFAULT NULL,
            service_type varchar(50) DEFAULT NULL,
            artist varchar(50) DEFAULT NULL,
            event_start_date date DEFAULT NULL,
            event_end_date date DEFAULT NULL,
            destination_details text DEFAULT NULL,
            bride_service varchar(255) DEFAULT NULL,
            needs_trial varchar(10) DEFAULT NULL,
            trial_service varchar(255) DEFAULT NULL,
            needs_jewelry varchar(10) DEFAULT NULL,
            needs_extensions varchar(10) DEFAULT NULL,
            has_party_members varchar(10) DEFAULT NULL,
            party_both_count int(11) DEFAULT NULL,
            party_makeup_count int(11) DEFAULT NULL,
            party_hair_count int(11) DEFAULT NULL,
            party_dupatta_count int(11) DEFAULT NULL,
            party_extensions_count int(11) DEFAULT NULL,
            has_airbrush varchar(10) DEFAULT NULL,
            airbrush_count int(11) DEFAULT NULL,
            non_bridal_count int(11) DEFAULT NULL,
            non_bridal_everyone_both varchar(10) DEFAULT NULL,
            non_bridal_both_count int(11) DEFAULT NULL,
            non_bridal_makeup_count int(11) DEFAULT NULL,
            non_bridal_hair_count int(11) DEFAULT NULL,
            non_bridal_extensions_count int(11) DEFAULT NULL,
            non_bridal_jewelry_count int(11) DEFAULT NULL,
            non_bridal_has_airbrush varchar(10) DEFAULT NULL,
            non_bridal_airbrush_count int(11) DEFAULT NULL,
            submission_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize the plugin
new BridalBookingPlugin();

// Activation hook
register_activation_hook(__FILE__, array('BridalBookingPlugin', 'activate'));

?>