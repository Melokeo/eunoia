# /srv/flaskiface/query_worker.py
# deps: pip install pinecone
from __future__ import annotations
import json, os, hashlib, unicodedata
from typing import Any, Dict, List, Optional
from pinecone import Pinecone

import datetime as dt
import math

PINECONE_KEY_JSON = "/var/lib/euno/secrets/pinecone_key.json"
INDEX_HOST        = "euno-7zjghs7.svc.aped-4627-b74a.pinecone.io"  # from describe_index
NAMESPACE         = "history"
FIELDS            = ["chunk_text", "session_id", "role", "chunk_ord", "start_ts", "hash", "simhash64", "len", "source"]

def _normalize(s: str) -> str:
    return unicodedata.normalize("NFC", s or "").replace("\r\n", "\n").replace("\r", "\n").strip()

def _parse_ts(s: str):
    try:
        s = (s or "").replace("Z", "+00:00")
        return dt.datetime.fromisoformat(s).astimezone(dt.timezone.utc)
    except Exception:
        return None

def _recency_weight(t: dt.datetime | None, now: dt.datetime, half_life_h: float = 168.0) -> float:
    if t is None: 
        return 0.5
    age_h = max((now - t).total_seconds() / 3600.0, 0.0)
    return 0.5 ** (age_h / max(half_life_h, 1e-6))

def _select_hits(
    hits: list,
    rmin: int = 0,
    rmax: int = 3,
    half_life_h: float = 168.0,
    alpha: float = 0.7,   # weight for normalized semantic score
    beta: float  = 0.25,  # weight for recency
    gamma: float = 0.2,   # penalty for score drop vs previous
    drop_knee: float = 0.25,  # early-stop if relative drop exceeds this and rmin satisfied
    hard_clip: float = 0.01
):
    if not hits:
        return []

    top = max((hit.get("_score") or hit.get("score") or 0.0) for hit in hits) or 1e-9
    prev = top
    now = dt.datetime.now(dt.timezone.utc)

    chosen = []
    for h in hits:
        f = h.get("fields", {})
        s_raw = (h.get("_score") or h.get("score") or 0.0)
        if s_raw < hard_clip:
            break

        s_norm = max(min(s_raw / top, 1.0), 0.0)

        t = _parse_ts(f.get("start_ts") or f.get("timestamp") or "")
        rec = _recency_weight(t, now, half_life_h)

        drop = max((prev - s_raw) / max(prev, 1e-9), 0.0)
        composite = alpha * s_norm + beta * rec - gamma * drop

        must_take = len(chosen) < rmin
        if must_take or composite >= 0.35:
            chosen.append((composite, h))
            prev = s_raw
        else:
            if drop >= drop_knee and len(chosen) >= rmin:
                break
        if len(chosen) >= rmax:
            break

    chosen.sort(key=lambda x: x[0], reverse=True)
    return [h for _, h in chosen]

class QueryWorker:
    def __init__(self, host: str = INDEX_HOST, namespace: str = NAMESPACE):
        api_key = json.load(open(PINECONE_KEY_JSON))["api_key"]
        self.pc = Pinecone(api_key=api_key)
        self.index = self.pc.Index(host=host)
        self.namespace = namespace

    def search_text(
        self,
        text: str,
        top_k: int = 12,
        flt: Optional[Dict[str, Any]] = None,
        with_rerank: bool = False,
        rerank_model: str = "bge-reranker-v2-m3",
        rerank_top_n: Optional[int] = None,
        rmin: int = 0, rmax: int = 3, half_life_h: float = 168.0,
    ) -> Dict[str, Any]:
        q = {"inputs": {"text": _normalize(text)}, "top_k": int(top_k)}
        if flt:
            q["filter"] = flt

        kwargs = {"namespace": self.namespace, "query": q, "fields": FIELDS}
        if with_rerank:
            kwargs["rerank"] = {
                "model": rerank_model,
                "top_n": int(rerank_top_n or min(top_k, 5)),
                "rank_fields": ["chunk_text"],
            }

        res = self.index.search(**kwargs)
        hits = res.get("result", {}).get("hits", [])

        # select subset
        hits = _select_hits(hits, rmin=rmin, rmax=rmax, half_life_h=half_life_h)

        # light dedupe on identical content hash or identical simhash64
        seen = set()
        out = []
        for h in hits:
            f = h.get("fields", {})
            sig = f.get("hash") or f.get("simhash64") or hashlib.sha1(f.get("chunk_text","").encode("utf-8")).hexdigest()
            if sig in seen:
                continue
            seen.add(sig)
            out.append({
                "id": h.get("_id"),
                "score": h.get("_score"),
                "chunk_text": f.get("chunk_text", ""),
                "session_id": f.get("session_id"),
                "role": f.get("role"),
                "chunk_ord": f.get("chunk_ord"),
                "start_ts": f.get("start_ts"),
            })
        return {"ok": True, "hits": out}

# simple singleton accessor
_worker: Optional[QueryWorker] = None
def get_worker() -> QueryWorker:
    global _worker
    if _worker is None:
        _worker = QueryWorker()
    return _worker

