<?php
declare(strict_types=1);

/**
 * Render graph subgraph into plain text for model injection.
 * No JSON, no arrays leak out. ≤100 lines target.
 */
final class Render
{
    /** Render full memory pack. */
    public static function pack(array $subgraph, array $seeds, int $windowDays = 30, int $hop = 2): string
    {
        $nodes = $subgraph['nodes'] ?? [];
        $edges = $subgraph['edges'] ?? [];

        $lines   = [];
        $lines[] = '[Memory v1]';

        // Seeds
        $seedTitles = [];
        foreach ($nodes as $n) {
            if (in_array($n['id'], $seeds, true)) $seedTitles[] = $n['title'];
        }
        $lines[] = 'Seeds: ' . ($seedTitles ? implode('; ', $seedTitles) : '(none)');

        // Facts: nodes
        $lines[] = 'Facts:';
        $count = 0;
        foreach ($nodes as $n) {
            $lines[] = sprintf(
                '- %s %s (conf=%.2f)',
                $n['type'],
                $n['title'],
                $n['confidence'] ?? 0
            );
            if (++$count >= 60) { $lines[] = '...'; break; }
        }


        // Build lookup id → title
        $titleMap = [];
        foreach ($nodes as $n) $titleMap[$n['id']] = $n['title'];

        // Relations (grouped by type; compact weight)
        $lines[] = 'Relations:';
        $byType = [];
        foreach ($edges as $e) $byType[$e['type']][] = $e;

        $count = 0;
        foreach ($byType as $type => $group) {
            $lines[] = "- {$type}:";
            foreach ($group as $e) {
                $src = $titleMap[$e['src_id']] ?? $e['src_id'];
                $dst = $titleMap[$e['dst_id']] ?? $e['dst_id'];
                // weight with no trailing zeros
                $w = rtrim(rtrim(number_format((float)$e['weight'], 2, '.', ''), '0'), '.');
                $lines[] = "  • {$src} → {$dst} (w={$w})";
                if (++$count >= 30) { $lines[] = '...'; break 2; }
            }
        }


        // Footer
        $lines[] = sprintf(
            'Window %dd  Nodes %d  Hop %d',
            $windowDays,
            count($nodes),
            $hop
        );

        $lines = array_values(array_unique($lines));

        return implode("\n", $lines) . "\n";
    }
}
