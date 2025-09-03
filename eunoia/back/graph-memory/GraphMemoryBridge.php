<?php
declare(strict_types=1);

/**
 * /var/www/typecho/eunoia/GraphMemoryBridge.php
 * Thin helper between index.php and graph-memory package.
 * - Pre-call: persist user line → link → retrieve → render memory block
 * - Post-call: log turn
 */

require_once '/var/lib/euno/sql/db.php';

require_once '/var/lib/euno/graph-memory/Repo.php';
require_once '/var/lib/euno/graph-memory/Schema.php';
require_once '/var/lib/euno/graph-memory/Detector.php';
require_once '/var/lib/euno/graph-memory/Linker.php';
require_once '/var/lib/euno/graph-memory/Ranker.php';
require_once '/var/lib/euno/graph-memory/Retrieval.php';
require_once '/var/lib/euno/graph-memory/Render.php';
require_once '/var/lib/euno/graph-memory/Graph.php';

final class GraphMemoryBridge
{
    /**
     * Persist the latest user text to messages, build memory system message.
     * Returns [systemMsg|null, userMsgId|null, detectorArray].
     *
     * systemMsg shape: ['role'=>'system','content'=>"[Memory v1]..."]
     */
    public static function injectSystemMemory(string $sessionId, string $userText): array
    {
        $userText = trim($userText);
        if ($userText === '') return [null, null, ['detector_version'=>'v1','intent'=>['label'=>'other','score'=>0.0],'entities'=>[],'slots'=>[]]];

        // Persist user line now to obtain messages.id for evidence
        $userMsgId = insert_message_id($sessionId, 'user', $userText);
        if (!$userMsgId) return [null, null, ['detector_version'=>'v1','intent'=>['label'=>'other','score'=>0.0],'entities'=>[],'slots'=>[]]];

        $repo = new Repo();

        // Detect → Link (writes nodes/aliases/evidence) → Retrieve → Render
        $det  = (new Detector())->detect($userText);
        $link = (new Linker($repo))->link($sessionId, (int)$userMsgId, $det['entities'] ?? []);
        $seeds = $link['seeds'] ?? [];

        $sub  = (new Retrieval($repo))->subgraph($seeds);
        $win  = (int)($repo->getConfig('retrieval.recency_days') ?? 30);
        $hop  = (int)($repo->getConfig('limits.hop') ?? 1);
        $mem  = Render::pack($sub, $seeds, $win, $hop);

        $systemMsg = $mem !== '' ? ['role'=>'system','content'=>$mem] : null;

        return [$systemMsg, (int)$userMsgId, $det];
    }

    public static function injectSystemMemoryReadOnly(string $sessionId, string $userText): ?array
    {
        $userText = trim($userText);
        if ($userText === '') return ['role'=>'system','content'=>"[Memory v1]\nSeeds: (none)\nFacts:\nRelations:\nWindow 30d  Nodes 0  Hop 2\n"];

        $repo = new Repo();
        $det  = (new Detector())->detect($userText);

        // resolve seeds read-only (exact → FTS/LIKE)
        $seeds = [];
        $pdo = $repo->pdo();

        foreach ($det['entities'] ?? [] as $ent) {
            $raw = trim((string)($ent['norm'] ?? $ent['text'] ?? ''));
            if ($raw === '') continue;

            // exact alias
            $stmt = $pdo->prepare('SELECT node_id FROM graph_node_aliases WHERE alias=? LIMIT 1');
            $stmt->execute([$raw]);
            $nid = $stmt->fetchColumn();

            // fallback: LIKE (prefix/suffix)
            if (!$nid) {
                $like = mb_strlen($raw) >= 3 ? "%{$raw}%" : $raw;
                $stmt = $pdo->prepare('SELECT node_id FROM graph_node_aliases WHERE alias LIKE ? ORDER BY created_ts DESC LIMIT 1');
                $stmt->execute([$like]);
                $nid = $stmt->fetchColumn() ?: null;
            }

            if ($nid) $seeds[] = (string)$nid;
        }
        $seeds = array_values(array_unique($seeds));

        $useText = (bool)($repo->getConfig('retrieval.use_text_seeds') ?? true);
        if ($useText) {
            $retr = new Retrieval($repo);
            $textSeeds = $retr->seedsFromText($userText);
            if ($textSeeds) {
                // merge with de-dup
                $seeds = array_values(array_unique(array_merge($seeds, $textSeeds)));
            }
        }

        // even with zero seeds, still render a minimal pack
        $sub  = (new Retrieval($repo))->subgraph($seeds);
        $win  = (int)($repo->getConfig('retrieval.recency_days') ?? 30);
        $hop  = (int)($repo->getConfig('limits.hop') ?? 1);
        $mem  = Render::pack($sub, $seeds, $win, $hop);

        error_log($mem . "\n", 3, '/var/log/euno/graph-bridge.log');

        return [$mem !== '' ? ['role'=>'system','content'=>$mem] : null, null, $det];
    }


    /**
     * Log a completed turn into graph_turns.
     * Accepts null assistant id.
     */
    public static function logTurn(string $sessionId, ?int $userMsgId, ?int $assistantMsgId, array $detector): void
    {
        if (!$userMsgId) return;
        $repo = new Repo();
        (new Graph($repo))->logTurn($sessionId, (int)$userMsgId, $assistantMsgId ? (int)$assistantMsgId : null, $detector);
    }

    
    /* ai input proc */
    private static function stripFences(string $s): string {
        return trim(preg_replace('/~~~\w+\s*\{[\s\S]*?\}\s*~~~/u', '', $s) ?? '');
    }

    /** Run detector+linker on assistant reply so it contributes evidence too. */
    public static function processAssistantMemory(string $sessionId, ?int $assistantMsgId, string $assistantRaw): void
    {
        if (!$assistantMsgId) return;
        $clean = self::stripFences($assistantRaw);
        if ($clean === '') return;

        $repo = new Repo();
        $det  = (new Detector())->detect($clean);
        // tag source as 'assistant'
        (new Linker($repo))->link($sessionId, (int)$assistantMsgId, $det['entities'] ?? [], 'assistant');
    }
}
