<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$query = is_array($data) ? trim((string)($data['query'] ?? '')) : '';
$page = is_array($data) ? max(1, (int)($data['page'] ?? 1)) : 1;
$limit = is_array($data) ? max(1, min(50, (int)($data['limit'] ?? 10))) : 10;

$path = __DIR__ . '/sites.json';
if (!is_file($path)) {
    echo json_encode([
        'results' => [],
        'page' => $page,
        'per_page' => $limit,
        'total' => 0,
        'has_more' => false,
    ]);
    exit;
}

$sites = json_decode(file_get_contents($path), true);
if (!is_array($sites)) {
    echo json_encode([
        'results' => [],
        'page' => $page,
        'per_page' => $limit,
        'total' => 0,
        'has_more' => false,
    ]);
    exit;
}

$terms = preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
$results = [];

foreach ($sites as $row) {
    if (!is_array($row)) {
        continue;
    }
    $haystack = mb_strtolower(
        ($row['title'] ?? '') . ' ' . ($row['description'] ?? '') . ' ' . ($row['url'] ?? '')
    );
    if ($terms) {
        $match = true;
        foreach ($terms as $term) {
            if ($term !== '' && mb_strpos($haystack, $term) === false) {
                $match = false;
                break;
            }
        }
        if (!$match) {
            continue;
        }
    }
    $results[] = [
        'url' => (string)($row['url'] ?? ''),
        'title' => (string)($row['title'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'score' => (float)($row['score'] ?? 0.5),
    ];
}

usort($results, function ($a, $b) {
    return ($b['score'] <=> $a['score']);
});

$total = count($results);
$offset = ($page - 1) * $limit;
$slice = array_slice($results, $offset, $limit);

echo json_encode(
    [
        'results' => $slice,
        'page' => $page,
        'per_page' => $limit,
        'total' => $total,
        'has_more' => ($offset + count($slice)) < $total,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
