<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/ll_node_crawler.php';

@set_time_limit(30);

if (!ll_node_crawl_request_allowed()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'processed' => 0,
        'indexed' => 0,
        'queue_remaining' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$result = ll_node_crawl_tick();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
