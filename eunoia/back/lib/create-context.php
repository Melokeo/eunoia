<?php
declare(strict_types=1);

require_once '/var/lib/euno/sql/db.php';
require_once '/var/lib/euno/graph-memory/GraphMemoryBridge.php';
require_once '/var/lib/euno/lib/PineconeBridge.php';
require_once '/var/lib/euno/lib/unpack-history-from-pinecone.php';
require_once '/var/lib/euno/lib/rag-rewrite.php';

const TASK_CTX_PATH = '/var/lib/euno/lib/tasks-context.php';
const MEM_CTX_PATH = '/var/lib/euno/lib/memories.php';

/**
 * Builds the complete context stack for the AI model
 * 
 * @param string $sid Session ID
 * @param array $client_messages Messages from client
 * @return array Array containing the complete message stack and metadata
 */
function create_context(string $sid, array $client_messages, bool $include_history=true, bool $no_tool=false): array {
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
    
    $memInput = trim(implode("\n", $tailUserTexts));
    $memInput = compute_rag_query($sid, $client_messages, $memInput);
    // $memInput .= isset($ass_msg) ? "\n$ass_msg" : '';
    @(list($memMsg, $preUserId, $detOut) = PineconeBridge::injectSystemMemoryReadOnly($sid, $memInput));
    
    $result['skip_user_contents'] = $tailUserTexts;
    @($result['pre_user_id'] = $preUserId);
    @($result['detector'] = $detOut);
    
    // Build system stack
    $system_stack = [];
    
    // Euno persona (system prompt)
    if ($system_prompt !== '') {
        $system_prompt = str_replace("\r\n", "\n", $system_prompt);
        $system_stack[] = ['role' => 'system', 'content' => $system_prompt];
    }
    
    // Task context (equivalent to ensureTaskContextOnce)
    $task_context = get_task_context();
    if ($task_context !== '') {
        $system_stack[] = ['role' => 'system', 'content' => $task_context];
    }
    
    // Current time (equivalent to client-side time injection)
    date_default_timezone_set('America/New_York');
    $now = new DateTimeImmutable();
    $today = $now->format('Y-m-dD');
    $tomorrow = $now->modify('+1 day')->format('Y-m-dD');
    $week_end = $now->modify('Sunday this week')->format('Y-m-dD');
    $next_week_start = $now->modify('Monday next week')->format('Y-m-dD');
    $next_week_end = $now->modify('Sunday next week')->format('Y-m-dD');

    $system_stack[] = [
        'role' => 'system', 
        'content' => '现在时间：' . date('Y-m-dD H:i:s') . "\n" .
                    "今天=" . $today . ' | ' .
                    "明天=" . $tomorrow . ' | ' .
                    "本周=" . $today . '~' . $week_end . ' (' . 
                        implode(', ', array_map(fn($d) => date('m.d', strtotime($today . " +$d days")) . '-周' . ['日','一','二','三','四','五','六'][date('w', strtotime($today . " +$d days"))], range(0, 6))) . 
                        ') | ' .
                    "下周=" . $next_week_start . '~' . $next_week_end . "\n" .
                    'Calculate dates from THESE values only'
    ];
    
    // Memories (equivalent to injectMemoriesOnce)
    $memories = get_memories();
    if ($memories !== '') {
        $system_stack[] = ['role' => 'system', 'content' => $memories];
    }
    // error_log(json_encode($system_stack, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    
    // Graph memory
    $memory_rounds = [];
    if ($memMsg && ($memMsg['content'] ?? '') !== '') {
        $content = $memMsg['content'];
        if (strncmp($content, '[Memory v2]', 11) === 0) {
            // error_log($content);
            $memory_rounds = unpack_memory_v2($content);
            $system_stack[] = ['role' => 'system', 'content' => '[Memory v2] --'];
        } else {
            // fallback: keep as system if not v2 format
            $system_stack[] = $memMsg;
        }
    } else {
        error_log('Empty sys memory!');
    }

    // Extract system messages from client (for any additional system context)
    $system_stack = array_merge($system_stack, extract_system_from_client($client_messages));

    /*// Get history from database
    $outgoing = $include_history? 
        pack_messages_for_model($sid, $system_stack, 8192, 1024):
        $system_stack;*/

    // insert memory rounds after system stack, before DB history
    if (!empty($memory_rounds)) {
        if ($include_history) {
            // re-pack with memory rounds already included
            $db_history = pack_messages_for_model($sid, $system_stack, 8192, 1024);
            // replace system portion with full stack
            $outgoing = array_merge(
                array_slice($db_history, 0, count($system_stack)),
                $memory_rounds,
                array_slice($db_history, count($system_stack))
            );
        } else {
            $outgoing = array_merge($system_stack, $memory_rounds);
        }
    }

    // kick_fake_tool_msg($outgoing);
    if ($no_tool) {discard_all_tools($outgoing);}
    
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
function get_memories_legacy(): string {
    $j = load_json_silently(MEM_CTX_PATH);
    if (is_array($j) && !empty($j['entries']) && is_array($j['entries'])) {
        $parts = array_values(array_filter(array_map('strval', $j['entries']), fn($s)=>trim($s)!==''));
        if ($parts) return 'memories: ' . implode(' | ', $parts);
    }
    return ''; // no memories line if none
}

// new mem structure
function get_memories(): string {
    $j = load_json_silently(MEM_CTX_PATH);

    // migrate legacy flat entries → General
    if (is_array($j) && isset($j['entries']) && !isset($j['categories'])) {
        $j = ['categories' => ['General' => array_values(array_filter(array_map('strval', $j['entries'])))]];
        @file_put_contents(MEM_CTX_PATH, json_encode($j, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        @chmod(MEM_CTX_PATH, 0600);
    }

    $cats = is_array($j['categories'] ?? null) ? $j['categories'] : [];
    // normalize: remove empties, cast to strings
    $cats = array_filter(array_map(static function($facts) {
        $facts = is_array($facts) ? $facts : [];
        $facts = array_values(array_filter(array_map('strval', $facts), fn($s)=>trim($s)!==''));
        return $facts;
    }, $cats));

    if (!$cats) return 'EUNO IS IN AMNESIA!';

    ksort($cats, SORT_NATURAL|SORT_FLAG_CASE);
    $keys = implode(',', array_keys($cats));

    $chunks = [];
    foreach ($cats as $k => $facts) {
        // keep short; separate facts with '; '
        $chunks[] = $k . '[' . implode('; ', $facts) . ']';
    }
    // single compact line, keys first to guide open-world categorization
    return 'memories: keys=' . $keys . ' | ' . implode(' | ', $chunks);
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
        if (strncmp($c, '[Memory v', 9) === 0) continue;

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

    /* NEW: locate last user in tail */
    $lastUser = -1;
    for ($i = count($tail) - 1; $i >= 0; $i--) {
        if (($tail[$i]['role'] ?? '') === 'user') { $lastUser = $i; break; }
    }

    $delta = [];
    foreach ($tail as $idx => $m) {
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
            if (strncmp($lc, '[memory v', 9) === 0) continue;
            if (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $c)) continue;
        }

        /* prepend stamp to the last user input only, if not already stamped */
        if ($idx === $lastUser && $role === 'user') {
            $dt = new DateTime('now', new DateTimeZone('-04:00'));  // match DB tz
            $c = '[' . $dt->format('m.dD H:i') . '] ' . $c;
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