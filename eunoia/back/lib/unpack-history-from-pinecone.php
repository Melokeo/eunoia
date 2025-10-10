<?php
declare(strict_types=1);

function unpack_memory_v2(string $content): array {
    // remove leading "[Memory v2]" header
    $content = preg_replace('/^\[Memory v2\]\s*/', '', $content);

    // split into lines
    $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $content)));
    $messages = [];

    foreach ($lines as $line) {
        // match optional timestamp and role
        if (preg_match('/^(?:\[(\d{6}\w* \d{2}:\d{2})\]\s*)?(Mel|Euno):\s*(.*)$/u', $line, $m)) {
            $timestamp = $m[1] ?? null;
            $role = strtolower($m[2]) === 'euno' ? 'assistant' : 'user';
            $text = trim($m[3]);

            if ($timestamp) {
                $display = "[$timestamp] " . $text;
            } else {
                $display = $text;
            }

            $messages[] = [
                'role' => $role,
                'content' => $display,
            ];
        } else {
            // continuation of previous message or narration
            if (!empty($messages)) {
                $messages[count($messages) - 1]['content'] .= "\n" . $line;
            }
        }
    }

    $messages = dedupe_lines_across_messages($messages);
    return $messages;
}

/**
 * Line-level dedupe across the whole transcript.
 * - Ignores roles.
 * - Removes inline [yyMMddDay HH:MM] only for comparison.
 * - Exact line match only (no fuzzy). Partial overlap handled because we compare per line.
 */
function dedupe_lines_across_messages(array $messages): array {
    $seen = [];
    $out  = [];
    $ts_prefix = '/^\[(\d{6}\w* \d{2}:\d{2})\]\s*/';

    foreach ($messages as $msg) {
        $content = (string)($msg['content'] ?? '');
        if ($content === '') continue;

        $lines = preg_split('/\r?\n/', $content);
        $kept  = [];

        foreach ($lines as $ln) {
            // normalize for comparison
            $norm = preg_replace($ts_prefix, '', $ln);
            $norm = trim(preg_replace('/\s+/', ' ', $norm));
            if ($norm === '') continue;

            if (isset($seen[$norm])) {
                // drop duplicate line (even if role differs)
                continue;
            }
            $seen[$norm] = true;
            $kept[] = $ln;            // keep original formatting
        }

        if (!empty($kept)) {
            $msg['content'] = implode("\n", $kept);
            $out[] = $msg;
        }
    }
    return $out;
}

function organize_chunks(array|string $det, int $ts_space_min = 5): string {
    // Handle both decoded and raw forms
    if (is_string($det)) {
        $decoded = json_decode($det, true);
        if (!is_array($decoded)) return '';
        $hits = $decoded['hits'] ?? [];
    } else {
        $hits = $det['hits'] ?? [];
    }
    if (!is_array($hits)) return '';

    // sort by start_ts
    usort($hits, fn($a, $b) => strcmp($a['start_ts'], $b['start_ts']));

    $lines = [];
    foreach ($hits as $hit) {
        $chunk = trim((string)($hit['chunk_text'] ?? ''));
        if ($chunk === '') continue;
        foreach (preg_split('/\r?\n/', $chunk) as $ln) {
            if ($ln !== '') $lines[] = $ln;
        }
    }

    $ts_pat = '/\[(\d{6}\w{3} \d{2}:\d{2})\]/';
    $out = [];
    $last_ts = null;

    foreach ($lines as $ln) {
        if (preg_match($ts_pat, $ln, $m)) {
            $curr = DateTime::createFromFormat('ymdD H:i', $m[1]);
            if ($last_ts === null) {
                $out[] = $ln;
                $last_ts = $curr;
            } else {
                $diff = $curr->getTimestamp() - $last_ts->getTimestamp();
                if ($diff >= $ts_space_min * 60) {
                    $out[] = $ln;
                    $last_ts = $curr;
                } else {
                    // too close, remove TS
                    $out[] = ltrim(preg_replace($ts_pat, '', $ln));
                }
            }
        } else {
            $out[] = $ln;
        }
    }

    return implode("\n", $out);
}