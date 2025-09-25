<?php
/**
 * Simple runner script for testing IsolatedSites API integration
 * 
 * This script can be run from the command line or web browser
 * to test the API functionality with user 20
 */

echo "=== IsolatedSites API Test Runner ===\n\n";

// Check if we can include the test file
$testFile = __DIR__ . '/test/IsolatedSitesTest/API/test_api.php';

if (!file_exists($testFile)) {
    echo "ERROR: Test file not found at: $testFile\n";
    echo "Please make sure the test file exists.\n";
    exit(1);
}

echo "Running API integration tests...\n";
echo "Test file: $testFile\n\n";

// Include and run the test
try {
    include $testFile;
} catch (Exception $e) {
    echo "ERROR running tests: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Runner Complete ===\n";
