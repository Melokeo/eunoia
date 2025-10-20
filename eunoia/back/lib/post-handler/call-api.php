<?php
if (!defined('LIB_ROOT')) exit('No direct access');

function create_request(string $sid, array $client_messages, ?array $last_func_call): array {
    // build context
    $context = create_context($sid, $client_messages, true, true);
    $outgoing = $context['messages'];

    // attach last tool call if present
    if ($last_func_call) {
        preserve_tool_call($outgoing, $last_func_call);
    }

    return [
        'messages' => $outgoing,
        'meta' => [
            'skip_user_contents' => $context['skip_user_contents'] ?? [],
            'pre_user_id'        => $context['pre_user_id'] ?? null,
            'detector'           => $context['detector'] ?? []
        ]
    ];
}

function call_model_api(array $messages, string $apiKey): array {
  $TOOLS = require '/var/lib/euno/memory/tool-functions.php';

  switch (CURR_API_TYPE) {
    case 'open-ai-new':
      $payload = build_openai_new_payload($messages);
      $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
      ];
      $timeout = 60;
      break;

    case 'open-ai-old':
      $payload = build_openai_old_payload($messages);
      $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
      ];
      $timeout = 60;
      break;

    case 'claude':
    default:
      $payload = build_claude_payload($messages);
      $headers = [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json'
      ];
      $timeout = 30;
      break;
  }

  if (TOOL_MODE) $payload['tools'] = $TOOLS;

  $ch = curl_init(API_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => $timeout,
  ]);

  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['error' => 'cURL error: ' . $err];
  }
  curl_close($ch);

  $json = json_decode($raw, true);
  if ($code >= 400 || !is_array($json)) {
    $msg = is_array($json) && isset($json['error']['message'])
      ? $json['error']['message']
      : ('HTTP ' . $code);
    error_log('[AI] raw=' . substr($raw, 0, 2000) . $msg);
    return ['error' => $msg];
  }

  file_put_contents(LAST_OUT_LOG, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  return ['data' => $json];
}

