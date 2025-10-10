<?php
// if call is openai, tool_calls won't be correctly parsed, so just get rid of them.
function discard_all_tools(array &$msgs): void {
  foreach ($msgs as $i => $m) {
    if ($m['role'] === 'tool') {
      unset($msgs[$i]);
    } else{
      if (array_key_exists('tool_calls', $m)) {
        unset($msgs[$i]['tool_calls']);
      }
    }
  }
}

/* returns true if a function call normally lack tool msg */
function is_really_no_response(array $tool_call): bool {
  return false;
  
  $fname = $tool_call['function']['name'];
  if (!isset($tool_call['function']['name'])) return false;
  if ($fname === 'memory' || $fname === 'debug') return true;
  return false;
}

function no_tail_tool(array &$msgs): void {
  if (empty($msgs)) return;
  $last = $msgs[count($msgs) - 1];
  // error_log(json_encode($last, JSON_PRETTY_PRINT));
  if ($last['role'] !== 'assistant') return;
  if (array_key_exists('tool_calls', $last)) {
    $last['tool_calls'] = null;
    //error_log($last['tool_calls']);
  } else {
    // error_log('tool_calls: ok');
  }

  $msgs[count($msgs) - 1] =  $last;
}

/**
 * Ensure assistant tool_calls are followed by matching tool entries.
 * - If assistant has a call with no tool return => inject synthetic tool with empty content.
 * - If a tool has no preceding assistant call => drop it.
 * Input order: oldest → newest.
 */
/**
 * Validate and repair assistant↔tool sequencing.
 * Invariants enforced:
 *  - For every assistant message with tool_calls_json = [{id:...}, ...],
 *    there are exactly N following tool messages matched in order by tool_call_id.
 *  - Stray tool messages (no pending assistant or mismatched id) are dropped.
 *  - If a matching tool message lacks tool_call_id, it is filled from the pending id.
 *  - If tools are missing when a non-tool message arrives, synthetic empty tool
 *    messages are inserted to satisfy the pending ids.
 *
 * Input:  $msgs oldest→newest, each item: ['role','content','tool_call_id','tool_calls_json', ...]
 * Output: repaired list oldest→newest.
 */
function reconcile_assistant_tool_sequence(array $msgs): array {
    $out = [];
    $pending = [];                 // queue of ['id'=>string, 'need'=>bool] from the last assistant
    $havePending = false;          // true iff pending belongs to the last assistant in $out

    $flush_pending = function() use (&$out, &$pending, &$havePending) {
        if ($havePending && $pending) {
            // Insert synthetic empty tool messages for any unmet tool calls
            foreach ($pending as $p) {
                if ($p['need']) {
                    $out[] = [
                        'role' => 'tool',
                        'content' => $p['placeholder'],
                        'tool_call_id' => $p['id'],
                    ];
                }
            }
        }
        $pending = [];
        $havePending = false;
    };

    foreach ($msgs as $m) {
        $role = $m['role'] ?? '';

        if ($role === 'assistant') {
            // New assistant: close out any prior pending first
            $flush_pending();

            // Parse tool_calls_json
            $pending = [];
            $havePending = true;
            $tc = [];
            if (!empty($m['tool_calls'])) {
                //$tc = json_decode((string)$m['tool_calls'], true);
                $tc = $m['tool_calls'];
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($tc)) {
                    $tc = [];
                }
                $l = count($tc);
                $t = prettyTrimmedJson($tc);
                //error_log("tc det: len $l \n$t");
            }
            // Build ordered queue from assistant-declared calls
            foreach ($tc as $call) {
                $id = is_array($call) ? ($call['id'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    $pending[] = ['id' => $id, 'need' => true, 'placeholder' => is_really_no_response($call)? 'void function' : 'PLACEHOLDERx'];
                }
            }

            if (empty($tc)) { $havePending = false; } else {
              $e = prettyTrimmedJson($pending);
              //error_log("pending = $e");
            }

            // Keep the assistant as-is
            $out[] = $m;
            continue;
        }

        if ($role === 'tool') {
            if (!$havePending || !$pending) {
                // No assistant expecting tools → drop stray tool
                $t = prettyTrimmedJson($m);
                //rror_log("Dropped tool:\n$t");
                continue;
            }
            // Match against the head of the queue
            $expected = $pending[0]['id'];
            $tid = $m['tool_call_id'] ?? null;

            if (!is_string($tid) || $tid === '') {
                // Fill missing id with expected
                $m['tool_call_id'] = $expected;
                $tid = $expected;
            }

            if ($tid === $expected) {
                // Consume one pending slot
                $pending[0]['need'] = false;
                array_shift($pending);
                $out[] = $m;
                // If all matched, clear the pending flag
                if (!$pending) $havePending = false;
            } else {
                // Mismatched id → drop as stray
                continue;
            }
            continue;
        }

        // Any other role breaks the pending chain: flush unmet tool calls, then pass through
        $flush_pending();
        $out[] = $m;
    }

    // End: flush any unmet tools
    $flush_pending();
    return $out;
}