<?php
// /var/lib/euno/graph-memory/Detector.php
declare(strict_types=1);

/**
 * Lightweight detector. No external NLP.
 * Heuristics: intent, entities (Task/Person/Project/Time/Quantity/Preference), slots.
 */
final class Detector
{
    public function detect(string $utterance): array
    {
        $text = $this->norm($utterance);
        $intent = $this->inferIntent($text);
        [$entities, $slots] = $this->extractEntitiesAndSlots($text);

        // --- Logging ---
        $names = [];
        foreach ($entities as $e) $names[] = $e['norm'];
        $line = sprintf(
            "[%s] %s | entities: %s\n",
            gmdate('Y-m-d H:i:s'),
            str_replace(["\n","\r"], ' ', $text),
            implode(', ', $names)
        );
        @file_put_contents('/var/log/euno/detector.log', $line, FILE_APPEND);

        return [
            'detector_version' => 'v1',
            'intent' => ['label' => $intent, 'score' => 0.7],
            'entities' => $entities,
            'slots' => $slots,
        ];
    }

    // intent via scorer
    private function inferIntent(string $t): string {
        $l = $this->norm($t);
        $scores = $this->intentScore($l);
        arsort($scores);
        $top = array_key_first($scores);
        $max = $scores[$top];
        if ($max <= 0) return $this->isQuestion($l) ? 'query' : 'other';
        if ($scores['schedule'] >= $scores['plan'] && $this->hasTimeCue($l)) return 'schedule';
        return $top;
    }

    // normalizer
    private function norm(string $t): string {
        $t = preg_replace('/\s+/u',' ', trim($t));
        $t = strtr($t, ['，'=>',','。'=>'.','：'=>':','；'=>';','？'=>'?','！'=>'!','（'=>'(', '）'=>')','「'=>'"','」'=>'"','『'=>'"','』'=>'"','、'=>',']);
        return mb_strtolower($t,'UTF-8').'-';
    }

    // language hints (routing only)
    private function langHints(string $t): array {
        return [
            'has_zh' => (bool)preg_match('/\p{Han}/u',$t),
            'has_fr' => (bool)preg_match('/[àâçéèêëîïôùûüÿœæ]/u',$t),
        ];
    }
    // NEW: helpers used by scorer
    private function hasNegation(string $l): bool {
        return (bool)preg_match('~\b(no|not|don\'t|do not|无|不要|别|不|ne\s+pas|pas\b)~u',$l);
    }
    private function hasTimeCue(string $l): bool {
        return (bool)preg_match('~\b(?:\d{1,2}(:\d{2})?\s*(am|pm))\b|(?:\b\d{1,2}h(\d{2})?\b)|(?:\b(?:today|tomorrow|tmrw|tmr|next (week|month))\b)|明天|今天|下周|下个月~u',$l);
    }
    private function isQuestion(string $t): bool {
        return str_contains($t,'?') || (bool)preg_match('~\b(who|what|when|where|why|how|qui|quoi|quand|où|pourquoi|comment|谁|什么|什么时候|哪里|为何|如何)\b~u',$t);
    }
    private function looksImperative(string $l): bool {
        return (bool)preg_match('~^\s*(schedule|finish|complete|assign|plan|call|meet|安排|完成|指派|计划|预约|planifier|terminer|attribuer|rendez[-\s]?vous)\b~u',$l);
    }

    // CHANGED: weighted scorer uses typo-tolerance
    private function intentScore(string $l): array {
        $score = ['plan'=>0,'preference'=>0,'assign'=>0,'schedule'=>0,'query'=>0];

        $lex = [
        'plan' => ['todo','to do','finish','complete','deadline','due','by ','截止','到…之前','期限','finir','terminer','échéance','d\'ici','avant '],
        'preference' => ['prefer','like','dislike','喜欢','偏好','更喜欢','n\'aime pas','préfér'],
        'assign' => ['assign','delegate','owner','responsible','指派','分配','负责人','assigner','attribuer','déléguer','responsable'],
        'schedule' => ['meeting','schedule','call','appoint','rdv','rendez','会议','安排','预约','réunion','planifier','programmer'],
        'query' => ['who','what','when','where','why','how','谁','什么','什么时候','哪里','为何','如何','qui','quoi','quand','où','pourquoi','comment'],
        ];

        foreach ($lex as $k=>$arr) {
            if ($this->containsAny($l,$arr) || $this->containsAnyFuzzy($l,$arr)) $score[$k]+=1.0;
        }

        if ($this->hasTimeCue($l)) $score['schedule']+=1.0;
        if ($this->looksImperative($l)) { $score['plan']+=0.7; $score['assign']+=0.5; }
        if ($this->isQuestion($l)) $score['query']+=1.0;
        if (preg_match('~\bby\b|\b之前\b|d\'ici|avant\b~u',$l)) $score['plan']+=0.5;
        if ($this->containsAnyFuzzy($l, ['prefer','喜欢','préfér'])) $score['preference']+=0.7;

        if ($this->hasNegation($l)) { $score['preference']+=0.3; $score['plan']+=0.1; }
        return $score;
    }

    // NEW: typo-tolerant lexicon matching
    private function containsAny(string $l, array $terms): bool {
        foreach ($terms as $w) {
            if (mb_strpos($l, $w) !== false) return true;
        }
        return false;
    }
    private function containsAnyFuzzy(string $l, array $terms): bool {
        static $fuzzyMap = [
            // EN
            'prefer'    => ['preffer','prefr','prefered','preffered','preferr','prefrer'],
            'like'      => ['liek','lke'],
            'dislike'   => ['dislke','disliek'],
            'assign'    => ['assgin','asign','assigne'],
            'delegate'  => ['deleagte','delagate','delegte'],
            'owner'     => ['owenr'],
            'responsible'=>['responsable','repsonsible','responisble'],
            'meeting'   => ['meetng','meting','meetign'],
            'schedule'  => ['scheduel','schedual','schedul'],
            'appoint'   => ['appint','apoint','appointmnet'],
            'tomorrow'  => ['tomorow','tmrw','tmr'],
            'deadline'  => ['deadlne','deadeline'],
            'complete'  => ['compelete','complet','cmplete'],
            'finish'    => ['finsh','finsih'],
            // FR
            'préfér'    => ['preferer','prefere','préfere','préfer','prefferer'],
            'réunion'   => ['reunion','reuinion','reunoin'],
            'planifier' => ['planifer','planifir','plannifier'],
            'terminer'  => ['termnier','ternimer'],
            'attribuer' => ['atribuer','attrubuer'],
            'échéance'  => ['echeance','echéance','écheace'],
            'avant '    => ['avnt ','avan '],
            // ZH: no fuzzy needed
        ];
        foreach ($terms as $w) {
            if (mb_strpos($l,$w)!==false) return true;
            if (isset($fuzzyMap[$w])) {
                foreach ($fuzzyMap[$w] as $v) {
                    if (mb_strpos($l,$v)!==false) return true;
                }
            }
        }
        return false;
    }




    // ~ Replace entire method body
    private function extractEntitiesAndSlots(string $t): array
    {
        $entities = [];
        $slots = [];

        // Google NER
        $res = $this->gcloudAnalyzeEntities($t);
        foreach (($res['entities'] ?? []) as $e) {
            $type = $this->mapGoogleType((string)($e['type'] ?? 'OTHER'));
            $name = (string)($e['name'] ?? '');
            $sal  = (float)($e['salience'] ?? 0.5);
            foreach (($e['mentions'] ?? []) as $m) {
                $mtxt = (string)($m['text']['content'] ?? $name);
                $beg  = $m['text']['beginOffset'] ?? null; // UTF16 units
                $span = $this->spanFromMention($t, $mtxt, is_int($beg) ? $beg : null);
                $norm = $name !== '' ? $name : $mtxt;
                $entities[] = [
                    'type'  => $type,
                    'text'  => $mtxt,
                    'span'  => $span,
                    'norm'  => $norm,
                    'score' => $sal,
                ];
            }
        }

        // Lightweight slot inference kept
        if (preg_match('/\bby\b/i', $t) && !empty($res['entities'])) {
            foreach ($entities as $en) { if ($en['type']==='Time') { $slots['deadline'] = $en['norm']; break; } }
        }
        if (preg_match('/\bprefer(?:s|red)?\s+([^\.;,]+)$/i', $t, $m, PREG_OFFSET_CAPTURE)) {
            $pref = trim($m[1][0]);
            $slots['preference'] = $pref;
            $entities[] = ['type'=>'Preference','text'=>$pref,'span'=>[$m[1][1], $m[1][1]+mb_strlen($pref,'UTF-8')],'norm'=>$pref,'score'=>0.8];
        }

        $this->injectCustomNames($t, $entities);

        return [$entities, $slots];
    }

    

    private function extractDates(string $t): array
    {
        $res = [];
        // ISO YYYY-MM-DD
        if (preg_match_all('/\b(20[2-9]\d-\d{2}-\d{2})\b/', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[1] as $m) $res[] = ['raw' => $m[0], 'iso' => $m[0], 'span' => [$m[1], $m[1] + strlen($m[0])]];
        }
        // MM/DD or M/D
        if (preg_match_all('/\b(\d{1,2}\/\d{1,2})\b/', $t, $ms, PREG_OFFSET_CAPTURE)) {
            $year = (int)date('Y');
            foreach ($ms[1] as $m) {
                [$mm, $dd] = array_map('intval', explode('/', $m[0]));
                $iso = sprintf('%04d-%02d-%02d', $year, $mm, $dd);
                $res[] = ['raw' => $m[0], 'iso' => $iso, 'span' => [$m[1], $m[1] + strlen($m[0])]];
            }
        }
        // Natural words
        $map = [
            'today' => 0, 'tomorrow' => 1, 'tmr' => 1,
            'next week' => 7, 'next month' => 30,
        ];
        foreach ($map as $kw => $add) {
            if (preg_match('/\b'.preg_quote($kw,'/').'\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
                $iso = date('Y-m-d', strtotime("+$add day"));
                $res[] = ['raw' => $m[0][0], 'iso' => $iso, 'span' => [$m[0][1], $m[0][1] + strlen($m[0][0])]];
            }
        }
        // Weekday like Monday
        if (preg_match('/\b(mon|tue|wed|thu|fri|sat|sun)(day)?\b/i', $t, $m, PREG_OFFSET_CAPTURE)) {
            $iso = date('Y-m-d', strtotime('next ' . $m[0][0]));
            $res[] = ['raw' => $m[0][0], 'iso' => $iso, 'span' => [$m[0][1], $m[0][1] + strlen($m[0][0])]];
        }
        return $res;
    }

    private function extractQuantities(string $t): array
    {
        $out = [];
        if (preg_match_all('/\b(\d+(?:\.\d+)?)\s*(kg|g|lb|lbs|gb|mb|ms|s|min|h|hours?)\b/i', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[0] as $i => $m) {
                $raw = $m[0];
                $start = $m[1];
                $val = (float)$ms[1][$i][0];
                $unit = strtolower($ms[2][$i][0]);
                $out[] = ['raw' => $raw, 'value' => $val, 'unit' => $unit, 'span' => [$start, $start + strlen($raw)]];
            }
        }
        return $out;
    }

    private function extractQuotedOrTitleCase(string $t): array
    {
        $out = [];
        // Quoted “...” or '...'
        if (preg_match_all('/[\'"“”](.+?)[\'"“”]/u', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[1] as $m) {
                $txt = trim($m[0]);
                if ($txt !== '') $out[] = ['text' => $txt, 'span' => [$m[1], $m[1] + mb_strlen($txt, 'UTF-8')]];
            }
        }
        // Title Case chunk of 2–5 words
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z0-9]+){1,4})\b/u', $t, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[1] as $m) {
                $txt = trim($m[0]);
                if (mb_strlen($txt, 'UTF-8') >= 3) $out[] = ['text' => $txt, 'span' => [$m[1], $m[1] + mb_strlen($txt, 'UTF-8')]];
            }
        }
        return $out;
    }


    // + Add: load API key
private function googleApiKey(): string {
    $p = '/var/lib/euno/secrets/google/NER.json';
    $j = @file_get_contents($p);
    if ($j === false) throw new \RuntimeException("NER key file not readable: $p");
    $o = json_decode($j, true);
    $k = (string)($o['api_key'] ?? '');
    if ($k === '') throw new \RuntimeException("NER api_key missing in $p");
    return $k;
}

// + Add: call Cloud NL REST
private function gcloudAnalyzeEntities(string $text): array {
    $url = 'https://language.googleapis.com/v1/documents:analyzeEntities?key='.$this->googleApiKey();
    $payload = json_encode([
        'document' => ['type' => 'PLAIN_TEXT', 'content' => $text],
        // Use UTF16 so beginOffset aligns with user-perceived characters.
        'encodingType' => 'UTF8',
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_REFERER => 'https://melokeo.icu/',
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) throw new \RuntimeException('NER HTTP error: '.curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) throw new \RuntimeException("NER HTTP $code: $resp");
    $out = json_decode($resp, true);
    return is_array($out) ? $out : [];
}

// + Add: Google → internal type map
private function mapGoogleType(string $t): string {
    static $m = [
        'PERSON'=>'Person','LOCATION'=>'Location','ORGANIZATION'=>'Organization',
        'EVENT'=>'Event','WORK_OF_ART'=>'Work','CONSUMER_GOOD'=>'Item',
        'PHONE_NUMBER'=>'Phone','ADDRESS'=>'Address','DATE'=>'Time',
        'NUMBER'=>'Quantity','PRICE'=>'Quantity','OTHER'=>'Entity',
    ];
    return $m[$t] ?? 'Entity';
}

// + Add: safe span from beginOffset or fallback search
private function spanFromMention(string $full, string $mention, ?int $beginOffset): array {
    // Find first case-insensitive occurrence, get byte offsets.
    if (preg_match('/'.preg_quote($mention, '/').'/iu', $full, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1];                // byte offset
        $len   = strlen($m[0][0]);        // byte length
        return [$start, $start + $len];
    }
    // Fallback: case-insensitive search
    $pos = stripos($full, $mention);
    $start = ($pos === false) ? 0 : $pos;
    return [$start, $start + strlen($mention)];
}


// --- Compact utilities ---
private function ci(string $s): string { return mb_strtolower($s,'UTF-8'); }
private function overlaps(array $a, array $b): bool { return max($a[0],$b[0]) < min($a[1],$b[1]); }

// Find all spans (case-insensitive). If $word=true, require non-letter boundaries.
private function findSpans(string $text, string $needle, bool $word=false): array {
    $n = preg_quote($needle, '/');
    $pat = $word
        ? '/(?<![\p{L}\p{N}_])'.$n.'(?![\p{L}\p{N}_])/iu'
        : '/'.$n.'/iu';
    if (!preg_match_all($pat, $text, $m, PREG_OFFSET_CAPTURE)) return [];
    $spans = [];
    foreach ($m[0] as $hit) {
        $start = $hit[1];              // byte offset
        $len   = strlen($hit[0]);      // byte length
        $end   = $start + $len;
        $spans[] = [$start, $end];
        // echo "[findSpans] needle=\"{$needle}\" span=[{$start},{$end}] text=\"{$hit[0]}\"\n";
    }

    return $spans;
}

// --- Catalog: add variants here ---
private function customNameCatalog(): array {
    return [
        ['norm'=>'Mel',    'type'=>'Person', 'variants'=>['mel xu','@mel','mel'],   'word'=>['mel'=>true]],
        ['norm'=>'Eunoia', 'type'=>'Person',  'variants'=>['eunoia','@euno','euno'], 'word'=>['euno'=>true]],
    ];
}

// Remove any existing entity whose norm matches (case-insensitive)
private function dropByNorm(array &$entities, string $norm): void {
    $n = $this->ci($norm);
    $entities = array_values(array_filter($entities, fn($e)=>$this->ci((string)($e['norm']??'')) !== $n));
}

// Build occupied spans from current entities
private function occupiedSpans(array $entities): array {
    $occ = [];
    foreach ($entities as $e) { if (!empty($e['span'])) $occ[] = $e['span']; }
    return $occ;
}

// Main injector: one entity per norm, best non-overlapping variant
private function injectCustomNames(string $text, array &$entities): void {
    $occ = $this->occupiedSpans($entities);

    foreach ($this->customNameCatalog() as $entry) {
        $norm = $entry['norm']; $type = $entry['type'];
        $vars = $entry['variants']; $wordFlags = $entry['word'] ?? [];

        // ensure only one per norm
        $this->dropByNorm($entities, $norm);

        // collect candidates from all variants
        $cands = [];
        foreach ($vars as $v) {
            $word = !empty($wordFlags[$this->ci($v)]);
            foreach ($this->findSpans($text, $v, $word) as $sp) {
                $len = $sp[1]-$sp[0];
                $txt = substr($text, $sp[0], $len);
                $cands[] = ['span'=>$sp, 'len'=>$len, 'start'=>$sp[0], 'text'=>$txt];
            }
        }
        if (!$cands) continue;

        // prefer longer match, then earlier
        usort($cands, fn($a,$b)=>($b['len']<=>$a['len']) ?: ($a['start']<=>$b['start']));

        // pick first non-overlapping
        $best = null;
        foreach ($cands as $c) {
            $ok = true;
            foreach ($occ as $o) { if ($this->overlaps($c['span'],$o)) { $ok=false; break; } }
            if ($ok) { $best = $c; break; }
        }
        if ($best === null) continue;

        $entities[] = ['type'=>$type,'text'=>$best['text'],'span'=>$best['span'],'norm'=>$norm,'score'=>0.9];
        $occ[] = $best['span']; // reserve
    }
}



}
