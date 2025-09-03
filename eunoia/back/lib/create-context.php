<?php
declare(strict_types=1);

require_once '/var/lib/euno/sql/db.php';
require_once '/var/lib/euno/graph-memory/GraphMemoryBridge.php';

const TASK_CTX_PATH = '/var/lib/euno/lib/tasks-context.php';
const MEM_CTX_PATH = '/var/lib/euno/lib/memories.php';

/**
 * Builds the complete context stack for the AI model
 * 
 * @param string $sid Session ID
 * @param array $client_messages Messages from client
 * @return array Array containing the complete message stack and metadata
 */
function create_context(string $sid, array $client_messages): array {
    $result = [
        'messages' => [],
        'skip_user_contents' => [],
        'pre_user_id' => null,
        'detector' => []
    ];
    
    // Load system prompt (server-side only)
    $system_prompt = '';
    $sysPath = '/var/lib/euno/memory/system-prompt.php';
    if (is_file($sysPath) && is_readable($sysPath)) {
        $ret = require $sysPath;
        if (is_string($ret)) { 
            $system_prompt = $ret; 
        }
    }
    
    // --- Graph memory pre-pass ---
    $lastA = -1;
    for ($i = count($client_messages) - 1; $i >= 0; $i--) {
        if (($client_messages[$i]['role'] ?? '') === 'assistant') { 
            $lastA = $i; 
            break; 
        }
    }
    
    $tailUserTexts = [];
    for ($j = $lastA + 1; $j < count($client_messages); $j++) {
        if (($client_messages[$j]['role'] ?? '') === 'user') {
            $t = trim((string)($client_messages[$j]['content'] ?? ''));
            if ($t !== '') $tailUserTexts[] = $t;
        }
    }
    if ($lastA>=0) $ass_msg = $client_messages[$lastA]['content'];
    
    $memInput = trim(implode("\n", $tailUserTexts) . "\n$ass_msg");
    @(list($memMsg, $preUserId, $detOut) = GraphMemoryBridge::injectSystemMemoryReadOnly($sid, $memInput));
    
    $result['skip_user_contents'] = $tailUserTexts;
    @($result['pre_user_id'] = $preUserId);
    @($result['detector'] = $detOut);
    
    // Build system stack
    $system_stack = [];
    
    // Euno persona (system prompt)
    if ($system_prompt !== '') {
        $system_stack[] = ['role' => 'system', 'content' => $system_prompt];
    }
    
    // Task context (equivalent to ensureTaskContextOnce)
    $task_context = get_task_context();
    if ($task_context !== '') {
        $system_stack[] = ['role' => 'system', 'content' => $task_context];
    }
    
    // Current time (equivalent to client-side time injection)
    date_default_timezone_set('America/New_York');
    $system_stack[] = ['role' => 'system', 'content' => 'Current time is: ' . date('Y-m-d H:i:s')];
    
    // Memories (equivalent to injectMemoriesOnce)
    $memories = get_memories();
    if ($memories !== '') {
        $system_stack[] = ['role' => 'system', 'content' => $memories];
    }
    
    // Graph memory
    if ($memMsg) {
        $system_stack[] = $memMsg;
    }
    
    // Extract system messages from client (for any additional system context)
    $system_stack = array_merge($system_stack, extract_system_from_client($client_messages));
    
    // Get history from database
    $outgoing = pack_messages_for_model($sid, $system_stack, 8192, 1024);
    // kick_fake_tool_msg($outgoing);

    
    // Add client delta (new messages)
    $client_delta = build_client_delta($client_messages);
    if (!empty($client_delta)) {
        $outgoing = array_merge($outgoing, $client_delta);
    }
    
    $result['messages'] = unique_tool_msg($outgoing);
    return $result;
}

/**
 * Get task context (equivalent to ensureTaskContextOnce)
 */
function get_task_context(): string {
    $j = load_json_silently(TASK_CTX_PATH);
    if (is_array($j)) {
        if (isset($j['__direct_string__']) && is_string($j['__direct_string__']) && $j['__direct_string__'] !== '') {
            return $j['__direct_string__'];
        }
        $ctx = $j['context'] ?? null;
        if (is_string($ctx) && $ctx !== '') return $ctx; 
    }
    return 'task-context: empty';
}

/**
 * Get memories (equivalent to injectMemoriesOnce)
 */
function get_memories(): string {
    $j = load_json_silently(MEM_CTX_PATH);
    if (is_array($j) && !empty($j['entries']) && is_array($j['entries'])) {
        $parts = array_values(array_filter(array_map('strval', $j['entries']), fn($s)=>trim($s)!==''));
        if ($parts) return 'memories: ' . implode(' | ', $parts);
    }
    return ''; // no memories line if none
}

// Silent loader for endpoint-like PHP files that echo JSON and/or return a value
function load_json_silently(string $path): ?array {
    if (!is_file($path) || !is_readable($path)) return null;
    $lvl = ob_get_level();
    ob_start();
    try {
        $ret = (static function($file){ return require $file; })($path);
        $out = ob_get_contents() ?: '';
    } finally {
        while (ob_get_level() > $lvl) ob_end_clean(); // drop any echoed output
    }
    // Prefer explicit return
    if (is_array($ret)) return $ret;
    if (is_string($ret) && $ret !== '') {
        $j = json_decode($ret, true);
        if (is_array($j)) return $j;
        // treat as direct context string below
        return ['__direct_string__' => $ret];
    }
    // Fallback to captured echo
    $j = json_decode($out, true);
    return is_array($j) ? $j : null;
}

/**
 * Extract system messages from client payload
 */
function extract_system_from_client(array $msgs): array {
    $out = [];
    $seen_task = false; 
    $seen_mem = false; 
    $seen_date = false;

    foreach ($msgs as $m) {
        if (($m['role'] ?? '') !== 'system') continue;

        $c = (string)($m['content'] ?? '');
        if (strncmp($c, '[Memory v1]', 11) === 0) continue;

        // Keep first task context (but we already added it server-side)
        if (!$seen_task && stripos($c, '```Task context policy:') === 0) {
            // Skip as we already added task context server-side
            $seen_task = true; 
            continue;
        }
        
        // Keep first memories (but we already added it server-side)
        if (!$seen_mem && stripos($c, 'memories:') === 0) {
            // Skip as we already added memories server-side
            $seen_mem = true; 
            continue;
        }
        
        // Keep one timestamp line (but we already added it server-side)
        if (!$seen_date && stripos($c, 'Current time is:') === 0) {
            // Skip as we already added time server-side
            $seen_date = true;
            continue;
        }
        
        if (!$seen_date && preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2}/', $c)) {
            // Skip as we already added time server-side
            $seen_date = true;
            continue;
        }
        
        // Add any other system messages
        $out[] = ['role' => 'system', 'content' => $c];
    }
    
    return $out;
}

/**
 * Build client delta (new messages after last assistant response)
 */
function build_client_delta(array $msgs): array {
    $lastA = -1;
    for ($i = count($msgs) - 1; $i >= 0; $i--) {
        if (($msgs[$i]['role'] ?? '') === 'assistant') { 
            $lastA = $i; 
            break; 
        }
    }
    
    $tail = array_slice($msgs, $lastA + 1);
    $delta = [];
    
    foreach ($tail as $m) {
        $role = $m['role'] ?? '';
        if ($role === 'assistant') continue;

        $c = (string)($m['content'] ?? '');
        if ($c === '') continue;

        // Drop ephemerals already handled via system_stack
        if ($role === 'system') {
            $lc = strtolower($c);
            if (strpos($lc, 'task-context:') === 0) continue;
            if (strpos($lc, '```task context policy:') === 0) continue;
            if (strpos($lc, 'memories:') === 0) continue;
            if (strpos($lc, 'current time is:') === 0) continue;
            if (strncmp($lc, '[memory v1]', 11) === 0) continue;
            if (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $c)) continue;
        }

        $curr_delta = ['role' => $role, 'content' => $c];
        add_tool_call_id($m, $curr_delta);

        $delta[] = $curr_delta;
    }
    
    return $delta;
}

function kick_fake_tool_msg(array &$msg): void {
    $last_row = $msg[count($msg) - 1];
    if ($last_row['role'] !== 'tool') return;
    if ($last_row['content'] !== 'PLACEHOLDERx') return;
    array_pop($msg);
}

function unique_tool_msg(array $messages): array {
    // find all tool_call_ids with valid content
    $validIds = [];
    foreach ($messages as $m) {
        if (($m['content'] ?? '') !== 'PLACEHOLDERx') {
            $validIds[$m['tool_call_id'] ?? ''] = true;
        }
    }

    $result = [];
    foreach ($messages as $m) {
        $id = $m['tool_call_id'] ?? '';
        if ($m['content'] === 'PLACEHOLDERx' && isset($validIds[$id])) {
            continue; // drop placeholder if a valid exists
        }
        $result[] = $m;
    }
    return $result;
}