<?php
declare(strict_types=1);

/**
 * Scores candidate nodes. Simple weighted sum.
 */
final class Ranker {
    private array $weights;
    public function __construct(?Repo $repo=null) {
        $r=$repo??new Repo();
        $this->weights=$r->getConfig('rank.weights')??['text'=>1.0,'recency'=>0.6,'edge'=>0.5,'centrality'=>0.2];
    }

    /**
     * Score a node candidate.
     * $cand: ['textScore'=>float, 'recencyHours'=>float, 'edgeWeight'=>float, 'centrality'=>float]
     */
    public function score(array $cand):float {
        $s=0.0;
        $s+=$this->weights['text']*($cand['textScore']??0.0);
        $s+=$this->weights['recency']*(1.0/(1.0+($cand['recencyHours']??999)/24.0));
        $s+=$this->weights['edge']*($cand['edgeWeight']??0.0);
        $s+=$this->weights['centrality']*($cand['centrality']??0.0);
        return $s;
    }
}
