# Graph Memory DB — Developer Notes

## Scope

Single-box graph memory for Eunoia. Stores entities, aliases, relations, evidence, config, queue, and turn log. Target scale ≈ 1e5 nodes.

## Files and layout

* SQL: `/var/lib/euno/sql/graph-db/`

  * `000_drop_all_graph.sql`
  * `001_create_graph_tables.sql`
  * `002_create_graph_indexes.sql`
  * `004_config_defaults.sql`
  * `003_seed_smoke.sql` (optional)
  * `010_embeddings_addon.sql` (optional)
  * `100_create_turns.sql`
* PHP package: `/var/lib/euno/graph-memory/`
  Core: `Repo.php`, `Detector.php`, `Linker.php`, `Retrieval.php`, `Ranker.php`, `Render.php`, `Graph.php`, `Schema.php`
* Bridge: `/var/www/typecho/eunoia/GraphMemoryBridge.php`

## Tables

### `graph_nodes`

* `id CHAR(30) PK` (e.g., `n_...`)
* `type ENUM('Person','Project','Task','Preference','Artifact','Time','Quantity','Other')`
* `title VARCHAR(255)` canonical name
* `confidence DECIMAL(3,2)` 0–1
* `status ENUM('provisional','promoted')`
* `created_ts`, `updated_ts`
* (optional) `embed` if `010_embeddings_addon.sql` used
* Purpose: entity catalog.

### `graph_node_aliases`

* `id CHAR(30) PK` (e.g., `a_...`)
* `node_id CHAR(30)` → `graph_nodes.id`
* `alias VARCHAR(255)`
* `source ENUM('span','assistant','manual','seed')`
* `weight DECIMAL(4,2)`
* `created_ts`
* Indexes: FULLTEXT(alias) + `(node_id,alias)` unique if supported
* Purpose: surface forms and linkage.

### `graph_edges`

* `id CHAR(30) PK` (e.g., `e_...`)
* `src_id CHAR(30)` → `graph_nodes.id`
* `dst_id CHAR(30)` → `graph_nodes.id`
* `type ENUM('assigned_to','prefers','depends_on','member_of','related_to','owns','scheduled_for')`
* `weight DECIMAL(4,2)`; `attrs_json JSON NULL`
* `created_ts`, `updated_ts`
* Unique: `(src_id,dst_id,type)`
* Purpose: typed relations.

### `graph_evidence`

* `id CHAR(30) PK` (e.g., `v_...`)
* `subject_kind ENUM('node','edge')`
* `subject_id CHAR(30)` → node.id or edge.id
* `msg_id BIGINT` → `messages.id` (user or assistant)
* `span_json JSON NULL` (e.g., `{"span":[start,end]}`)
* `created_ts`
* Purpose: provenance to chat content.

### `graph_config_kv`

* `ns VARCHAR(64)` default `graph`
* `key VARCHAR(128)`; `value_json TEXT`
* `updated_ts`
* PK: `(ns,key)`
* Purpose: tunables without code edits.

### `graph_queue_jobs` / `graph_job_runs` (optional now)

* Basic queue scaffolding (`queued|running|done|failed`), payload JSON, attempts, priority.
* Purpose: future async maintenance.

### `graph_turns`

* `id CHAR(30) PK` (e.g., `t_...`)
* `sid VARCHAR(64)` = `messages.session_id`
* `user_msg_id BIGINT` → `messages.id`
* `assistant_msg_id BIGINT NULL` → `messages.id`
* `ts_user`, `ts_assistant` (copied timestamps)
* `intent VARCHAR(64) NULL`, `scores_json JSON NULL`, `detections_json JSON NULL`, `slots_json JSON NULL`
* `detector_version VARCHAR(16)`
* `processed TINYINT DEFAULT 0`
* Purpose: immutable event log.

## ID and naming conventions

* Prefixes: node=`n_`, alias=`a_`, edge=`e_`, evidence=`v_`, queue job=`q_`, run=`r_`, turn=`t_`.
* ULID-like sortable IDs; generated in `Repo::newId()`.

## Write paths

### Pre-API (immediate graph update)

1. Persist latest user text → `messages` (get `messages.id`).
2. `Detector::detect(text)` → intent, entities, slots.
3. `Linker::link(session_id, msg_id, entities, source='span')`:

   * Alias match → reuse node.
   * Miss → create provisional node + alias.
   * Always add `graph_evidence` pointing to `msg_id`.
   * Optional: hard-fact edges via `Writer` when rules apply.
4. Retrieval is read-only.

### Post-API

* Persist assistant reply → `messages` (id).
* `Graph::logTurn(sid, user_msg_id, assistant_msg_id, detector)` → `graph_turns`.
* Optional symmetric update: `Linker::link(..., source='assistant')` on assistant text (fences stripped) to add evidence/aliases from the reply.

## Read path

* Seeds from `Linker` results.
* `Retrieval::subgraph(seeds)`:

  * Collect seed nodes.
  * 1-hop expand via `graph_edges`.
  * Rank with `Ranker` (text/recency/edge/centrality).
* `Render::pack(subgraph, seeds, windowDays, hop)` → `[Memory v1]` block (≤100 lines).
  Never persisted to `messages`.

## Config keys (in `graph_config_kv`)

* `limits.node_cap` (default 100)
* `limits.hop` (default 1)
* `retrieval.recency_days` (default 30)
* `thresholds.promote` (e.g., 0.8)
* `rank.weights` (object: `text`, `recency`, `edge`, `centrality`)
* `detector.version` (e.g., `v1`)

## Migrations

Order:

1. `000_drop_all_graph.sql` (safe dev reset; also drops `graph_turns_latest` view if present)
2. `001_create_graph_tables.sql`
3. `002_create_graph_indexes.sql`
4. `004_config_defaults.sql`
5. `003_seed_smoke.sql` (optional)
6. `010_embeddings_addon.sql` (optional)
7. `100_create_turns.sql`

## Integration points

* Bridge: `GraphMemoryBridge::injectSystemMemory(sid, userText)` returns:

  * system message `[Memory v1]`, `user_msg_id`, detector output.
* After assistant persist:
  `GraphMemoryBridge::logTurn(...)` and optionally `processAssistantMemory(...)`.

## Invariants

* Evidence always references `messages.id` (not turn id).
* No internal IDs in rendered text.
* `[Memory v1]` never persisted to `messages`.
* Provisional nodes carry low confidence until promoted by rules or repeated evidence.
* Edges unique on `(src,dst,type)`.

## Common queries

* Latest evidence for a message:

  ```sql
  SELECT subject_kind, subject_id FROM graph_evidence WHERE msg_id=? ORDER BY created_ts DESC;
  ```
* Node by alias:

  ```sql
  SELECT n.* FROM graph_node_aliases a JOIN graph_nodes n ON n.id=a.node_id WHERE a.alias=?;
  ```
* Assigned tasks for a person:

  ```sql
  SELECT t.* FROM graph_edges e
  JOIN graph_nodes t ON t.id=e.dst_id
  WHERE e.type='assigned_to' AND e.src_id=?;
  ```

## Extending types

* Add node/edge types in `Schema.php` lists.
* Seed canonical aliases in `graph_node_aliases` for known entities to avoid provisional duplicates.

## Gotchas

* FULLTEXT availability differs across MariaDB versions; `Repo::findAliases` falls back to `LIKE`.
* `IN (?)` parameterization must expand placeholders per id (fixed in `Repo`).
* Assistant replies may include fenced blocks; strip before detection when writing evidence with `source='assistant'`.

This document captures schema, flows, and contracts so additional contributors can evolve detection, linking, and retrieval without breaking storage invariants.
