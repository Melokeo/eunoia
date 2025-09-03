-- Defaults for DB-stored flags; safe to re-run
-- USE euno;

INSERT INTO graph_config_kv (ns, `key`, value_json) VALUES
('graph','schema.version',       JSON_EXTRACT(JSON_OBJECT('v','v1'),'$.v')),
('graph','pack.version',         JSON_EXTRACT(JSON_OBJECT('v','v1'),'$.v')),
('graph','detector.version',     JSON_EXTRACT(JSON_OBJECT('v','v1'),'$.v')),
('graph','merge.version',        JSON_EXTRACT(JSON_OBJECT('v','v1'),'$.v')),

('graph','rank.weights',         JSON_OBJECT('text',1.0,'recency',0.6,'edge',0.5,'centrality',0.2)),
('graph','limits.hop',           JSON_EXTRACT(JSON_OBJECT('v',1),'$.v')),
('graph','limits.node_cap',      JSON_EXTRACT(JSON_OBJECT('v',100),'$.v')),
('graph','limits.token_cap',     JSON_EXTRACT(JSON_OBJECT('v',8000),'$.v')),
('graph','thresholds.link',      JSON_EXTRACT(JSON_OBJECT('v',0.55),'$.v')),
('graph','thresholds.promote',   JSON_EXTRACT(JSON_OBJECT('v',0.80),'$.v')),
('graph','thresholds.decay',     JSON_EXTRACT(JSON_OBJECT('v',0.10),'$.v')),
('graph','ttl.provisional_days', JSON_EXTRACT(JSON_OBJECT('v',90),'$.v')),
('graph','ttl.edge_stale_days',  JSON_EXTRACT(JSON_OBJECT('v',180),'$.v')),
('graph','retrieval.recency_days',JSON_EXTRACT(JSON_OBJECT('v',30),'$.v')),
('graph','detector.timeout_ms',  JSON_EXTRACT(JSON_OBJECT('v',120),'$.v'))
ON DUPLICATE KEY UPDATE value_json=VALUES(value_json), updated_ts=CURRENT_TIMESTAMP(6);
