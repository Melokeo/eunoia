<?php
// /var/lib/euno/graph-memory/Detector.php
declare(strict_types=1);

/**
 * Lightweight detector. No external NLP.
 * Heuristics: intent, entities (Task/Person/Project/Time/Quantity/Preference), slots.
 */
final class Detector
{
    public function detect(string $utterance): array
    {
        $text = trim($utterance);
        $intent = $this->inferIntent($text);
        [$entities, $slots] = $this->extractEntitiesAndSlots($text);

        return [
            'detector_version' => 'v1',
            'intent' => ['label' => $intent, 'score' => 0.7],
            'entities' => $entities,
            'slots' => $slots,
        ];
    }

    private function inferIntent(string $t): string
    {
        $l = mb_strtolower($t, 'UTF-8');
        if (preg_match('/\b(todo|to do|finish|complete|done|deadline|due|by \d{1,2}\/\d{1,2}|\bby (mon|tue|wed|thu|fri|sat|sun)\b)/i', $t)) return 'plan';
        if (preg_match('/\b(prefer|like|love|hate|dislike|prefer[s]?)\b/i', $t)) return 'preference';
        if (preg_match('/\b(assign|delegate|owner|owned by|responsible)\b/i', $t)) return 'assign';
        if (preg_match('/\b(meeting|call|schedule|at \d{1,2}(:\d{2})?\s*(am|pm)?|tomorrow|today|next (week|month))\b/i', $t)) return 'schedule';
        if (preg_match('/\b(who|what|when|where|why|how)\b/i', $l)) return 'query';
        return 'other';
    }

    private function extractEntitiesAndSlots(string $t): array
    {
        $entities = [];
        $slots = [];

        // Dates like 2025-09-01, 09/01, Sep 1, tomorrow, next Monday
        $isoDates = $this->extractDates($t);
        if ($isoDates) {
            foreach ($isoDates as $dt) {
                $entities[] = ['type' => 'Time', 'text' => $dt['raw'], 'span' => $dt['span'], 'norm' => $dt['iso'], 'score' => 0.8];
            }
            // Use first as deadline if “by” occurs
            if (preg_match('/\bby\b/i', $t)) $slots['deadline'] = $isoDates[0]['iso'];
        }

        // Quantities like "15 lbs", "32GB", "3 tasks"
        foreach ($this->extractQuantities($t) as $q) {
            $entities[] = ['type' => 'Quantity', 'text' => $q['raw'], 'span' => $q['span'], 'norm' => "{$q['value']} {$q['unit']}", 'score' => 0.75];
        }

        // Preferences like "prefer classical music"
        if (preg_match('/\bprefer(?:s|red)?\s+([^\.;,]+)$/i', $t, $m, PREG_OFFSET_CAPTURE)) {
            $pref = trim($m[1][0]);
            $start = $m[1][1];
            $entities[] = ['type' => 'Preference', 'text' => $pref, 'span' => [$start, $start + mb_strlen($pref, 'UTF-8')], 'norm' => $pref, 'score' => 0.8];
            $slots['preference'] = $pref;
        }

        // Very simple Task/Project detection: quoted spans or capitalized chunks
        foreach ($this->extractQuotedOrTitleCase($t) as $chunk) {
            $entities[] = ['type' => 'Task', 'text' => $chunk['text'], 'span' => $chunk['span'], 'norm' => $chunk['text'], 'score' => 0.7];
        }

        return [$entities, $slots];
    }

    private function extractDates(string $t): array
    {
        $res = [];
        // ISO YYYY-MM-DD
        if (preg_match_all('/\b(20[2-9]\d-\d{2}-\d{2})\b/', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[1] as $m) $res[] = ['raw' => $m[0], 'iso' => $m[0], 'span' => [$m[1], $m[1] + strlen($m[0])]];
        }
        // MM/DD or M/D
        if (preg_match_all('/\b(\d{1,2}\/\d{1,2})\b/', $t, $ms, PREG_OFFSET_CAPTURE)) {
            $year = (int)date('Y');
            foreach ($ms[1] as $m) {
                [$mm, $dd] = array_map('intval', explode('/', $m[0]));
                $iso = sprintf('%04d-%02d-%02d', $year, $mm, $dd);
                $res[] = ['raw' => $m[0], 'iso' => $iso, 'span' => [$m[1], $m[1] + strlen($m[0])]];
            }
        }
        // Natural words
        $map = [
            'today' => 0, 'tomorrow' => 1, 'tmr' => 1,
            'next week' => 7, 'next month' => 30,
        ];
        foreach ($map as $kw => $add) {
            if (preg_match('/\b'.preg_quote($kw,'/').'\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                $iso = date('Y-m-d', strtotime("+$add day"));
                $res[] = ['raw' => $m[0][0], 'iso' => $iso, 'span' => [$m[0][1], $m[0][1] + strlen($m[0][0])]];
            }
        }
        // Weekday like Monday
        if (preg_match('/\b(mon|tue|wed|thu|fri|sat|sun)(day)?\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
            $iso = date('Y-m-d', strtotime('next ' . $m[0][0]));
            $res[] = ['raw' => $m[0][0], 'iso' => $iso, 'span' => [$m[0][1], $m[0][1] + strlen($m[0][0])]];
        }
        return $res;
    }

    private function extractQuantities(string $t): array
    {
        $out = [];
        if (preg_match_all('/\b(\d+(?:\.\d+)?)\s*(kg|g|lb|lbs|gb|mb|ms|s|min|h|hours?)\b/i', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[0] as $i => $m) {
                $raw = $m[0];
                $start = $m[1];
                $val = (float)$ms[1][$i][0];
                $unit = strtolower($ms[2][$i][0]);
                $out[] = ['raw' => $raw, 'value' => $val, 'unit' => $unit, 'span' => [$start, $start + strlen($raw)]];
            }
        }
        return $out;
    }

    private function extractQuotedOrTitleCase(string $t): array
    {
        $out = [];
        // Quoted “...” or '...'
        if (preg_match_all('/[\'"“”](.+?)[\'"“”]/u', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[1] as $m) {
                $txt = trim($m[0]);
                if ($txt !== '') $out[] = ['text' => $txt, 'span' => [$m[1], $m[1] + mb_strlen($txt, 'UTF-8')]];
            }
        }
        // Title Case chunk of 2–5 words
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z0-9]+){1,4})\b/u', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[1] as $m) {
                $txt = trim($m[0]);
                if (mb_strlen($txt, 'UTF-8') >= 3) $out[] = ['text' => $txt, 'span' => [$m[1], $m[1] + mb_strlen($txt, 'UTF-8')]];
            }
        }
        return $out;
    }
}
