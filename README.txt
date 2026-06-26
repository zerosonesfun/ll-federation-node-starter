Litter Layer Federation — Node Starter Pack
===========================================

Upload everything in this folder to your web root (usually public_html).
After upload, your host should have:

  public_html/
    .well-known/litterlayer.json
    sites.json
    search.php
    crawl_tick.php
    ll-widget.js
    lib/
      ll_node_common.php
      ll_node_crawler.php
    data/                 (must be writable by PHP)
    .htaccess             (merge with existing rules if you already have one)

Before you register
-------------------

1. Edit .well-known/litterlayer.json
   - Replace every "your-domain.example" with your real domain.
   - Keep https:// in base_url.
   - categories is required — keep demo and internet or change them to fit your site.

2. Edit sites.json
   - The file includes wilcosky.com and internetlastpage.com as examples.
   - Replace them with your own pages, or add more entries in the same format.
   - Each entry needs url, title, description, and score (0.0–1.0).
   - The auto-crawler will merge new pages into this file; manual entries are kept.

3. .htaccess
   - If you already have a .htaccess file, copy only the RewriteEngine/RewriteRule
     lines into it. Do not delete your existing rules.
   - Nginx or locked-down hosts: ask support to route /search to search.php and
     /crawl_tick to crawl_tick.php.

4. data/ folder
   - Make sure public_html/data is writable by PHP (chmod 755 or 775 is usually fine).
   - The crawler stores its queue and progress here. data/.htaccess blocks web access.

Site search widget + auto-crawler
---------------------------------

Add this HTML anywhere on your site (same domain as your node files):

  <div id="ll-site-search"></div>
  <script src="/ll-widget.js" defer></script>

Point src at wherever ll-widget.js lives. The widget finds /search and /crawl_tick
relative to that script path, so subfolder nodes work (e.g. src="/test/ll-widget.js").

The widget inherits your page colors and font-family, searches your local sites.json
via /search, and is separate from Litter Layer's main index.

When someone visits a page with the widget, a background crawl tick may run (at most
once every 5 minutes). Each tick processes up to 2 pages on your domain:

  - First tries /sitemap.xml at your site root (https://yoursite.com/sitemap.xml)
  - If no sitemap, crawls your homepage at / for internal links
  - Discovered pages anywhere on your domain are merged into sites.json

If node files live in a subfolder (e.g. /test/) but your site is at the domain root,
this is the default: base_url stays /test/ for search; the crawler seeds from /.
To index a subfolder only, set crawl_root_url in litterlayer.json.

No cron job is required. Traffic to pages with the widget gradually builds your index.

Quick tests
-----------

Descriptor (browser):
  https://your-domain.example/.well-known/litterlayer.json

Search (terminal):
  curl -sS -X POST https://your-domain.example/search \
    -H "Content-Type: application/json" \
    -d '{"query":"internet","limit":5}'

Crawl tick (terminal):
  curl -sS https://your-domain.example/crawl_tick

You should get JSON like {"results":[...]} from search and {"ok":true,...} from crawl_tick.

Register
--------

When both URLs work, register your descriptor at:
  https://litterlayer.com/federation/

Use your full descriptor URL, for example:
  https://your-domain.example/.well-known/litterlayer.json

New nodes are reviewed before they appear in federated search.

Upgrading an existing node
--------------------------

If you already run an older starter pack, upload the new files without replacing
your edited litterlayer.json or sites.json:

  ll-widget.js, crawl_tick.php, lib/, data/, and the new .htaccess rewrite line.

Optional heartbeat (after approval)
-----------------------------------

  curl -sS -X POST https://litterlayer.com/api/federation/heartbeat.php \
    -H "Content-Type: application/json" \
    -d '{"node_id":"your-domain.example"}'

Replace your-domain.example with the same node_id from litterlayer.json.
