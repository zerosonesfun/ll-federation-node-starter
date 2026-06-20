Litter Layer Federation — Node Starter Pack
===========================================

Upload everything in this folder to your web root (usually public_html).
After upload, your host should have:

  public_html/
    .well-known/litterlayer.json
    sites.json
    search.php
    .htaccess          (merge with existing rules if you already have one)

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

3. .htaccess
   - If you already have a .htaccess file, copy only the RewriteEngine/RewriteRule
     lines into it. Do not delete your existing rules.
   - Nginx or locked-down hosts: ask support to route /search to search.php.

Quick tests
-----------

Descriptor (browser):
  https://your-domain.example/.well-known/litterlayer.json

Search (terminal):
  curl -sS -X POST https://your-domain.example/search \
    -H "Content-Type: application/json" \
    -d '{"query":"internet","limit":5}'

You should get JSON like {"results":[...]}.

Register
--------

When both URLs work, register your descriptor at:
  https://litterlayer.com/federation/

Use your full descriptor URL, for example:
  https://your-domain.example/.well-known/litterlayer.json

New nodes are reviewed before they appear in federated search.

Optional heartbeat (after approval)
-----------------------------------

  curl -sS -X POST https://litterlayer.com/api/federation/heartbeat.php \
    -H "Content-Type: application/json" \
    -d '{"node_id":"your-domain.example"}'

Replace your-domain.example with the same node_id from litterlayer.json.
