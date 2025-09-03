<?php
declare(strict_types=1);

/**
 * Test script for context generation
 * Usage: 
 *   - Direct URL access: https://yourdomain.com/test-context.php
 *   - With session ID: https://yourdomain.com/test-context.php?sid=YOUR_SESSION_ID
 *   - With custom messages: POST JSON to this script with {"messages": [...]}
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');

// Allow CORS for testing
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include required files
require_once '/var/lib/euno/sql/db.php';
require_once '/var/lib/euno/graph-memory/GraphMemoryBridge.php';
require_once '/var/lib/euno/lib/create-context.php';

// Function to generate test messages
function generate_test_messages() {
    return [
        [
            'role' => 'system',
            'content' => 'Current time is: ' . date('Y-m-d H:i:s')
        ],
        [
            'role' => 'system',
            'content' => 'memories: user likes coffee, user works in tech'
        ],
        [
            'role' => 'user',
            'content' => 'Hello, how are you today?'
        ],
        [
            'role' => 'assistant',
            'content' => 'I\'m doing well, thank you for asking! How can I help you today?'
        ],
        [
            'role' => 'user',
            'content' => 'I need help with a programming problem'
        ]
    ];
}

// Get or create session ID
if (isset($_GET['sid']) && !empty($_GET['sid'])) {
    $sid = $_GET['sid'];
} else {
    // Create a test session ID
    $sid = 'test_session_' . bin2hex(random_bytes(8));
}

// Get messages from POST or use test messages
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $client_messages = $input['messages'] ?? generate_test_messages();
} else {
    $client_messages = generate_test_messages();
}

try {
    // Generate context
    $context = create_context($sid, $client_messages);
    
    // Prepare response
    $response = [
        'success' => true,
        'session_id' => $sid,
        'input_messages' => $client_messages,
        'context' => [
            'messages_count' => count($context['messages']),
            'messages_preview' => array_map(function($msg) {
                return [
                    'role' => $msg['role'],
                    'content_preview' => mb_substr($msg['content'], 0, 100) . 
                                        (mb_strlen($msg['content']) > 100 ? '...' : '')
                ];
            }, $context['messages']),
            'skip_user_contents' => $context['skip_user_contents'],
            'pre_user_id' => $context['pre_user_id'],
            'detector' => $context['detector']
        ],
        'full_messages' => $context['messages']
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Error response
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}