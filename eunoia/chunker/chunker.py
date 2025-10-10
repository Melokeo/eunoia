import json, re, sys
import hashlib
from datetime import datetime
import pymysql
from datetime import timedelta
from pinecone import Pinecone
import regex

from post_process import post_process_text

DEBUG = 0 
DB_SECRET = "/var/lib/euno/secrets/db.json"
try:
    with open(DB_SECRET, "r") as f:
        _db = json.load(f)
    DB = dict(
        host="127.0.0.1",
        user=_db["db-usr"],
        password=_db["db-pwd"],
        database="euno",
        charset="utf8mb4",
        autocommit=True,
    )
except Exception as e:
    print(f"failed to load db config: {e}")
    DB = None

try:
    pc = Pinecone(api_key=json.load(open("/var/lib/euno/secrets/pinecone_key.json"))["api_key"])
    index = pc.Index(host="")
except Exception as e:
    print(f"failed to init pinecone: {e}")
    index = None

def simhash(text: str, bits: int = 64) -> int:
    """lightweight simhash without external lib"""
    tokens = [text[i:i+2] for i in range(len(text)-1)]  # bigrams
    v = [0] * bits
    for t in tokens:
        h = int(hashlib.blake2b(t.encode(), digest_size=8).hexdigest(), 16)
        for i in range(bits):
            v[i] += 1 if (h >> i) & 1 else -1
    return sum(1 << i for i, val in enumerate(v) if val > 0)

def dedupe(vecs: list[dict], max_hamming: int = 3) -> list[dict]:
    """remove near-duplicate chunks"""
    seen_hash = set()
    seen_sim = []
    out = []
    for v in vecs:
        h = v["metadata"]["hash"]
        if h in seen_hash:
            continue
        sh = int(v["metadata"]["simhash64"], 16)
        if any((sh ^ prior).bit_count() <= max_hamming for prior in seen_sim):
            continue
        seen_hash.add(h)
        seen_sim.append(sh)
        out.append(v)
    return out

def pre_sanitize(text: str) -> str:
    """pre-clean input contents"""
    text = re.sub(r'\~{3}(action|memory|interest|debug)\s*\n*(.*?)\~{3}', '[cmd]', text, flags=re.S)
    text = regex.sub(r'function\sresult:\s*(\{(?:[^{}]|(?1))*\})', 'function result: [result]', text)
    return text

def split_into_sentences(text: str) -> list[str]:
    """split text into sentences, CJK compatible"""
    sentences = re.split(r'(?<=[.!?])\s+|(?<=[。！？])\s*', text)
    return [s for s in sentences if s.strip()]

def print_chunks(chunks: list[dict]) -> None:
    for c in chunks:
        print(f'\n=== chunk {c["chunk_ord"]} ===')
        print(c['start_ts'])
        print(c['text'])

def chunk_messages(messages: list[dict], chunk_size: int = 200, overlap: int = 50, ts_space_min: int = 5) -> list[dict]:
    """
    Chunks a list of messages, ensuring each chunk has the correct starting timestamp.
    """
    role_map = {"user": "Mel", "assistant": "Euno"}
    
    # Step 1: Create a list of sentences, each associated with its original timestamp.
    timed_sentences = []
    last_stamp_ts = None

    if DEBUG:
        for msg in messages:
            print(msg)

    for msg in messages:
        role = role_map.get(msg["role"], msg["role"])
        content = msg["content"].strip()
        content = pre_sanitize(content)
        if not content:
            continue

        sentences_from_msg = split_into_sentences(content)
        if not sentences_from_msg:
            continue

        for sent in sentences_from_msg:
            timed_sentences.append({"text": f"{role}: {sent}", "ts": msg["ts"]})

    if DEBUG:
        for msg in timed_sentences:
            print(msg)

    chunks = slide_win_chunk(timed_sentences, chunk_size, overlap, ts_space_min)

    if DEBUG:
        print_chunks(chunks)
        return

    return chunks

def slide_win_chunk(timed_sentences: list[dict], chunk_size: int = 200, overlap: int = 50, ts_space_min: int = 5) -> list[dict]:
    chunks = []
    chunk_ord = 0
    n = len(timed_sentences)
    i = 0

    while i < n:
        chunk_start_ts = timed_sentences[i]["ts"]
        curr_len = 0
        chunk_parts = []
        last_ts = chunk_start_ts
        j = i

        while j < n:
            text = timed_sentences[j]["text"]
            ts = timed_sentences[j]["ts"]
            text_len = len(text)
            if curr_len + text_len > chunk_size and chunk_parts:
                break

            if not chunk_parts:
                chunk_parts.append(f"[{ts.strftime('%y%m%d%a %H:%M')}] {text}")
            else:
                if (ts - last_ts) >= timedelta(minutes=ts_space_min):
                    chunk_parts.append(f"[{ts.strftime('%y%m%d%a %H:%M')}] {text}")
                else:
                    chunk_parts.append(text)
            curr_len += text_len
            last_ts = ts
            j += 1

        chunk_text = "\n".join(chunk_parts)
        chunk_text = post_process_text(chunk_text)
        chunks.append({
            "text": chunk_text,
            "start_ts": chunk_start_ts,
            "chunk_ord": chunk_ord,
        })
        chunk_ord += 1

        if j >= n:
            break

        # slide window backward by overlap length (characters)
        back_len = 0
        k = j - 1
        while k > i and back_len < overlap:
            back_len += len(timed_sentences[k]["text"])
            k -= 1
        i = k + 1

    return chunks

def upsert_vectors(pc, index_name, namespace, vectors, dry_run=True, log=True):
    if not vectors:
        if log: print("no vectors to upsert")
        return

    records = [{
        "_id": v["id"],
        "chunk_text": v["metadata"]["chunk_text"],
        **v["metadata"]
    } for v in vectors]

    if dry_run:
        if log: print(f"[DRY RUN] upserts={len(records)} ns={namespace}")
        return

    try:
        index = pc.Index(host="")
        B = 96
        for i in range(0, len(records), B):
            index.upsert_records(namespace, records[i:i+B])
    except Exception as e:
        print(f"upsert_records error: {e}")

def get_last_processed_timestamp() -> str | None:
    conn = pymysql.connect(**DB)
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT MAX(last_message_ts) FROM processed_chunks")
            res = cur.fetchone()
            return res[0] if res and res[0] else None
    finally:
        conn.close()


def save_processed_timestamp(ts: datetime) -> None:
    conn = pymysql.connect(**DB)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO processed_chunks (last_message_ts, processed_at) VALUES (%s, NOW())",
                (ts,),
            )
    finally:
        conn.close()


def run(limit: int = 1000, since: str | None = None, dry_run: bool = True, log: bool = True):
    """
    process chat messages into pinecone chunks
    
    limit: max messages to process
    since: iso datetime string to filter messages (ts >= since)
    dry_run: if true, don't insert to pinecone/db
    log: print progress
    """
    if DB is None:
        raise RuntimeError("db not configured")
    
    conn = pymysql.connect(**DB)
    cur = conn.cursor(pymysql.cursors.DictCursor)

    if since is None:
        since = get_last_processed_timestamp()
    if log and since:
        print(f"resuming from last processed: {since}")

    
    try:
        query = "SELECT session_id, ts, role, content FROM messages WHERE role IN ('user', 'assistant')"
        params = []
        
        if since:
            query += " AND ts >= %s"
            params.append(since)
        
        query += " ORDER BY ts ASC LIMIT %s"
        params.append(limit)
        
        cur.execute(query, params)
        messages = cur.fetchall()
        
        if log:
            print(f"fetched {len(messages)} messages")
        
        if not messages:
            if log:
                print("no messages to process")
            return
        
        chunks = chunk_messages(messages)

        if DEBUG: return
        
        if log:
            print(f"created {len(chunks)} chunks")
        
        vectors = []
        for chunk in chunks:
            text = chunk["text"]
            start_ts = chunk["start_ts"]
            chunk_ord = chunk["chunk_ord"]
            
            shash = hashlib.sha256(text.encode()).hexdigest()
            sh = simhash(text)
            
            vid = f"chat_{start_ts.strftime('%Y%m%d_%H%M%S')}_{chunk_ord:04d}"
            
            meta = {
                "session_id": messages[0]["session_id"],
                "chunk_ord": chunk_ord,
                "start_ts": start_ts.isoformat(),
                "hash": shash,
                "simhash64": f"{sh:016x}",
                "len": len(text),
                "source": "chat",
            }
            
            vectors.append({
                "id": vid,
                "values": None,
                "metadata": {"chunk_text": text, **meta}
            })
        
        vectors = dedupe(vectors)
        
        if log:
            print(f"after dedupe: {len(vectors)} vectors")
        
        if dry_run:
            if log:
                print("dry run - would insert vectors:")
                for v in vectors[:3]:
                    # Access the text from metadata for printing
                    print(f"  {v['id']}: {v['metadata']['chunk_text'][:100]}...")
            return

        
        if index is None:
            raise RuntimeError("pinecone not configured")

        upsert_vectors(pc, "euno", "history", vectors, dry_run=dry_run, log=log)
        
        if log:
            print(f"upserted {len(vectors)} vectors to pinecone")
        
        if not dry_run and messages:
            last_ts = messages[-1]["ts"]
            save_processed_timestamp(last_ts)
    
    finally:
        cur.close()
        conn.close()
