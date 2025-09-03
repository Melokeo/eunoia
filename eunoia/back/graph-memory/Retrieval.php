<?php
declare(strict_types=1);

final class Retrieval {
    private Repo $repo;
    private Ranker $ranker;

    public function __construct(?Repo $repo=null){
        $this->repo=$repo??new Repo();
        $this->ranker=new Ranker($this->repo);
    }

    /**
     * Given seed node ids, expand 1-hop, rank, return ≤ nodeCap nodes.
     */
    public function subgraph(array $seedIds):array {
        $nodeCap=(int)($this->repo->getConfig('limits.node_cap')??100);
        $hop=(int)($this->repo->getConfig('limits.hop')??1);

        if(!$seedIds) return ['nodes'=>[],'edges'=>[]];

        // Collect seed nodes
        $nodes=$this->repo->getNodes($seedIds);

        // --- Multi-hop expansion (BFS up to $hop) ---
        $visited  = array_fill_keys($seedIds, 0);  // node_id => depth
        $frontier = $seedIds;
        $edges    = [];
        $nodes    = $this->repo->getNodes(array_keys($visited));

        for ($d = 1; $d <= $hop && $frontier; $d++) {
            $batchEdges = $this->repo->getEdgesTouching($frontier);
            $edges = array_merge($edges, $batchEdges);

            $nextSet = [];
            foreach ($batchEdges as $e) {
                $a = $e['src_id']; $b = $e['dst_id'];
                if (!isset($visited[$a])) $nextSet[$a] = true;
                if (!isset($visited[$b])) $nextSet[$b] = true;
            }

            $perDepthCap = (int)($this->repo->getConfig('limits.per_depth_cap') ?? 500);
            $next = array_keys($nextSet);
            if ($perDepthCap > 0 && count($next) > $perDepthCap) $next = array_slice($next, 0, $perDepthCap);

            foreach ($next as $nid) $visited[$nid] = $d;
            if ($next) $nodes = array_merge($nodes, $this->repo->getNodes($next));
            $frontier = $next;
        }

        // de-dup edges
        $edges = array_values(array_reduce($edges, function($acc, $e){
            $k = $e['id'] ?? ($e['src_id'].'>'.$e['type'].'>'.$e['dst_id']);
            $acc[$k] = $e; return $acc;
        }, []));

        // take down bridges
        $K = (int)($this->repo->getConfig('limits.bridge_depth') ?? 2);
        if ($K > 0) {
            $bridges = $this->bridgeNodes($seedIds, $K);
            if ($bridges) {
                $extraNodes = $this->repo->getNodes($bridges);
                $nodes = array_merge($nodes, $extraNodes);
                $edges = array_merge($edges, $this->repo->getEdgesTouching($bridges));
            }
        }
        // Score and cap
        foreach($nodes as &$n){
            $n['score']=$this->ranker->score([
                'textScore'=>1.0, // stub; add lexical score later
                'recencyHours'=>rand(1,72), // stub; use ts later
                'edgeWeight'=>0.5,
                'centrality'=>0.1,
                'depthPenalty' => 1.0 / (1 + ($visited[$n['id']] ?? 0)),
            ]);
        }
        usort($nodes,fn($a,$b)=>$b['score']<=>$a['score']);
        $nodes=array_slice($nodes,0,$nodeCap);

        return ['nodes'=>$nodes,'edges'=>$edges];
    }

    private function bridgeNodes(array $seedIds, int $k): array {
        if (count($seedIds) < 2 || $k < 1) return [];
        $reach = []; // seed => node => depth
        $Qall  = [];

        foreach ($seedIds as $s) {
            $reach[$s] = [$s => 0];
            $Qall[$s]  = [$s];
            for ($d = 1; $d <= $k && $Qall[$s]; $d++) {
                $edges = $this->repo->getEdgesTouching($Qall[$s]);
                $next = [];
                foreach ($edges as $e) {
                    foreach ([$e['src_id'],$e['dst_id']] as $v) {
                        if (!isset($reach[$s][$v])) {
                            $reach[$s][$v] = $d;
                            $next[$v] = true;
                        }
                    }
                }
                $Qall[$s] = array_keys($next);
            }
        }

        $counts = [];
        foreach ($reach as $map) foreach ($map as $v => $_) $counts[$v] = ($counts[$v] ?? 0) + 1;
        $bridges = array_values(array_filter(array_keys($counts), fn($v) => $counts[$v] >= 2));

        $cap = (int)($this->repo->getConfig('limits.bridge_cap') ?? 100);
        if ($cap > 0 && count($bridges) > $cap) $bridges = array_slice($bridges, 0, $cap);
        return $bridges;
    }

    /** CJK-safe tokens: CJK chars as single tokens; ASCII words split by \w+. */
    private function tokenizeCjkSafe(string $s): array {
        $s = mb_strtolower($s, 'UTF-8');
        // 1) extract ASCII words
        preg_match_all('/[A-Za-z0-9_]+/u', $s, $m1);
        $ascii = $m1[0] ?? [];

        // 2) extract CJK codepoints (Unified Ideographs + extensions A, B basic block)
        preg_match_all('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}]/u', $s, $m2);
        $cjk = $m2[0] ?? [];

        $tokens = array_merge($ascii, $cjk);

        // 3) filter trivial tokens
        static $stop = [
            'the','a','an','and','or','to','of','in','is','are','was','were','on','for','with','at','by','it','this','that',
        ];
        $tokens = array_values(array_unique(array_filter($tokens, function($t) use ($stop){
            if (preg_match('/^[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}]$/u', $t)) return true; // single CJK ok
            if (strlen($t) < 2) return false;
            return !in_array($t, $stop, true);
        })));

        return $tokens;
    }

    /** Build seed node ids from raw text via alias lookup. */
    public function seedsFromText(string $userText, int $limitPerToken = 3): array {
        $tokens = $this->tokenizeCjkSafe($userText);
        if (!$tokens) return [];
        $hits = [];
        foreach ($tokens as $tok) {
            // prefer exact; then LIKE via findAliases
            $exact = $this->repo->findAliasExact($tok);
            if ($exact) { $hits[] = $exact['node_id']; continue; }
            foreach ($this->repo->findAliases($tok, $limitPerToken) as $row) {
                $hits[] = $row['node_id'];
            }
        }
        return array_values(array_unique($hits));
    }


}
