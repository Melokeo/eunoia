<?php
// /var/lib/euno/graph-memory/Graph.php
declare(strict_types=1);

/**
 * Graph facade: stable surface for retrieval and logging.
 * No JSON leaves this class; callers receive plain text for injection.
 */
final class Graph
{
    private Repo $repo;
    private Schema $schema;

    public function __construct(?Repo $repo = null, ?Schema $schema = null)
    {
        $this->repo   = $repo   ?? new Repo();
        $this->schema = $schema ?? new Schema();
    }

    /**
     * Build a compact memory block for the given utterance and session.
     * Returns a plain-text block (≤100 lines) or empty string if nothing relevant.
     */
    public function retrieveText(string $sid, string $utterance): string
    {
        $retrieval = new Retrieval($this->repo);
        $sub = $retrieval->subgraph([]); // seeds to be passed later
        return Render::pack($sub, [], 30, 1);
    }

    /**
     * Log a turn’s detector output and link to existing message ids.
     * Returns the generated turn id.
     *
     * @param array $det Detector output as PHP array (intent/entities/slots/scores).
     */
    public function logTurn(string $sid, int $userMsgId, ?int $assistantMsgId, array $det): string
    {
        $turnId = $this->repo->newId('t_');

        $detectorVersion = (string)($det['detector_version'] ?? ($this->repo->getConfig('detector.version') ?? 'v1'));
        $intentLabel     = isset($det['intent']['label']) ? (string)$det['intent']['label'] : null;
        $scoresJson      = isset($det['intent']['score']) ? json_encode(['intent'=>$det['intent']['score']], JSON_UNESCAPED_UNICODE) : null;
        $detectionsJson  = isset($det['entities'])        ? json_encode($det['entities'],    JSON_UNESCAPED_UNICODE) : null;
        $slotsJson       = isset($det['slots'])           ? json_encode($det['slots'],       JSON_UNESCAPED_UNICODE) : null;

        $this->repo->insertTurn([
            'id'               => $turnId,
            'sid'              => $sid,
            'user_msg_id'      => $userMsgId,
            'assistant_msg_id' => $assistantMsgId,
            'intent'           => $intentLabel,
            'scores_json'      => $scoresJson,
            'detections_json'  => $detectionsJson,
            'slots_json'       => $slotsJson,
            'detector_version' => $detectorVersion,
        ]);

        // Enqueue lightweight post-turn job (runs inline later).
        $this->repo->enqueueJob('turn_summarize', ['turn_id' => $turnId]);

        return $turnId;
    }
}
