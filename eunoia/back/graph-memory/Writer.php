<?php
declare(strict_types=1);

final class Writer {
    private Repo $repo;
    public function __construct(?Repo $repo=null){ $this->repo=$repo??new Repo(); }

    /** Commit a hard fact edge (idempotent via UNIQUE constraint on src,dst,type). */
    public function commitEdge(string $srcId,string $dstId,string $type,float $w=0.7,?array $attrs=null):string {
        $eid=$this->repo->newId('e_');
        $attrsJson=$attrs?json_encode($attrs,JSON_UNESCAPED_UNICODE):null;
        $this->repo->insertEdge($eid,$srcId,$dstId,$type,$w,$attrsJson);
        return $eid;
    }

    /** Promote node if confidence crosses threshold. */
    public function promoteNode(string $nodeId,float $newConf):void {
        $th=(float)($this->repo->getConfig('thresholds.promote')??0.8);
        $sql='UPDATE graph_nodes SET confidence=?, status=IF(?>=?, "promoted",status), updated_ts=CURRENT_TIMESTAMP(6) WHERE id=?';
        $this->repo->pdo()->prepare($sql)->execute([$newConf,$newConf,$th,$nodeId]);
    }
}
