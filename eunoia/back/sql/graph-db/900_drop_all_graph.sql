-- Assumes current DB is `euno`
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS graph_job_runs;
DROP TABLE IF EXISTS graph_queue_jobs;
DROP TABLE IF EXISTS graph_evidence;
DROP TABLE IF EXISTS graph_edges;
DROP TABLE IF EXISTS graph_node_aliases;
DROP TABLE IF EXISTS graph_nodes;
DROP TABLE IF EXISTS graph_daily_summaries;
DROP TABLE IF EXISTS graph_config_kv;
DROP TABLE IF EXISTS graph_turns;

SET FOREIGN_KEY_CHECKS=1;
