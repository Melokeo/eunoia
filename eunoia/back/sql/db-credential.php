<?php
const DB_CRED_FILE = '/var/lib/euno/secrets/db.json';

$data = json_decode(@file_get_contents(DB_CRED_FILE), true);
if (!is_array($data) || empty($data['db-usr']) || empty($data['db-pwd'])) {
    http_response_code(500);
    exit(json_encode(['error' => 'DB credential missing / invalid'], JSON_UNESCAPED_UNICODE));
}

$db_usr = $data['db-usr'];
$db_pwd = $data['db-pwd'];
define('DB_USR', $data['db-usr']);
define('DB_PWD', $data['db-pwd']);
