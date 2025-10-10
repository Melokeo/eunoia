<?php
declare(strict_types=1);

/**
 * dida365 task operations - create, update, complete, delete
 * all actions logged to /var/log/euno/dida.log
 */

final class DidaOps
{
    private string $baseUrl;
    private string $token;
    private string $projectId;
    private const LOG_PATH = '/var/log/euno/dida.log';

    private function __construct(string $token, string $projectId, string $baseUrl)
    {
        $this->token = $token;
        $this->projectId = $projectId;
        $this->baseUrl = $baseUrl;
    }

    public static function fromFile(string $path = '/var/lib/euno/secrets/dida_tokens.json'): ?self
    {
        $tok = json_decode(@file_get_contents($path), true);
        if (!is_array($tok) || empty($tok['access_token']) || empty($tok['target_project_id'])) {
            return null;
        }
        return new self(
            $tok['access_token'],
            $tok['target_project_id'],
            $tok['base_url'] ?? 'https://api.dida365.com'
        );
    }

    /** extract TID=xxx from updates array */
    public function extractTid(array $task): ?string
    {
        foreach ($task['updates'] ?? [] as $u) {
            if (preg_match('/^TID=([a-f0-9]+)$/i', trim($u), $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /** create task on dida365, returns tid or null */
    public function create(array $task): ?string
    {
        $time = $task['time'] ?? [];
        $date = $time['date'] ?? '';
        $start = $time['start'] ?? null;
        $end = $time['end'] ?? null;
        $name = $task['name'];
        $updates = $task['updates'] ?? [];

        $payload = [
            'title' => $name,
            'projectId' => $this->projectId
        ];

        // add content from updates (filter out TID lines)
        $contentLines = array_filter($updates, fn($u) => !preg_match('/^TID=/i', trim($u)));
        if (!empty($contentLines)) {
            $payload['content'] = implode("\n", $contentLines);
        }

        if ($date) {
            $tz = new DateTimeZone('America/New_York');
            $isAllDay = empty($start);
            
            if ($isAllDay) {
                // default all-day to 9am NY time, convert to UTC
                $dt = new DateTime($date . ' 09:00:00', $tz);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $payload['dueDate'] = $dt->format('Y-m-d\TH:i:s') . '+0000';
                $payload['isAllDay'] = false;
                $payload['timeZone'] = 'America/New_York';
                $payload['reminders'] = ['TRIGGER:PT0S'];
                $this->log("create all-day task (9am): '$name' on $date");
            } else {
                if ($end) {
                    // task with start and end time
                    $startDt = new DateTime($date . ' ' . $start, $tz);
                    $endDt = new DateTime($date . ' ' . $end, $tz);
                    // convert to UTC
                    $startDt->setTimezone(new DateTimeZone('UTC'));
                    $endDt->setTimezone(new DateTimeZone('UTC'));
                    $payload['startDate'] = $startDt->format('Y-m-d\TH:i:s') . '+0000';
                    $payload['dueDate'] = $endDt->format('Y-m-d\TH:i:s') . '+0000';
                    $payload['isAllDay'] = false;
                    $payload['timeZone'] = 'America/New_York';
                    $payload['reminders'] = ['TRIGGER:PT0S'];
                    $this->log("create timed task: '$name' on $date $start-$end");
                } else {
                    // task with only start time
                    $dt = new DateTime($date . ' ' . $start, $tz);
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $payload['dueDate'] = $dt->format('Y-m-d\TH:i:s') . '+0000';
                    $payload['isAllDay'] = false;
                    $payload['timeZone'] = 'America/New_York';
                    $payload['reminders'] = ['TRIGGER:PT0S'];
                    $this->log("create timed task: '$name' on $date $start");
                }
            }
        } else {
            $this->log("create task without date: '$name'");
        }

        // map importance to priority: high=5, medium=3, low=1
        $imp = strtolower($task['importance'] ?? '');
        if ($imp === 'high') {
            $payload['priority'] = 5;
        } elseif ($imp === 'medium') {
            $payload['priority'] = 3;
        } elseif ($imp === 'low') {
            $payload['priority'] = 1;
        }

        $result = $this->http('POST', '/open/v1/task', $payload);
        $tid = $result['id'] ?? null;
        
        if ($tid) {
            $this->log("created task '$name' with TID=$tid" . (!empty($contentLines) ? " (with notes)" : ""));
        } else {
            $this->log("failed to create task '$name'");
        }
        
        return $tid;
    }
    
    /** update task on dida365 */
    public function update(string $tid, array $task): bool
    {
        $time = $task['time'] ?? [];
        $date = $time['date'] ?? '';
        $start = $time['start'] ?? null;
        $end = $time['end'] ?? null;
        $name = $task['name'];
        $updates = $task['updates'] ?? [];

        $payload = [
            'id' => $tid,
            'projectId' => $this->projectId,
            'title' => $name
        ];

        // add content from updates (filter out TID lines)
        $contentLines = array_filter($updates, fn($u) => !preg_match('/^TID=/i', trim($u)));
        if (!empty($contentLines)) {
            $payload['content'] = implode("\n", $contentLines);
        }

        if ($date) {
            $tz = new DateTimeZone('America/New_York');
            $isAllDay = empty($start);
            
            if ($isAllDay) {
                // default all-day to 9am NY time, convert to UTC
                $dt = new DateTime($date . ' 09:00:00', $tz);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $payload['dueDate'] = $dt->format('Y-m-d\TH:i:s') . '+0000';
                $payload['isAllDay'] = false;
                $payload['timeZone'] = 'America/New_York';
                $payload['reminders'] = ['TRIGGER:PT0S'];
            } else {
                if ($end) {
                    $startDt = new DateTime($date . ' ' . $start, $tz);
                    $endDt = new DateTime($date . ' ' . $end, $tz);
                    $startDt->setTimezone(new DateTimeZone('UTC'));
                    $endDt->setTimezone(new DateTimeZone('UTC'));
                    $payload['startDate'] = $startDt->format('Y-m-d\TH:i:s') . '+0000';
                    $payload['dueDate'] = $endDt->format('Y-m-d\TH:i:s') . '+0000';
                    $payload['isAllDay'] = false;
                    $payload['timeZone'] = 'America/New_York';
                    $payload['reminders'] = ['TRIGGER:PT0S'];
                } else {
                    $dt = new DateTime($date . ' ' . $start, $tz);
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $payload['dueDate'] = $dt->format('Y-m-d\TH:i:s') . '+0000';
                    $payload['isAllDay'] = false;
                    $payload['timeZone'] = 'America/New_York';
                    $payload['reminders'] = ['TRIGGER:PT0S'];
                }
            }
        }

        // map importance to priority
        $imp = strtolower($task['importance'] ?? '');
        if ($imp === 'high') {
            $payload['priority'] = 5;
        } elseif ($imp === 'medium') {
            $payload['priority'] = 3;
        } elseif ($imp === 'low') {
            $payload['priority'] = 1;
        }

        $ok = $this->http('POST', "/open/v1/task/{$tid}", $payload) !== null;
        
        if ($ok) {
            $this->log("updated task '$name' (TID=$tid)" . (!empty($contentLines) ? " (with notes)" : ""));
        } else {
            $this->log("failed to update task '$name' (TID=$tid)");
        }
        
        return $ok;
    }

    /** complete task on dida365 */
    public function complete(string $tid): bool
    {
        $url = "/open/v1/project/{$this->projectId}/task/{$tid}/complete";
        $ok = $this->http('POST', $url) !== null;
        
        if ($ok) {
            $this->log("completed task TID=$tid");
        } else {
            $this->log("failed to complete task TID=$tid");
        }
        
        return $ok;
    }

    /** delete task on dida365 */
    public function delete(string $tid): bool
    {
        $url = "/open/v1/project/{$this->projectId}/task/{$tid}";
        $ok = $this->http('DELETE', $url) !== null;
        
        if ($ok) {
            $this->log("deleted task TID=$tid");
        } else {
            $this->log("failed to delete task TID=$tid");
        }
        
        return $ok;
    }

    /** find remote task by name+date, returns tid or null */
    public function findByName(string $name, ?array $time): ?string
    {
        $url = "/open/v1/project/{$this->projectId}/data";
        $data = $this->http('GET', $url);
        if (!$data || empty($data['tasks'])) {
            $this->log("findByName: project data fetch failed or empty");
            return null;
        }

        $targetDate = $time['date'] ?? '';
        $targetName = mb_strtolower(trim($name));

        foreach ($data['tasks'] as $t) {
            if (($t['status'] ?? 0) !== 0) continue; // skip completed

            $title = mb_strtolower(trim($t['title'] ?? ''));
            if ($title !== $targetName) continue;

            // match date if provided
            if ($targetDate) {
                $dueDate = $t['dueDate'] ?? '';
                if ($dueDate && strpos($dueDate, $targetDate) !== 0) continue;
            }

            $tid = $t['id'] ?? null;
            if ($tid) {
                $this->log("found remote task '$name' with TID=$tid");
            }
            return $tid;
        }
        
        $this->log("remote task '$name' not found");
        return null;
    }

    // --- internals ---

    private function http(string $method, string $endpoint, ?array $body = null): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            "Authorization: Bearer {$this->token}",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code >= 400) {
            $this->log("HTTP $method $endpoint failed with code $code");
            return null;
        }
        if ($res === '') return []; // no content ok for complete/delete

        $json = json_decode($res, true);
        return is_array($json) ? $json : null;
    }

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        @file_put_contents(self::LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    }
}