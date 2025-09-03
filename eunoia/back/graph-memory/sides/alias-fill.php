<?php
declare(strict_types=1);

/**
 * Batched alias backfill using a single OpenAI call per batch.
 * Generates aliases for EN/ZH/FR/JA. Skips type='Task'.
 * Dedup vs existing aliases and title.
 *
 * Env: OPENAI_API_KEY, DB_DSN, DB_USER, DB_PASS
 * Flags: --limit=500  --batch=50
 */
/*const OPENAI_KEY_PATH = '/var/lib/euno/secrets/openai_key.json';
const OPENAI_API_URL  = 'https://api.openai.com/v1/responses';
const OPENAI_MODEL    = 'gpt-5-mini';*/
const OPENAI_KEY_PATH = '/var/lib/euno/secrets/deepseek_key.json';
const OPENAI_API_URL  = 'https://api.deepseek.com/chat/completions';
const OPENAI_MODEL    = 'deepseek-chat';

require_once '/var/lib/euno/sql/db-credential.php';

function load_key(): string {
    $data = json_decode(@file_get_contents(OPENAI_KEY_PATH), true);
    if (!is_array($data) || empty($data['api_key'])) {
        http_response_code(500);
        exit(json_encode(['error' => 'OpenAI key missing'], JSON_UNESCAPED_UNICODE));
    }
    return $data['api_key'];
}

$apiKey = load_key();
$dsn  = getenv('DB_DSN')  ?: 'mysql:host=127.0.0.1;dbname=euno;charset=utf8mb4';
$user = $db_usr ?: 'this shouldnt be empty';
$pass = $db_pwd ?: 'no? i just dont want to change these';
$LIMIT = 500; $BATCH = 25;
foreach ($argv as $a) {
  if (preg_match('/^--limit=(\d+)$/',$a,$m)) $LIMIT=(int)$m[1];
  if (preg_match('/^--batch=(\d+)$/',$a,$m)) $BATCH=max(1,(int)$m[1]);
}

$pdo = new PDO($dsn,$user,$pass,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->exec("SET time_zone = '+00:00'");

function rid(): string { return 'a_'.bin2hex(random_bytes(12)); }
function norm(string $s): string {
  $s = mb_strtolower(trim($s),'UTF-8');
  // keep CJK and digits; collapse spaces; do not strip hyphens inside dates/words
  $s = mb_convert_kana($s,'asKV','UTF-8');
  $s = preg_replace('/[\'"\.\,\(\)\[\]\{\}_]+/u',' ',$s); // no hyphen removal
  return trim(preg_replace('/\s+/u',' ',$s));
}

/* fetch candidates with no assistant aliases --AND a.node_id IS NULL*/ 
$st = $pdo->prepare(<<<SQL
SELECT n.id, n.type, n.title
FROM graph_nodes n
LEFT JOIN (
  SELECT DISTINCT node_id FROM graph_node_aliases WHERE source='assistant'
) a ON a.node_id=n.id
WHERE n.type <> 'Task'
ORDER BY n.created_ts ASC
LIMIT :lim
SQL);
$st->bindValue(':lim',$LIMIT,PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();
if (!$rows) { echo "No candidates.\n"; exit; }

/* cache existing titles+aliases for dedupe */
$ids = array_column($rows,'id');
$qm = implode(',',array_fill(0,count($ids),'?'));
$seen = []; // node_id => set(normalized)
$tSt = $pdo->prepare("SELECT id,title FROM graph_nodes WHERE id IN ($qm)");
$tSt->execute($ids);
foreach ($tSt as $r) $seen[$r['id']][norm($r['title'])]=true;
$aSt = $pdo->prepare("SELECT node_id,alias FROM graph_node_aliases WHERE node_id IN ($qm)");
$aSt->execute($ids);
foreach ($aSt as $r) $seen[$r['node_id']][norm($r['alias'])]=true;

$ins = $pdo->prepare("
  INSERT INTO graph_node_aliases
  (id,node_id,alias,source,weight,created_ts)
  VALUES (?,?,?,?,?,CURRENT_TIMESTAMP(6))
");


/* OpenAI batched call */
function gen_aliases_batch(array $batch, string $apiKey): array {
  // expect: [{"id":"n_x","title":"...", "type":"Person"}, ...]
  $sys = "Produce aliases for many entities at once to create entities for a knowledge graph's nodes.
Output ONLY compact JSON object: {\"items\": [{\"id\": \"<node_id>\", \"aliases\": [\"...\"]}, ...]}
Plain json text, no code fence.
Rules per entity:
- Three to twelve unique aliases across English, Chinese (simplified), keep both balanced.
- May include important synonyms, possible abbreviations, nicknames, plural/singular, transliterations.
- Avoid duplicate aliases.
- Exclude items identical to title ignoring case/width. No punctuation variants, URLs, emojis, or explanations.
- Do not add hallucinated details
";
  $payload = [
    'model' => OPENAI_MODEL,
    'messages' => [ //input
      ['role'=>'system','content'=>$sys],
      ['role'=>'user','content'=>json_encode(['items'=>$batch], JSON_UNESCAPED_UNICODE)],
    ],
    //'text' => [ 'verbosity' => 'medium' ],
    //'reasoning' => [ 'effort' => 'medium' ],
    'temperature' => 0.1,
    'max_output_tokens'=>8192,
  ];
  $ch=curl_init(OPENAI_API_URL);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>[
      'Authorization: Bearer '.$apiKey,
      'Content-Type: application/json',
    ],
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT=>900,
  ]);
  $raw=curl_exec($ch);
  if ($raw===false) throw new RuntimeException('HTTP: '.curl_error($ch));
  $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($code<200||$code>=300) throw new RuntimeException("API $code: $raw");
  echo $raw;
  $j=json_decode($raw,true);
  // $text=$j['output_text'] ?? ($j['output'][0]['content'][0]['text'] ?? ($j['output'][1]['content'][0]['text'] ?? ''));
  $text = $j['choices'][0]['message']['content'] ?? '';
  $text = preg_replace('/```json/', '', $text);
  $text = preg_replace('/```/', '', $text);
  echo $text;


  $obj=json_decode(trim($text),true);
  echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
  if (!isset($obj['items']) || !is_array($obj['items'])) return [];
  $out=[];
  foreach ($obj['items'] as $it) {
    $id=$it['id']??null; $als=$it['aliases']??[];
    if (!is_string($id) || !is_array($als)) continue;
    $clean=[];
    foreach ($als as $s) if (is_string($s) && ($s=trim($s))!=='') $clean[]=$s;
    $out[$id]=$clean;
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
  return $out;
}

/* process in batches */
$addedAll=0;
for ($i=0; $i<count($rows); $i+=$BATCH) {
  $chunk = array_slice($rows,$i,$BATCH);
  // prepare minimal batch payload
  $payload = array_map(fn($r)=>['id'=>$r['id'],'title'=>$r['title'],'type'=>$r['type']], $chunk);
  try {
    $map = gen_aliases_batch($payload,$apiKey); // id => aliases[]
  } catch (Throwable $e) {
    fwrite(STDERR,"Batch ".($i/$BATCH).": ".$e->getMessage()."\n");
    continue;
  }
    echo json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

  foreach ($chunk as $n) {
    $nid=$n['id']; $title=$n['title'];
    $cands=$map[$nid] ?? [];
    if (!$cands) { echo "$nid \"$title\": +0\n"; continue; }

    $set = $seen[$nid] ?? [];
    $added=0;
        
    $sk_seen=0; $sk_err=0;
    foreach ($cands as $cand) {
    $k = norm($cand);
    if ($k === '' || isset($set[$k])) { $sk_seen++; continue; }

    $weight = max(0.50, min(2.00, 1.50 - 0.01*mb_strlen($cand,'UTF-8')));
    try {
        $ins->execute([rid('a_'), $nid, $cand, 'assistant', $weight]);
        $set[$k]=true; $added++;
    } catch (Throwable $e) {
        $sk_err++;
        fwrite(STDERR, "INSERT err nid=$nid alias=\"$cand\": ".$e->getMessage()."\n");
    }
    }
    echo "$nid \"$title\": +$added (seen:$sk_seen, dberr:$sk_err)\n";

  }
}

echo "Done. Inserted $addedAll aliases.\n";
