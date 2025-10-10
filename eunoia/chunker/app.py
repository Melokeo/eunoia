
from flask import Flask, request, jsonify
import os, json
from pinecone import Pinecone, ServerlessSpec
import logging
from query_worker import get_worker
import chunker

lg = logging.getLogger(__name__)
qw = get_worker()

KEY_PATH = '/var/lib/euno/secrets/pinecone_key.json'
IND_NAME = 'euno'
DIM = 1536
REG = 'us-east-1'

try:
    with open(KEY_PATH, 'r') as f:
        api_key = json.load(f)["api_key"]
except (FileNotFoundError, ValueError) as e:
    lg.error(f'Failed reading api key: {e}')
    exit()

pc = Pinecone(api_key=api_key)

if not IND_NAME in [i.get('name', None) for i in pc.list_indexes()]:
    pc.create_index(
            name=IND_NAME,
            dimension=DIM,
            metric='cosine',
            spec=ServerlessSpec(cloud='aws', region=REG),
    )
    lg.info(f'Created index {IND_NAME}')

ind = pc.Index(IND_NAME)
app = Flask(__name__)

@app.get('/health')
def health():
    return {'ok': True,  "index": IND_NAME}

@app.post("/upsert")
def upsert():
    body = request.get_json(force=True)
    ns = body.get("namespace", "kb")
    ind.upsert(vectors=body["vectors"], namespace=ns)
    return {"ok": True}

@app.post("/q")
def q():
    body = request.get_json(force=True, silent=True) or {}
    text = body.get("text", "")
    top_k = int(body.get("topK", 12))
    flt   = body.get("filter")
    rr    = bool(body.get("rerank", False))

    rmin = int(body.get("rmin", 0))
    rmax = int(body.get("rmax", 3))

    res = qw.search_text(text=text, top_k=top_k, flt=flt, with_rerank=rr, rmin=rmin, rmax=rmax)
    lg.info(f'Requested query: {text[:max(len(text), 88)]}, returned {len(res["hits"])} matches')
    return jsonify(res)

@app.post("/run")
def run_chunker():
    body = request.get_json(force=True, silent=True) or {}
    dry = bool(body.get("dry_run", False))
    try:
        result = chunker.run(dry_run=dry)   # runs: >>> chunker.run(dry_run=False)
        return {"ok": True, "dry_run": dry, "result": result}, 200
    except Exception as e:
        lg.exception("chunker.run failed")
        return {"ok": False, "error": str(e)}, 500
