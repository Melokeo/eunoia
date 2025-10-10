<?php
declare(strict_types=1);
require_once '/var/lib/euno/lib/unpack-history-from-pinecone.php';

final class PineconeBridge
{
    // Drop-in replacement. Calls local worker at 127.0.0.1:5145/q
    public static function injectSystemMemoryReadOnly(string $sessionId, string $userText): ?array
    {
        $url = 'http://127.0.0.1:5145/q';

        $payload = [
            'text'  => $userText,
            'topK'  => 20,
            'rmin'  => 0,
            'rmax'  => 10,
            // pass-throughs if needed later:
            // 'filter' => null,
            // 'rerank' => false,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 3,
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // error_log(json_encode($raw), JSON_PRETTY_PRINT);

        // default DET
        $det = [
            'source' => 'local-worker',
            'url'    => $url,
            'http'   => $http,
            'raw_response' => $raw,
        ];

        if ($raw === false || $err !== '') {
            $det['error'] = $err ?: 'curl_exec returned false';
            return [null, null, $det];
        }

        $res = json_decode($raw, true);
        if (!is_array($res)) {
            $det['error'] = 'non-json response';
            $det['raw']   = mb_substr((string)$raw, 0, 500);
            return [null, null, $det];
        }

        // Extract a single concatenated content string
        $raw_resp = $res; // local worker JSON already decoded
        $joined_history = organize_chunks($raw_resp);
        $mem = $joined_history ? '[Memory v2] ' . $joined_history : '';

        // trim and cap
        $mem = trim($mem);
        if ($mem !== '') {
            // single system message content only
            return [['role' => 'system', 'content' => mb_substr($mem, 0, 6000)], null, $det + ['ok' => true]];
        }
        return [null, null, $det + ['ok' => true]];
    }

    /* depricated */ 
    private static function extractSingleContent(array $res): string
    {
        // Direct single-field cases
        foreach (['mem', 'summary', 'text', 'content'] as $k) {
            if (isset($res[$k]) && is_string($res[$k]) && $res[$k] !== '') {
                return $res[$k];
            }
        }

        // Candidate containers
        $candidates = [];
        foreach (['hits', 'snippets', 'results', 'data', 'items'] as $k) {
            if (isset($res[$k]) && is_array($res[$k])) { $candidates = $res[$k]; break; }
        }
        if (!$candidates && array_is_list($res)) $candidates = $res;

        if ($candidates) {
            $parts = [];
            foreach ($candidates as $h) {
                if (!is_array($h)) continue;

                // Accept common text fields, including chunk_text
                $txt =
                    ($h['chunk_text'] ?? null) ??
                    ($h['text']       ?? null) ??
                    ($h['content']    ?? null) ??
                    ($h['snippet']    ?? null) ??
                    (($h['fields']['text'] ?? null) ?? ($h['fields']['content'] ?? null) ?? null);

                if (is_string($txt) && $txt !== '') {
                    $parts[] = self::filterMemoryContent(trim($txt));
                    if (mb_strlen(implode("\n\n---\n\n", $parts)) > 5500) break;
                }
            }
            if ($parts) return implode("\n\n---\n\n", $parts);
        }

        return '';
    }

    private static function filterMemoryContent(string $text): string
    {
        $text = preg_replace('/function result:\s*\{[^}]*\}/', 'function result: [result]', $text);
        $text = preg_replace('/pattern/', 'replacement', $text);
        // add more filters here as needed
        $text = preg_replace('/~~~.*?~~~/s', '[cmd]', $text);
        
        return $text;
    }
}
