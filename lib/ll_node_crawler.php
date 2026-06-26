<?php

require_once __DIR__ . '/ll_node_common.php';

const LL_NODE_CRAWL_TICK_INTERVAL = 300;
const LL_NODE_CRAWL_URLS_PER_TICK = 2;
const LL_NODE_CRAWL_MAX_QUEUE = 500;
const LL_NODE_CRAWL_MAX_RETRIES = 3;
const LL_NODE_CRAWL_SITEMAP_MAX_URLS = 200;
const LL_NODE_CRAWL_SITEMAP_MAX_DEPTH = 2;
const LL_NODE_CRAWL_SITEMAP_MAX_CHILD = 2;
const LL_NODE_CRAWL_RECRAWL_DAYS = 7;
const LL_NODE_CRAWL_FETCH_TIMEOUT = 8;
const LL_NODE_CRAWL_USER_AGENT = 'LLNodeCrawler/1.0 (+https://litterlayer.com/federation/)';

function ll_node_crawl_meta_path() {
    return ll_node_data_dir() . '/crawl-meta.json';
}

function ll_node_crawl_queue_path() {
    return ll_node_data_dir() . '/crawl-queue.json';
}

function ll_node_crawl_default_meta() {
    return [
        'last_tick_at' => 0,
        'last_seed_at' => 0,
        'pages_indexed' => 0,
        'queue_remaining' => 0,
    ];
}

function ll_node_crawl_load_meta() {
    return array_merge(
        ll_node_crawl_default_meta(),
        ll_node_read_json_file(ll_node_crawl_meta_path(), [])
    );
}

function ll_node_crawl_save_meta(array $meta) {
    return ll_node_write_json_file(ll_node_crawl_meta_path(), array_merge(ll_node_crawl_default_meta(), $meta));
}

function ll_node_crawl_load_queue() {
    $queue = ll_node_read_json_file(ll_node_crawl_queue_path(), []);
    return is_array($queue) ? $queue : [];
}

function ll_node_crawl_save_queue(array $queue) {
    return ll_node_write_json_file(ll_node_crawl_queue_path(), array_values($queue));
}

function ll_node_crawl_fetch_page($url) {
    $content_type = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => LL_NODE_CRAWL_FETCH_TIMEOUT,
            CURLOPT_TIMEOUT => LL_NODE_CRAWL_FETCH_TIMEOUT,
            CURLOPT_USERAGENT => LL_NODE_CRAWL_USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 400) {
            return false;
        }
        return [
            'body' => $body,
            'content_type' => $content_type,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => LL_NODE_CRAWL_FETCH_TIMEOUT,
            'header' => "User-Agent: " . LL_NODE_CRAWL_USER_AGENT . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return false;
    }
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $content_type = trim(substr($header, 13));
                break;
            }
        }
    }
    return [
        'body' => $body,
        'content_type' => $content_type,
    ];
}

function ll_node_crawl_fetch_text($url) {
    $page = ll_node_crawl_fetch_page($url);
    return is_array($page) ? $page['body'] : false;
}

function ll_node_crawl_strip_non_content($html) {
    $html = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $html);
    $html = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $html);
    $html = preg_replace('~<noscript\b[^>]*>.*?</noscript>~is', ' ', $html);
    return $html;
}

function ll_node_crawl_is_html_page($url, $body, $content_type = '') {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $basename = strtolower(basename($path));
    $skip_files = ['ll-widget.js', 'search.php', 'crawl_tick.php', 'sites.json', 'robots.txt'];
    if (in_array($basename, $skip_files, true)) {
        return false;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $non_html_ext = ['css', 'js', 'json', 'xml', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'pdf', 'zip', 'gz', 'mp3', 'mp4', 'woff', 'woff2', 'ttf', 'eot', 'map', 'txt'];
    if ($ext !== '' && in_array($ext, $non_html_ext, true)) {
        return false;
    }

    $ct = strtolower(trim(strtok((string)$content_type, ';')));
    if ($ct !== '' && $ct !== 'text/html' && $ct !== 'application/xhtml+xml') {
        return false;
    }

    $sample = ltrim(substr((string)$body, 0, 800));
    if ($sample === '') {
        return false;
    }

    if (preg_match('~^(\(function|/\*|var |const |let |import |export |#!/)~i', $sample)) {
        return false;
    }
    if (stripos($sample, '<') === false && preg_match('~[{;]\s*[a-z0-9#.\-*]+\s*\{~i', $sample)) {
        return false;
    }

    return (bool)preg_match('~<(html|head|body|title|meta|main|article|section|div|p|h1|h2|h3|ul|ol|nav|header|footer)\b~i', $body);
}

function ll_node_crawl_extract_links($html, $crawl_root, $host) {
    if ($html === '' || $html === false) {
        return [];
    }
    $links = [];
    if (preg_match_all('~<a\b[^>]*\bhref=["\']([^"\']+)["\']~i', $html, $matches)) {
        foreach ($matches[1] as $href) {
            $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '' || $href[0] === '#') {
                continue;
            }
            if (preg_match('~^(mailto:|tel:|javascript:)~i', $href)) {
                continue;
            }
            if (strpos($href, '//') === 0) {
                $href = 'https:' . $href;
            } elseif (!preg_match('~^https?://~i', $href)) {
                $href = rtrim($crawl_root, '/') . '/' . ltrim($href, '/');
            }
            $clean = ll_node_url_on_domain($href, $host);
            if ($clean !== '') {
                $links[] = $clean;
            }
        }
    }
    return array_values(array_unique($links));
}

function ll_node_crawl_parse_sitemap_urls($sitemap_url, array &$state) {
    if ($state['depth'] > LL_NODE_CRAWL_SITEMAP_MAX_DEPTH || count($state['urls']) >= LL_NODE_CRAWL_SITEMAP_MAX_URLS) {
        return;
    }
    $state['depth']++;
    $body = ll_node_crawl_fetch_text($sitemap_url);
    if ($body === false || trim($body) === '') {
        $state['depth']--;
        return;
    }
    $state['found'] = true;

    $is_index = stripos($body, '<sitemapindex') !== false || preg_match('~<sitemap\b~i', $body);
    if ($is_index && preg_match_all('~<sitemap>(.*?)</sitemap>~is', $body, $blocks)) {
        foreach ($blocks[1] as $block) {
            if ($state['child_fetches'] >= LL_NODE_CRAWL_SITEMAP_MAX_CHILD) {
                break;
            }
            if (preg_match('~<loc>(.*?)</loc>~is', $block, $m)) {
                $child = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($child !== '') {
                    $state['child_fetches']++;
                    ll_node_crawl_parse_sitemap_urls($child, $state);
                }
            }
        }
        $state['depth']--;
        return;
    }

    if (preg_match_all('~<loc>(.*?)</loc>~is', $body, $locs)) {
        foreach ($locs[1] as $loc) {
            if (count($state['urls']) >= LL_NODE_CRAWL_SITEMAP_MAX_URLS) {
                break;
            }
            $url = trim(html_entity_decode(strip_tags($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== '') {
                $state['urls'][] = $url;
            }
        }
    }
    $state['depth']--;
}

function ll_node_crawl_discover_from_sitemap($crawl_root, $host) {
    $sitemap_url = rtrim($crawl_root, '/') . '/sitemap.xml';
    $state = [
        'urls' => [],
        'found' => false,
        'child_fetches' => 0,
        'depth' => 0,
    ];
    ll_node_crawl_parse_sitemap_urls($sitemap_url, $state);
    $filtered = [];
    foreach ($state['urls'] as $url) {
        $clean = ll_node_url_on_domain($url, $host);
        if ($clean !== '') {
            $filtered[] = $clean;
        }
    }
    return array_values(array_unique($filtered));
}

function ll_node_crawl_discover_from_homepage($crawl_root, $host) {
    $html = ll_node_crawl_fetch_text(rtrim($crawl_root, '/') . '/');
    if ($html === false) {
        return [];
    }
    return ll_node_crawl_extract_links($html, $crawl_root, $host);
}

function ll_node_crawl_queue_item($url) {
    return [
        'url' => ll_node_normalize_url($url),
        'added_at' => time(),
        'last_attempt' => 0,
        'retry_count' => 0,
    ];
}

function ll_node_crawl_enqueue_urls(array $urls, $host) {
    return ll_node_with_file_lock(ll_node_crawl_queue_path(), function () use ($urls, $host) {
        $queue = ll_node_crawl_load_queue();
        $known = [];
        foreach ($queue as $row) {
            if (is_array($row) && !empty($row['url'])) {
                $known[ll_node_normalize_url($row['url'])] = true;
            }
        }
        $added = 0;
        foreach ($urls as $url) {
            if (count($queue) >= LL_NODE_CRAWL_MAX_QUEUE) {
                break;
            }
            $clean = ll_node_url_on_domain($url, $host);
            if ($clean === '' || isset($known[$clean])) {
                continue;
            }
            $known[$clean] = true;
            $queue[] = ll_node_crawl_queue_item($clean);
            $added++;
        }
        ll_node_crawl_save_queue($queue);
        return $added;
    });
}

function ll_node_crawl_append_queue_items(array $items) {
    if (empty($items)) {
        return 0;
    }
    return ll_node_with_file_lock(ll_node_crawl_queue_path(), function () use ($items) {
        $queue = ll_node_crawl_load_queue();
        $known = [];
        foreach ($queue as $row) {
            if (is_array($row) && !empty($row['url'])) {
                $known[ll_node_normalize_url($row['url'])] = true;
            }
        }
        $added = 0;
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['url'])) {
                continue;
            }
            if (count($queue) >= LL_NODE_CRAWL_MAX_QUEUE) {
                break;
            }
            $clean = ll_node_normalize_url($item['url']);
            if ($clean === '' || isset($known[$clean])) {
                continue;
            }
            $known[$clean] = true;
            $queue[] = [
                'url' => $clean,
                'added_at' => (int)($item['added_at'] ?? time()),
                'last_attempt' => (int)($item['last_attempt'] ?? time()),
                'retry_count' => (int)($item['retry_count'] ?? 0),
            ];
            $added++;
        }
        ll_node_crawl_save_queue($queue);
        return $added;
    });
}

function ll_node_crawl_pop_batch($limit) {
    $result = ll_node_with_file_lock(ll_node_crawl_queue_path(), function () use ($limit) {
        $queue = ll_node_crawl_load_queue();
        $batch = [];
        while (count($batch) < $limit && !empty($queue)) {
            $batch[] = array_shift($queue);
        }
        ll_node_crawl_save_queue($queue);
        return [
            'batch' => $batch,
            'remaining' => count($queue),
        ];
    });
    return is_array($result) ? $result : ['batch' => [], 'remaining' => 0];
}

function ll_node_crawl_seed_queue($crawl_root, $host) {
    $urls = ll_node_crawl_discover_from_sitemap($crawl_root, $host);
    if (empty($urls)) {
        $urls = ll_node_crawl_discover_from_homepage($crawl_root, $host);
    }
    if (empty($urls)) {
        $home = ll_node_url_on_domain(rtrim($crawl_root, '/') . '/', $host);
        if ($home !== '') {
            $urls = [$home];
        }
    }
    $added = ll_node_crawl_enqueue_urls($urls, $host);
    ll_node_crawl_save_meta(array_merge(ll_node_crawl_load_meta(), [
        'last_seed_at' => time(),
    ]));
    return $added;
}

function ll_node_crawl_extract_page_meta($html) {
    $html = ll_node_crawl_strip_non_content($html);
    $title = '';
    $description = '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('~<meta\b[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\']~i', $html, $m)
        || preg_match('~<meta\b[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\']~i', $html, $m)) {
        $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($description === '') {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
        if ($text !== '') {
            if (function_exists('mb_substr')) {
                $description = mb_substr($text, 0, 160);
            } else {
                $description = substr($text, 0, 160);
            }
        }
    }
    if ($title === '' && $description !== '') {
        $title = $description;
    }
    return [
        'title' => $title !== '' ? $title : 'Untitled page',
        'description' => $description,
    ];
}

function ll_node_crawl_should_skip_recent($url) {
    $indexed_at = ll_node_site_indexed_at($url);
    if ($indexed_at <= 0) {
        return false;
    }
    return (time() - $indexed_at) < (LL_NODE_CRAWL_RECRAWL_DAYS * 86400);
}

function ll_node_crawl_process_url($url, $crawl_root, $host) {
    if (ll_node_crawl_should_skip_recent($url)) {
        return ['ok' => true, 'skipped' => 'recent', 'discovered' => []];
    }
    $page = ll_node_crawl_fetch_page($url);
    if ($page === false) {
        return ['ok' => false, 'skipped' => 'fetch_failed', 'discovered' => []];
    }
    $html = $page['body'];
    if (!ll_node_crawl_is_html_page($url, $html, $page['content_type'] ?? '')) {
        return ['ok' => false, 'skipped' => 'not_html', 'discovered' => []];
    }
    $meta = ll_node_crawl_extract_page_meta($html);
    ll_node_upsert_site($url, $meta['title'], $meta['description']);
    ll_node_mark_site_indexed($url);
    $discovered = ll_node_crawl_extract_links($html, $crawl_root, $host);
    return ['ok' => true, 'skipped' => '', 'discovered' => $discovered];
}

function ll_node_crawl_begin_tick() {
    $result = ll_node_with_file_lock(ll_node_crawl_meta_path(), function () {
        $meta = ll_node_crawl_load_meta();
        $now = time();
        if (($now - (int)$meta['last_tick_at']) < LL_NODE_CRAWL_TICK_INTERVAL) {
            return [
                'ok' => true,
                'skipped' => 'throttled',
                'processed' => 0,
                'indexed' => 0,
                'queue_remaining' => (int)$meta['queue_remaining'],
                'pages_indexed' => (int)$meta['pages_indexed'],
                'continue' => false,
            ];
        }
        $meta['last_tick_at'] = $now;
        ll_node_crawl_save_meta($meta);
        return [
            'ok' => true,
            'skipped' => '',
            'processed' => 0,
            'indexed' => 0,
            'queue_remaining' => (int)$meta['queue_remaining'],
            'pages_indexed' => (int)$meta['pages_indexed'],
            'continue' => true,
        ];
    });

    if ($result === null) {
        return ['ok' => false, 'error' => 'lock_failed', 'processed' => 0, 'indexed' => 0, 'queue_remaining' => 0];
    }

    return $result;
}

function ll_node_crawl_finish_tick($pages_indexed, $processed, $indexed) {
    $result = ll_node_with_file_lock(ll_node_crawl_meta_path(), function () use ($pages_indexed) {
        $queue = ll_node_crawl_load_queue();
        ll_node_crawl_save_meta(array_merge(ll_node_crawl_load_meta(), [
            'pages_indexed' => $pages_indexed,
            'queue_remaining' => count($queue),
        ]));
        return count($queue);
    });
    if ($result === null) {
        $result = count(ll_node_crawl_load_queue());
    }
    return [
        'ok' => true,
        'skipped' => '',
        'processed' => $processed,
        'indexed' => $indexed,
        'queue_remaining' => (int)$result,
    ];
}

function ll_node_crawl_tick() {
    $descriptor = ll_node_read_descriptor();
    if ($descriptor === null) {
        return ['ok' => false, 'error' => 'descriptor_missing', 'processed' => 0, 'indexed' => 0, 'queue_remaining' => 0];
    }

    $base_url = $descriptor['base_url'];
    $crawl_root = $descriptor['crawl_root_url'] ?? ll_node_site_origin($base_url);
    $host = ll_node_site_host($base_url);
    if ($host === '' || $crawl_root === '') {
        return ['ok' => false, 'error' => 'invalid_base_url', 'processed' => 0, 'indexed' => 0, 'queue_remaining' => 0];
    }

    $tick = ll_node_crawl_begin_tick();
    if (empty($tick['continue'])) {
        unset($tick['continue'], $tick['pages_indexed']);
        if (!isset($tick['indexed'])) {
            $tick['indexed'] = 0;
        }
        return $tick;
    }

    $pages_indexed = (int)$tick['pages_indexed'];

    if (empty(ll_node_crawl_load_queue())) {
        ll_node_crawl_seed_queue($crawl_root, $host);
    }

    $pop = ll_node_crawl_pop_batch(LL_NODE_CRAWL_URLS_PER_TICK);
    $batch = $pop['batch'] ?? [];
    $processed = 0;
    $indexed = 0;
    $requeue = [];
    $discovered_all = [];

    foreach ($batch as $item) {
        if (!is_array($item) || empty($item['url'])) {
            continue;
        }
        $processed++;
        $item['last_attempt'] = time();
        $crawl = ll_node_crawl_process_url($item['url'], $crawl_root, $host);
        $skipped = (string)($crawl['skipped'] ?? '');

        if ($skipped === 'recent') {
            $requeue[] = $item;
            continue;
        }

        if ($skipped === 'fetch_failed') {
            $retries = (int)($item['retry_count'] ?? 0) + 1;
            if ($retries < LL_NODE_CRAWL_MAX_RETRIES) {
                $item['retry_count'] = $retries;
                $requeue[] = $item;
            }
            continue;
        }

        if ($skipped === 'not_html') {
            continue;
        }

        $indexed++;
        $pages_indexed++;
        if (!empty($crawl['discovered'])) {
            $discovered_all = array_merge($discovered_all, $crawl['discovered']);
        }
    }

    ll_node_crawl_append_queue_items($requeue);
    if (!empty($discovered_all)) {
        ll_node_crawl_enqueue_urls($discovered_all, $host);
    }

    $result = ll_node_crawl_finish_tick($pages_indexed, $processed, $indexed);
    return $result;
}
