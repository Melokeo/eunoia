<?php
if (!defined('LIB_ROOT')) exit('No direct access');

function db_insert_message(
    string $sid,
    array $client_messages,
    array $meta,
    string $answer_raw,
    array $tool_calls,
    string $model
): void {
    $skip_user_contents = $meta['skip_user_contents'] ?? [];
    $pre_user_id = $meta['pre_user_id'] ?? null;
    $detector    = $meta['detector'] ?? [];

    try {
      $skip_next_user_once = false;

      foreach ($client_messages as $m) {
        $role = strtolower($m['role'] ?? '');
        $content = (string)($m['content'] ?? '');
        if ($content === '') continue;

        // system router trace
        if ($role === 'system' && strncmp($content, 'function result: ', 17) === 0) {
            insert_message($sid, 'system', $content);
            $skip_next_user_once = true;
            continue;
        }

        // skip one ephemeral user line after router trace
        if ($role === 'user' && $skip_next_user_once) {
            if (stripos($content, 'continue') !== 0) {
                insert_message($sid, 'user', $content);
            }
            $skip_next_user_once = false;
            continue;
        }

        // dedup skip_user_contents
        // GM: do not skip current-turn user; rely on INSERT IGNORE to dedupe
        if ($role === 'user' && $skip_user_contents) {
            $idx = array_search($content, $skip_user_contents, true);
            if ($idx !== false) {
                array_splice($skip_user_contents, $idx, 1);
            }
        }

        // tool message with id
        if ($role === 'tool') {
            if (!isset($m['tool_call_id'])) continue;
            insert_message($sid, 'tool', $content, 0, null, null, null, null, $m['tool_call_id']);
            continue;
        }

        // skip ephemeral system lines
        if ($role === 'system') {
          $lc = strtolower($content);
          if (
            strpos($lc, 'task-context:') === 0 ||
            strpos($lc, '```task context policy:') === 0 ||
            strpos($lc, 'memories:') === 0 ||
            strpos($lc, 'current time is:') === 0 ||
            strpos($lc, '现在时间') === 0 ||
            strncmp($content, '[Memory', 11) === 0 ||
            preg_match('/^(\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2})/i', $content)
          ) {
              continue;
          }
        }

        // normal insertion (skip assistant)
        if ($role !== 'assistant') {
            insert_message($sid, $role, $content);
        }
      }

      // assistant reply
      $assistant_msg_id = null;
      $tool_json = $tool_calls
          ? json_encode($tool_calls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
          : null;

      if ($answer_raw !== '' || $tool_json) {
        $assistant_msg_id = insert_message_id(
          $sid,
          'assistant',
          $answer_raw,
          0,
          $model,
          null,
          null,
          $tool_json,
          null
        );
      }

      // optional graph memory hooks
      // GraphMemoryBridge::logTurn($sid, $pre_user_id, $assistant_msg_id, $detector);
      // GraphMemoryBridge::processAssistantMemory($sid, $assistant_msg_id, $answer_raw);
    } catch (Throwable $e) {
        error_log('[history] persist failed: ' . $e->getMessage());
    }
}