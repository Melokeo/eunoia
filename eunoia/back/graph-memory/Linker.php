<?php
// /var/lib/euno/graph-memory/Linker.php
declare(strict_types=1);

/**
 * Link entities to existing nodes via aliases; create provisional nodes on miss.
 * Records evidence tied to the user message id.
 */
final class Linker
{
    private Repo $repo;

    public function __construct(?Repo $repo = null)
    {
        $this->repo = $repo ?? new Repo();
    }

    /**
     * @param string $sessionId messages.session_id
     * @param int    $userMsgId messages.id of the utterance
     * @param array  $entities  detector entities array
     * @return array ['seeds' => string[] node_ids, 'created' => int, 'matched' => int]
     */
    public function link(string $sessionId, int $msgId, array $entities, string $source='span'): array
    {
        $seeds = [];
        $created = 0;
        $matched = 0;

        foreach ($entities as $ent) {
            $type = $ent['type'] ?? 'Other';
            $raw  = trim((string)($ent['norm'] ?? $ent['text'] ?? ''));
            if ($raw === '') continue;

            // search alias
            $hit = $this->bestAliasHit($raw);
            if ($hit) {
                $nodeId = $hit['node_id'];
                $matched++;
                $seeds[] = $nodeId;
                $this->repo->insertEvidence($nodeId, 'node', $msgId, $this->spanOrNull($ent));
                continue;
            }

            // create provisional
            $nodeId = $this->repo->newId('n_');
            $this->repo->begin();
            try {
                $this->repo->insertNode($nodeId, $type, $this->normalizeTitle($raw), 0.3, 'provisional');
                $aliasId = $this->repo->newId('a_');
                $this->repo->insertAlias($aliasId, $nodeId, $raw, $source, 1.0);
                $this->repo->insertEvidence($nodeId, 'node', $msgId, $this->spanOrNull($ent));
                $this->repo->commit();
            } catch (\Throwable $e) {
                $this->repo->rollBack();
                continue;
            }
            $created++;
            $seeds[] = $nodeId;
        }

        // dedupe seeds
        $seeds = array_values(array_unique($seeds));

        return ['seeds' => $seeds, 'created' => $created, 'matched' => $matched];
    }

    private function bestAliasHit(string $q): ?array
    {
        // Exact first, then FT/LIKE
        $row = $this->repo->findAliasExact($q);
        if ($row) return $row;

        $cands = $this->repo->findAliases($q, 5);
        return $cands[0] ?? null;
    }

    private function normalizeTitle(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s ?? '');
        return mb_substr(trim((string)$s), 0, 255, 'UTF-8');
    }

    private function spanOrNull(array $ent): ?string
    {
        if (!isset($ent['span']) || !is_array($ent['span']) || count($ent['span']) !== 2) return null;
        [$s,$e] = $ent['span'];
        if (!is_int($s) || !is_int($e)) return null;
        return json_encode(['span'=>[$s,$e]], JSON_UNESCAPED_UNICODE);
    }
}
