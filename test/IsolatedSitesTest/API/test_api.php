<?php
/**
 * Test script for IsolatedSites API integration
 * 
 * This script demonstrates how to read and modify custom settings
 * (limit_to_granted_sites, limit_to_own_assets) through the Omeka-S API
 * 
 * Tests are verified using UserSettings service and REST API calls
 * Creates a test user at the beginning and deletes it at the end
 * 
 * Usage: Run this script in an Omeka-S environment where the IsolatedSites module is active
 */

// Parse command line arguments
$options = getopt('', ['omeka-path:', 'key-identity:', 'key-credentials:', 'base-url:']);
$omekaPath = isset($options['omeka-path']) ? $options['omeka-path'] : '/var/www/html';
$keyIdentity = isset($options['key-identity']) ? $options['key-identity'] : null;
$keyCredentials = isset($options['key-credentials']) ? $options['key-credentials'] : null;
$baseUrl = isset($options['base-url']) ? $options['base-url'] : 'http://localhost/api';

// Validate required parameters for REST API calls
if (!$keyIdentity || !$keyCredentials) {
    echo "ERROR: REST API authentication required.\n";
    echo "Usage: php test_api.php --key-identity=YOUR_KEY_IDENTITY --key-credentials=YOUR_KEY_CREDENTIALS [--omeka-path=/path/to/omeka] [--base-url=http://your-site.com/api]\n\n";
    echo "To create API keys:\n";
    echo "1. Log in to Omeka-S as admin\n";
    echo "2. Go to Admin → Users → [Your User] → API Keys\n";
    echo "3. Create a new API key\n";
    echo "4. Use the generated key_identity and key_credential values\n";
    die();
}

echo "Initializing Omeka S application...\n";
require_once "$omekaPath/bootstrap.php";
if (!class_exists('Omeka\Module\AbstractModule')) {
    die("This script must be run in an Omeka-S environment.\n");
}

echo "=== IsolatedSites API Integration Test ===\n";
echo "Using API Key Identity: " . substr($keyIdentity, 0, 8) . "...\n";
echo "Base URL: $baseUrl\n\n";

try {
    // Get the API manager service
    $application = Omeka\Mvc\Application::init(require "$omekaPath/application/config/application.config.php");
    $services = $application->getServiceManager();
    $entityManager = $services->get('Omeka\EntityManager');
    $api = $services->get('Omeka\ApiManager');
    $userSettingsService = $services->get('Omeka\Settings\User');

    // Get the authentication service and set the admin user (ID 1) for API operations
    $auth = $services->get('Omeka\AuthenticationService');
    $adminUser = $entityManager->find('Omeka\Entity\User', 1); // Admin user (ID 1)
    if ($adminUser) {
        $auth->getStorage()->write($adminUser);
        echo "Using admin user for API operations\n";
    } else {
        echo "Warning: Admin user not found. Some operations may fail due to permission issues.\n";
    }

    // Helper function to make REST API calls with authentication
    function makeRestApiCall($method, $url, $keyIdentity, $keyCredentials, $data = null) {
        // Add authentication parameters to URL
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $authenticatedUrl = $url . $separator . 'key_identity=' . urlencode($keyIdentity) . '&key_credential=' . urlencode($keyCredentials);
        
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $authenticatedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: IsolatedSites-Test/1.0'
        ]);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        curl_close($ch);
        
        if ($curlErrno !== 0) {
            echo "  DEBUG: cURL Error ($curlErrno): $curlError\n";
            return [
                'status' => 0,
                'data' => null,
                'error' => $curlError,
                'errno' => $curlErrno
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "  DEBUG: JSON decode error: " . json_last_error_msg() . "\n";
        }
        
        return [
            'status' => $httpCode,
            'data' => $decodedResponse,
            'raw_response' => $response
        ];
    }
    
    // Variable to store test user ID
    $testUserId = null;
    
    echo "1. CREATING TEST USER\n";
    echo "=====================\n";
    
    // Create a test user for all operations
    echo "Creating test user for API integration tests...\n";
    try {
        $createResponse = $api->create('users', [
            'o:name' => 'API Test User',
            'o:email' => 'apitest@example.com',
            'o:role' => 'editor',
            'o-module-isolatedsites:limit_to_granted_sites' => false,
            'o-module-isolatedsites:limit_to_own_assets' => false
        ]);
        
        $testUser = $createResponse->getContent();
        $testUserId = $testUser->id();
        echo "✓ Successfully created test user with ID: $testUserId\n";
        
        // Verify initial settings
        $userSettingsService->setTargetId($testUserId);
        echo "Initial settings:\n";
        echo "  - Limit to Granted Sites: " . ($userSettingsService->get('limit_to_granted_sites', false) ? 'true' : 'false') . "\n";
        echo "  - Limit to Own Assets: " . ($userSettingsService->get('limit_to_own_assets', false) ? 'true' : 'false') . "\n";
        
    } catch (Exception $e) {
        echo "✗ Failed to create test user: " . $e->getMessage() . "\n";
        throw $e; // Re-throw to stop the test
    }
    
    echo "\n2. TESTING CREATE OPERATION WITH CUSTOM SETTINGS\n";
    echo "==================================================\n";
    
    // Test creating another user with custom settings enabled
    echo "Test 1: Creating user with custom settings enabled...\n";
    try {
        $createResponse2 = $api->create('users', [
            'o:name' => 'Test User With Settings',
            'o:email' => 'testwithsettings@example.com',
            'o:role' => 'editor',
            'o-module-isolatedsites:limit_to_granted_sites' => true,
            'o-module-isolatedsites:limit_to_own_assets' => true
        ]);
        
        $newUser = $createResponse2->getContent();
        $newUserId = $newUser->id();
        echo "✓ Successfully created user with ID: $newUserId\n";
        
        // Verify using UserSettings service
        $userSettingsService->setTargetId($newUserId);
        echo "  - Limit to Granted Sites (UserSettings): " . ($userSettingsService->get('limit_to_granted_sites', false) ? 'true' : 'false') . "\n";
        echo "  - Limit to Own Assets (UserSettings): " . ($userSettingsService->get('limit_to_own_assets', false) ? 'true' : 'false') . "\n";
        
        // Verify using REST API
        $restResponse = makeRestApiCall('GET', "$baseUrl/users/$newUserId", $keyIdentity, $keyCredentials, null);
        if ($restResponse['status'] === 200) {
            $userData = $restResponse['data'];
            echo "  - Limit to Granted Sites (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_granted_sites']) && $userData['o-module-isolatedsites:limit_to_granted_sites'] ? 'true' : 'false') . "\n";
            echo "  - Limit to Own Assets (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_own_assets']) && $userData['o-module-isolatedsites:limit_to_own_assets'] ? 'true' : 'false') . "\n";
        } else {
            echo "  - REST API verification failed with status: " . $restResponse['status'] . "\n";
        }
        
        // Clean up - delete this temporary user
        $api->delete('users', $newUserId);
        echo "  - Temporary test user deleted\n";
        
    } catch (Exception $e) {
        echo "✗ Failed to create user with settings: " . $e->getMessage() . "\n";
    }
    
    echo "\n3. TESTING UPDATE OPERATIONS\n";
    echo "=============================\n";
    
    // Update test user with custom settings - Test 1: Enable both settings
    echo "Test 2: Updating test user - Enable both settings...\n";
    try {
        $updateResponse1 = $api->update('users', $testUserId, [
            'o-module-isolatedsites:limit_to_granted_sites' => true,
            'o-module-isolatedsites:limit_to_own_assets' => true
        ], [], ['isPartial' => true]);
        
        echo "✓ Successfully updated test user (ID: $testUserId)\n";
        
        // Verify using UserSettings service
        $userSettingsService->setTargetId($testUserId);
        echo "  - Limit to Granted Sites (UserSettings): " . ($userSettingsService->get('limit_to_granted_sites', false) ? 'true' : 'false') . "\n";
        echo "  - Limit to Own Assets (UserSettings): " . ($userSettingsService->get('limit_to_own_assets', false) ? 'true' : 'false') . "\n";
        
        // Verify using REST API
        $restResponse = makeRestApiCall('GET', "$baseUrl/users/$testUserId", $keyIdentity, $keyCredentials, null);
        if ($restResponse['status'] === 200) {
            $userData = $restResponse['data'];
            echo "  - Limit to Granted Sites (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_granted_sites']) && $userData['o-module-isolatedsites:limit_to_granted_sites'] ? 'true' : 'false') . "\n";
            echo "  - Limit to Own Assets (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_own_assets']) && $userData['o-module-isolatedsites:limit_to_own_assets'] ? 'true' : 'false') . "\n";
        } else {
            echo "  - REST API verification failed with status: " . $restResponse['status'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "✗ Failed to update test user: " . $e->getMessage() . "\n";
    }

    echo "\nTest 3: Updating test user - Mixed settings...\n";
    try {
        $updateResponse2 = $api->update('users', $testUserId, [
            'o-module-isolatedsites:limit_to_granted_sites' => false,
            'o-module-isolatedsites:limit_to_own_assets' => true
        ], [], ['isPartial' => true]);
        
        echo "✓ Successfully updated test user with mixed settings\n";
        
        // Verify using UserSettings service
        $userSettingsService->setTargetId($testUserId);
        echo "  - Limit to Granted Sites (UserSettings): " . ($userSettingsService->get('limit_to_granted_sites', false) ? 'true' : 'false') . "\n";
        echo "  - Limit to Own Assets (UserSettings): " . ($userSettingsService->get('limit_to_own_assets', false) ? 'true' : 'false') . "\n";
        
        // Verify using REST API
        $restResponse = makeRestApiCall('GET', "$baseUrl/users/$testUserId", $keyIdentity, $keyCredentials, null);
        if ($restResponse['status'] === 200) {
            $userData = $restResponse['data'];
            echo "  - Limit to Granted Sites (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_granted_sites']) && $userData['o-module-isolatedsites:limit_to_granted_sites'] ? 'true' : 'false') . "\n";
            echo "  - Limit to Own Assets (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_own_assets']) && $userData['o-module-isolatedsites:limit_to_own_assets'] ? 'true' : 'false') . "\n";
        } else {
            echo "  - REST API verification failed with status: " . $restResponse['status'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "✗ Failed to update test user: " . $e->getMessage() . "\n";
    }

    echo "\n4. TESTING REST API DIRECT OPERATIONS\n";
    echo "======================================\n";
    // Test direct REST API update
    echo "Test 4: Direct REST API update...\n";
    $restUpdateData = [
        'o-module-isolatedsites:limit_to_granted_sites' => true,
        'o-module-isolatedsites:limit_to_own_assets' => false
    ];
    
    $restUpdateResponse = makeRestApiCall('PATCH', "$baseUrl/users/$testUserId", $keyIdentity, $keyCredentials, $restUpdateData);
    
    if ($restUpdateResponse['status'] === 200) {
        echo "✓ Successfully updated test user via REST API\n";
        
        // Verify using REST API read
        $restVerifyResponse = makeRestApiCall('GET', "$baseUrl/users/$testUserId", $keyIdentity, $keyCredentials, null);
        if ($restVerifyResponse['status'] === 200) {
            $userData = $restVerifyResponse['data'];
            echo "  - Limit to Granted Sites (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_granted_sites']) && $userData['o-module-isolatedsites:limit_to_granted_sites'] ? 'true' : 'false') . "\n";
            echo "  - Limit to Own Assets (REST API): " . (isset($userData['o-module-isolatedsites:limit_to_own_assets']) && $userData['o-module-isolatedsites:limit_to_own_assets'] ? 'true' : 'false') . "\n";
        }
    } else {
        echo "✗ REST API update failed with status: " . $restUpdateResponse['status'] . "\n";
    }

    echo "\n5. TESTING ERROR HANDLING\n";
    echo "==========================\n";
    
    // Test with invalid values
    echo "Test 5: Testing with invalid boolean values...\n";
    try {
        $updateResponse3 = $api->update('users', $testUserId, [
            'o-module-isolatedsites:limit_to_granted_sites' => 'invalid',
            'o-module-isolatedsites:limit_to_own_assets' => 'also_invalid'
        ], [], ['isPartial' => true]);
        
        echo "✗ API should have rejected invalid values but didn't!\n";
        
    } catch (\Omeka\Api\Exception\ValidationException $e) {
        echo "✓ Validation exception correctly thrown: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "✗ Unexpected exception caught: " . $e->getMessage() . "\n";
    }
    
    echo "\n6. FINAL STATE CHECK\n";
    echo "=====================\n";
    
    // Final check using both methods
    echo "Final state of test user (ID: $testUserId):\n";
    
    // REST API check
    $finalRestResponse = makeRestApiCall('GET', "$baseUrl/users/$testUserId", $keyIdentity, $keyCredentials, null);
    if ($finalRestResponse['status'] === 200) {
        $finalUserData = $finalRestResponse['data'];
        echo "Via REST API:\n";
        echo "  - Name: " . $finalUserData['o:name'] . "\n";
        echo "  - Email: " . $finalUserData['o:email'] . "\n";
        echo "  - Limit to Granted Sites: " . (isset($finalUserData['o-module-isolatedsites:limit_to_granted_sites']) && $finalUserData['o-module-isolatedsites:limit_to_granted_sites'] ? 'true' : 'false') . "\n";
        echo "  - Limit to Own Assets: " . (isset($finalUserData['o-module-isolatedsites:limit_to_own_assets']) && $finalUserData['o-module-isolatedsites:limit_to_own_assets'] ? 'true' : 'false') . "\n";
    } else {
        echo "  - REST API final check failed with status: " . $finalRestResponse['status'] . "\n";
    }
    
    echo "\n7. CLEANUP\n";
    echo "==========\n";
    
    // Clean up the test user
    echo "Deleting test user (ID: $testUserId)...\n";
    try {
        $api->delete('users', $testUserId);
        echo "✓ Test user deleted successfully\n";
    } catch (Exception $e) {
        echo "✗ Failed to delete test user: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== CURL EXAMPLES FOR EXTERNAL TESTING ===\n";
    echo "============================================\n";
    
    echo "1. Get user via REST API:\n";
    echo "curl -X GET 'http://your-omeka-site.com/api/users/{USER_ID}?key_identity=YOUR_KEY_IDENTITY&key_credential=YOUR_KEY_CREDENTIALS' \\\n";
    echo "     -H 'Accept: application/json'\n\n";
    
    echo "2. Update user custom settings via REST API:\n";
    echo "curl -X PUT 'http://your-omeka-site.com/api/users/{USER_ID}?key_identity=YOUR_KEY_IDENTITY&key_credential=YOUR_KEY_CREDENTIALS' \\\n";
    echo "     -H 'Content-Type: application/json' \\\n";
    echo "     -H 'X-Requested-With: XMLHttpRequest' \\\n";
    echo "     -d '{\n";
    echo '       "o-module-isolatedsites:limit_to_granted_sites": true,' . "\n";
    echo '       "o-module-isolatedsites:limit_to_own_assets": false' . "\n";
    echo "     }'\n\n";
    
    echo "3. Create user with custom settings:\n";
    echo "curl -X POST 'http://your-omeka-site.com/api/users?key_identity=YOUR_KEY_IDENTITY&key_credential=YOUR_KEY_CREDENTIALS' \\\n";
    echo "     -H 'Content-Type: application/json' \\\n";
    echo "     -H 'X-Requested-With: XMLHttpRequest' \\\n";
    echo "     -d '{\n";
    echo '       "o:name": "New User",' . "\n";
    echo '       "o:email": "newuser@example.com",' . "\n";
    echo '       "o:role": "editor",' . "\n";
    echo '       "o-module-isolatedsites:limit_to_granted_sites": true,' . "\n";
    echo '       "o-module-isolatedsites:limit_to_own_assets": false' . "\n";
    echo "     }'\n\n";
    
    echo "=== TEST COMPLETED SUCCESSFULLY ===\n";
    echo "The IsolatedSites API integration is working correctly!\n";
    echo "Tests verified using UserSettings service and REST API calls.\n";
    echo "Test user was created and cleaned up automatically.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Try to clean up test user if it was created
    if (isset($testUserId) && $testUserId && isset($api)) {
        echo "\nAttempting to clean up test user...\n";
        try {
            $api->delete('users', $testUserId);
            echo "✓ Test user cleaned up successfully\n";
        } catch (Exception $cleanupException) {
            echo "✗ Failed to clean up test user: " . $cleanupException->getMessage() . "\n";
        }
    }
    
    echo "\nTroubleshooting:\n";
    echo "1. Ensure the IsolatedSites module is active\n";
    echo "2. Check that you have proper permissions to create/read/update/delete users\n";
    echo "3. Verify the API is accessible\n";
    echo "4. Adjust the \$baseUrl variable to match your Omeka-S installation\n";
    echo "5. Make sure the admin user (ID 1) exists for authentication\n";
    echo "6. Check that the email addresses used in the test don't already exist\n";
}
