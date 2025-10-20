<?php

function update_summary(string $sid): void {
  try {
    if (!need_summary($sid)) return;

    $apiKey = load_key();
    $prompts = require SECRET_ROOT . '/misc-prompts.php';
    $sys_prompt  = $prompts['chat-summary-sys']  ?? '';
    $user_prompt = $prompts['chat-summary-user'] ?? '';

    $sumCall = function(array $messages, int $maxTokens, float $temp)
      use ($apiKey, $sys_prompt, $user_prompt): ?string {

      $payload = [
        'model'       => API_MODEL,
        'messages'    => array_merge([
          ['role' => 'system', 'content' => $sys_prompt],
          ['role' => 'user',   'content' => $user_prompt]
        ], $messages),
        'max_tokens'  => $maxTokens,
        'temperature' => $temp,
      ];

      $ch = curl_init(API_URL);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
          'Content-Type: application/json',
          'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 25,
      ]);
      $raw = curl_exec($ch);
      curl_close($ch);

      $json = json_decode((string)$raw, true);
      if (!is_array($json)) return null;

      $msg = $json['choices'][0]['message']['content'] ?? '';
      return is_string($msg) && $msg !== '' ? $msg : null;
    };

    create_session_summary($sid, function(array $m, int $k, float $t) use ($sumCall) {
      return $sumCall($m, $k, $t);
    });
  } catch (Throwable $e) {
    error_log('[summary] failed: ' . $e->getMessage());
  }
}
