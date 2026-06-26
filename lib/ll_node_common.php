<?php

function ll_node_root_dir() {
    return dirname(__DIR__);
}

function ll_node_data_dir() {
    $dir = ll_node_root_dir() . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function ll_node_sites_path() {
    return ll_node_root_dir() . '/sites.json';
}

function ll_node_descriptor_path() {
    return ll_node_root_dir() . '/.well-known/litterlayer.json';
}

function ll_node_read_descriptor() {
    $path = ll_node_descriptor_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $base_url = trim((string)($data['base_url'] ?? ''));
    $node_id = trim((string)($data['node_id'] ?? ''));
    if ($base_url === '' || $node_id === '') {
        return null;
    }
    $base_url = rtrim($base_url, '/');
    $crawl_root = trim((string)($data['crawl_root_url'] ?? ''));
    if ($crawl_root === '') {
        $crawl_root = ll_node_site_origin($base_url);
    } else {
        $crawl_root = rtrim($crawl_root, '/');
    }
    return [
        'node_id' => $node_id,
        'base_url' => $base_url,
        'crawl_root_url' => $crawl_root,
    ];
}

function ll_node_site_origin($url) {
    $parts = parse_url((string)$url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    return $scheme . '://' . strtolower($parts['host']);
}

function ll_node_normalize_host($host) {
    if (!is_string($host) || $host === '') {
        return '';
    }
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

function ll_node_site_host($base_url) {
    $host = parse_url($base_url, PHP_URL_HOST);
    return ll_node_normalize_host(is_string($host) ? $host : '');
}

function ll_node_normalize_url($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $host = ll_node_normalize_host($parts['host']);
    $path = $parts['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    } elseif ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . $path . $query;
}

function ll_node_url_on_domain($url, $host) {
    if ($host === '') {
        return '';
    }
    $normalized = ll_node_normalize_url($url);
    if ($normalized === '') {
        return '';
    }
    $url_host = ll_node_normalize_host(parse_url($normalized, PHP_URL_HOST) ?: '');
    if ($url_host !== ll_node_normalize_host($host)) {
        return '';
    }
    $path = parse_url($normalized, PHP_URL_PATH) ?? '/';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $skip_ext = ['css', 'js', 'json', 'xml', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'pdf', 'zip', 'gz', 'mp3', 'mp4', 'woff', 'woff2', 'ttf', 'eot', 'map', 'txt'];
    if ($ext !== '' && in_array($ext, $skip_ext, true)) {
        return '';
    }
    $basename = strtolower(basename($path));
    $skip_files = ['ll-widget.js', 'search.php', 'crawl_tick.php', 'sites.json', 'robots.txt'];
    if (in_array($basename, $skip_files, true)) {
        return '';
    }
    return $normalized;
}

function ll_node_read_json_file($path, $default) {
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function ll_node_write_json_file($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function ll_node_with_file_lock($path, callable $callback) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }
    try {
        $result = $callback($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $result;
}

function ll_node_load_sites() {
    $path = ll_node_sites_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $sites = json_decode($raw, true);
    return is_array($sites) ? $sites : [];
}

function ll_node_save_sites(array $sites) {
    return ll_node_write_json_file(ll_node_sites_path(), array_values($sites));
}

function ll_node_upsert_site($url, $title, $description, $score = 0.8) {
    $url = ll_node_normalize_url($url);
    if ($url === '') {
        return false;
    }
    return ll_node_with_file_lock(ll_node_sites_path(), function () use ($url, $title, $description, $score) {
        $sites = ll_node_load_sites();
        $found = false;
        foreach ($sites as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $existing_url = ll_node_normalize_url($row['url'] ?? '');
            if ($existing_url === $url) {
                $sites[$i]['url'] = $url;
                $sites[$i]['title'] = (string)$title;
                $sites[$i]['description'] = (string)$description;
                if (!isset($row['score'])) {
                    $sites[$i]['score'] = (float)$score;
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            $sites[] = [
                'url' => $url,
                'title' => (string)$title,
                'description' => (string)$description,
                'score' => (float)$score,
            ];
        }
        return ll_node_save_sites($sites);
    });
}

function ll_node_site_indexed_at($url) {
    $path = ll_node_data_dir() . '/indexed-at.json';
    $map = ll_node_read_json_file($path, []);
    $url = ll_node_normalize_url($url);
    return isset($map[$url]) ? (int)$map[$url] : 0;
}

function ll_node_mark_site_indexed($url) {
    $path = ll_node_data_dir() . '/indexed-at.json';
    $url = ll_node_normalize_url($url);
    if ($url === '') {
        return;
    }
    ll_node_with_file_lock($path, function () use ($path, $url) {
        $map = ll_node_read_json_file($path, []);
        $map[$url] = time();
        ll_node_write_json_file($path, $map);
        return true;
    });
}

function ll_node_crawl_request_allowed() {
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $descriptor = ll_node_read_descriptor();
    if ($descriptor === null) {
        return false;
    }

    $expected_host = ll_node_site_host($descriptor['base_url']);
    if ($expected_host === '') {
        return false;
    }

    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') {
        return true;
    }

    $referer_host = ll_node_site_host($referer);
    return $referer_host !== '' && hash_equals($expected_host, $referer_host);
}
