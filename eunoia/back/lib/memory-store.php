<?php
declare(strict_types=1);

final class MemoryStore {
    private string $path;

    public function __construct(string $path = '/var/lib/euno/memory/global.json') {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        if (!file_exists($path)) {
            file_put_contents($path, json_encode(['categories'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            chmod($path, 0600);
        }
    }

    public function listByKey(): array { return $this->load(); }

    public function listAll(): array {
        $cats = $this->load();

        foreach ($cats as $k => $facts) {
            $facts = is_array($facts) ? $facts : [];
            // cast, trim, drop empties
            $facts = array_values(array_filter(array_map('strval', $facts), fn($s) => trim($s) !== ''));

            // de-duplicate while preserving order
            $seen = [];
            $facts = array_values(array_filter($facts, function ($f) use (&$seen) {
                return !isset($seen[$f]) ? $seen[$f] = true : false;
            }));

            if ($facts) $cats[$k] = $facts; else unset($cats[$k]);
        }

        if ($cats) ksort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        return $cats; // category => [facts...]
    }


    public function remember(string $fact, string $key = 'General'): void {
        $fact = trim($fact);
        $key  = $key !== '' ? $key : 'General';
        if ($fact === '') return;
        $cats = $this->load();
        $arr = $cats[$key] ?? [];
        if (!in_array($fact, $arr, true)) {
            $arr[] = $fact;
            $cats[$key] = $arr;
            $this->saveCats($cats);
        }
    }

    public function forget(string $fact, string $key = 'General'): void {
        $cats = $this->load();
        if ($key === '*') {
            foreach ($cats as $k => $arr) {
                $cats[$k] = array_values(array_filter($arr, fn($e) => $e !== $fact));
            }
        } else {
            if (isset($cats[$key])) {
                $cats[$key] = array_values(array_filter($cats[$key], fn($e) => $e !== $fact));
            }
        }
        $this->saveCats($cats);
    }


    private function save(array $entries): void {
        file_put_contents(
            $this->path,
            json_encode(['entries'=>$entries], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
        );
        chmod($this->path, 0600);
    }

    private function load(): array {
        $raw = @file_get_contents($this->path) ?: '';
        $j = json_decode($raw, true);
        $cats = is_array($j['categories'] ?? null) ? $j['categories'] : [];
        foreach ($cats as $k => $v) {
            $cats[$k] = array_values(array_filter(array_map('strval', is_array($v)?$v:[]), fn($s)=>trim($s)!==''));
        }
        return $cats;
    }

    private function saveCats(array $cats): void {
        file_put_contents(
            $this->path,
            json_encode(['categories'=>$cats], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
        );
        chmod($this->path, 0600);
    }

}
