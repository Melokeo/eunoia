<?php
declare(strict_types=1);

/**
 * Minimal JSON-backed task store.
 * Schema per task:
 *   {
 *     "name": string,
 *     "importance": string|null,
 *     "tags": string[]|null,
 *     "time": { "date": "YYYY-MM-DD", "start"?: "HH:MM", "end"?: "HH:MM" }|null,
 *     "updates": string[]  // AI notes
 *   }
 *
 * File layout:
 *   { "tasks": [ ... ] }
 */
final class TaskStore
{
    private string $path;
    private string $finishedPath;

    public function __construct(string $path = '/var/lib/euno/memory/tasks.json')
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['tasks' => []], JSON_PRETTY_PRINT));
            chmod($path, 0600);
        }

        $this->finishedPath = dirname($path) . '/tasks-archived.json';
        if (!file_exists($this->finishedPath)) {
            file_put_contents($this->finishedPath, json_encode(['finished' => []], JSON_PRETTY_PRINT));
            chmod($this->finishedPath, 0600);
        }

    }

    /** @return array{tasks:list<array>} */
    public function loadAll(): array
    {
        $fp = fopen($this->path, 'c+');
        if (!$fp) return ['tasks' => []];
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($raw ?: '{"tasks":[]}', true);
        if (!is_array($data) || !isset($data['tasks']) || !is_array($data['tasks'])) {
            return ['tasks' => []];
        }
        return $data;
    }

    /** @return list<array> */
    public function listAll(): array
    {
        return $this->loadAll()['tasks'];
    }

    /**
     * Upsert by composite key (name|date|start|end). Preserves and merges updates[].
     * Returns true if inserted, false if updated.
     */
    public function upsert(array $task): bool
    {
        $task = $this->normalizeTask($task);
        $key  = $this->compositeKey($task);

        $data = $this->loadAll();
        foreach ($data['tasks'] as $i => $existing) {
            if ($this->compositeKey($existing) === $key) {
                $data['tasks'][$i] = $this->mergeTask($existing, $task);
                $this->saveAll($data);
                return false;
            }
        }
        $data['tasks'][] = $task;
        $this->saveAll($data);
        return true;
    }

    /** Find by exact name (case-insensitive). Optional date filter (YYYY-MM-DD). */
    /** Backward-compat exact name (+optional date). */
    public function findByName(string $name, ?string $date = null): ?array
    {
        return $this->find($name, $date, null, null);
    }

    /** Append a single update line to a matching task; returns true if written. */
    public function appendUpdate(string $name, ?string $date, string $note): bool
    {
        $data = $this->loadAll();
        $i = $this->locateFirst($data['tasks'], $name, $date, null, null);
        if ($i === null) return false;

        $u = $data['tasks'][$i]['updates'] ?? [];
        $u[] = $note;
        $u = array_values(array_unique(array_filter(array_map('strval', $u))));
        $data['tasks'][$i]['updates'] = $u;
        $this->saveAll($data);
        return true;
    }

    // ---------- internals ----------
    /** Canonical matcher for a task against name/date/start/end. */
    private function taskMatches(array $t, string $name, ?string $date, ?string $start, ?string $end): bool
    {
        $nWant  = mb_strtolower(trim($name));
        $nHave  = mb_strtolower((string)($t['name'] ?? ''));

        $tdate  = (string)($t['time']['date']  ?? '');
        $tstart = (string)($t['time']['start'] ?? '');
        $tend   = (string)($t['time']['end']   ?? '');

        return ($nHave === $nWant)
            && ($date  === null || $tdate  === (string)$date)
            && ($start === null || $tstart === (string)$start)
            && ($end   === null || $tend   === (string)$end);
    }

    /** Locate first index matching the composite query; returns index or null. */
    private function locateFirst(array $tasks, string $name, ?string $date, ?string $start, ?string $end): ?int
    {
        foreach ($tasks as $i => $t) {
            if ($this->taskMatches($t, $name, $date, $start, $end)) return $i;
        }
        return null;
    }

    /** Find first matching task; null if none. */
    public function find(string $name, ?string $date = null, ?string $start = null, ?string $end = null): ?array
    {
        $data = $this->loadAll();
        $i = $this->locateFirst($data['tasks'], $name, $date, $start, $end);
        return $i === null ? null : $data['tasks'][$i];
    }

    /** @param array{tasks:list<array>} $data */
    private function saveAll(array $data): void
    {
        $tmp = $this->path . '.tmp';
        $fp  = fopen($tmp, 'w');
        if (!$fp) throw new \RuntimeException('Unable to write temp tasks file');
        flock($fp, LOCK_EX);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        rename($tmp, $this->path);
        chmod($this->path, 0600);
    }

    private function compositeKey(array $t): string
    {
        $date  = (string)($t['time']['date']  ?? '');
        $start = (string)($t['time']['start'] ?? '');
        $end   = (string)($t['time']['end']   ?? '');
        return mb_strtolower(trim((string)($t['name'] ?? ''))) . '|' . $date . '|' . $start . '|' . $end;
    }

    private function mergeTask(array $old, array $new): array
    {
        $merged = $old;
        foreach (['name', 'importance', 'tags', 'time'] as $k) {
            if (array_key_exists($k, $new)) $merged[$k] = $new[$k];
        }
        $merged['updates'] = array_values(array_unique(array_merge($old['updates'] ?? [], $new['updates'] ?? [])));
        return $merged;
    }

    private function normalizeTask(array $t): array
    {
        $t['name'] = (string)($t['name'] ?? '');
        if (isset($t['importance'])) $t['importance'] = (string)$t['importance'];
        if (isset($t['tags'])) $t['tags'] = array_values(array_map('strval', (array)$t['tags']));
        if (isset($t['time']) && is_array($t['time'])) {
            $tt = $t['time'];
            $out = ['date' => (string)($tt['date'] ?? '')];
            if (!empty($tt['start'])) $out['start'] = (string)$tt['start'];
            if (!empty($tt['end']))   $out['end']   = (string)$tt['end'];
            $t['time'] = $out;
        } else {
            $t['time'] = null;
        }
        $t['updates'] = array_values(array_unique(array_map('strval', $t['updates'] ?? [])));
        return $t;
    }

    /** Delete by name (case-insensitive); optionally filter by date/start/end.
     *  If start/end are not provided, deletes ALL matches for that name(+date).
     *  Returns true if at least one task was removed.
     */
    public function delete(string $name, ?string $date = null, ?string $start = null, ?string $end = null): bool
    {
        $data = $this->loadAll();
        $n = mb_strtolower(trim($name));
        
        $removed = 0;
        $keep = [];
        foreach ($data['tasks'] as $t) {
            if ($this->taskMatches($t, $name, $date, $start, $end)) {
                $removed++;
            } else {
                $keep[] = $t;
            }
        }

        if ($removed > 0) {
            $this->saveAll(['tasks' => array_values($keep)]);
            return true;
        }
        return false;
    }

    /** Pop one task by name(+optional date/start/end). Remove it and return the task; null if not found. */
    public function pop(string $name, ?string $date = null, ?string $start = null, ?string $end = null): ?array
    {
        $data = $this->loadAll();
        $i = $this->locateFirst($data['tasks'], $name, $date, $start, $end);
        if ($i === null) return null;

        $removed = $data['tasks'][$i];
        unset($data['tasks'][$i]);
        $data['tasks'] = array_values($data['tasks']);
        $this->saveAll($data);
        return $removed;
    }

    /** Finish a task: pop it from active list and archive into finished.json. */
    public function finish(string $name, ?string $date = null, ?string $start = null, ?string $end = null): ?array
    {
        $removed = $this->pop($name, $date, $start, $end);
        if ($removed === null) return null;

        // archive to finished.json
        $fp = fopen($this->finishedPath, 'c+');
        if (!$fp) throw new \RuntimeException('Unable to write finished file');
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = json_decode($raw ?: '{"finished":[]}', true);
        if (!is_array($data) || !isset($data['finished']) || !is_array($data['finished'])) {
            $data = ['finished' => []];
        }
        $data['finished'][] = $removed;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        chmod($this->finishedPath, 0600);

        return $removed;
    }



}
