<?php
declare(strict_types=1);

final class MemoryStore {
    private string $path;

    public function __construct(string $path = '/var/lib/euno/memory/global.json') {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['entries'=>[]], JSON_PRETTY_PRINT));
            chmod($path, 0600);
        }
    }

    /** @return string[] */
    public function listAll(): array {
        $raw = @file_get_contents($this->path);
        $j = json_decode($raw ?: '{"entries":[]}', true);
        return is_array($j['entries'] ?? null) ? $j['entries'] : [];
    }

    /** Append a fact if not already present */
    public function remember(string $fact): void {
        $fact = trim($fact);
        if ($fact === '') return;
        $all = $this->listAll();
        if (in_array($fact, $all, true)) return;
        $all[] = $fact;
        $this->save($all);
    }

    /** Delete fact by exact match */
    public function forget(string $fact): void {
        $all = $this->listAll();
        $new = array_values(array_filter($all, fn($e) => $e !== $fact));
        $this->save($new);
    }

    private function save(array $entries): void {
        file_put_contents(
            $this->path,
            json_encode(['entries'=>$entries], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
        );
        chmod($this->path, 0600);
    }
}
