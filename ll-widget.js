(function () {
  'use strict';

  var DEBOUNCE_MS = 250;
  var PAGE_SIZE = 25;
  var widgetCounter = 0;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function getApiBase() {
    var script = document.currentScript;
    if (script && script.src) {
      var src = script.src.replace(/[#?].*$/, '');
      return src.slice(0, src.lastIndexOf('/') + 1);
    }
    var path = window.location.pathname || '/';
    if (path.slice(-1) === '/') {
      return path;
    }
    var slash = path.lastIndexOf('/');
    return slash >= 0 ? path.slice(0, slash + 1) : '/';
  }

  function joinBase(base, segment) {
    return base.replace(/\/+$/, '') + '/' + String(segment).replace(/^\/+/, '');
  }

  function injectStyles() {
    if (document.getElementById('ll-sw-styles')) {
      return;
    }
    var style = document.createElement('style');
    style.id = 'll-sw-styles';
    style.textContent = [
      '.ll-sw { color: inherit; font-family: inherit; max-width: 36rem; }',
      '.ll-sw-input-wrap { display: flex; gap: 0.5rem; align-items: stretch; }',
      '.ll-sw-input { flex: 1; min-width: 0; padding: 0.5rem 0.65rem; border: 1px solid var(--ll-sw-border, currentColor); border-radius: 4px; background: var(--ll-sw-bg, transparent); color: inherit; font: inherit; }',
      '.ll-sw-input:focus { outline: 2px solid currentColor; outline-offset: 1px; }',
      '.ll-sw-status { margin: 0.35rem 0 0; font-size: 0.9em; opacity: 0.75; min-height: 1.2em; }',
      '.ll-sw-results { list-style: none; margin: 0.75rem 0 0; padding: 0; }',
      '.ll-sw-result { margin: 0 0 0.85rem; padding: 0; }',
      '.ll-sw-result a { color: inherit; font-weight: 600; text-decoration: underline; }',
      '.ll-sw-result a:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }',
      '.ll-sw-desc { margin: 0.2rem 0 0; font-size: 0.92em; opacity: 0.85; }',
      '.ll-sw-empty { margin: 0.75rem 0 0; opacity: 0.75; font-size: 0.95em; }',
      '.ll-sw-load-more { margin: 0.75rem 0 0; padding: 0.45rem 0.85rem; border: 1px solid var(--ll-sw-border, currentColor); border-radius: 4px; background: var(--ll-sw-bg, transparent); color: inherit; font: inherit; cursor: pointer; }',
      '.ll-sw-load-more:hover { opacity: 0.85; }',
      '.ll-sw-load-more:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }',
      '.ll-sw-load-more[disabled] { opacity: 0.6; cursor: wait; }'
    ].join('\n');
    document.head.appendChild(style);
  }

  function hostColors() {
    var body = document.body;
    if (!body || !window.getComputedStyle) {
      return {};
    }
    var cs = window.getComputedStyle(body);
    return {
      bg: cs.backgroundColor || 'transparent',
      border: cs.borderColor || cs.color || 'currentColor'
    };
  }

  function mount(root, apiBase) {
    injectStyles();
    var colors = hostColors();
    root.classList.add('ll-sw');
    if (colors.bg) {
      root.style.setProperty('--ll-sw-bg', colors.bg);
    }
    if (colors.border) {
      root.style.setProperty('--ll-sw-border', colors.border);
    }

    widgetCounter += 1;
    var inputId = 'll-sw-input-' + widgetCounter;
    var loadMoreId = 'll-sw-load-more-' + widgetCounter;

    var label = document.createElement('label');
    label.className = 'll-sw-label';
    label.textContent = 'Search this site';
    label.style.position = 'absolute';
    label.style.width = '1px';
    label.style.height = '1px';
    label.style.padding = '0';
    label.style.margin = '-1px';
    label.style.overflow = 'hidden';
    label.style.clip = 'rect(0,0,0,0)';
    label.style.whiteSpace = 'nowrap';
    label.style.border = '0';
    label.setAttribute('for', inputId);

    var wrap = document.createElement('div');
    wrap.className = 'll-sw-input-wrap';

    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'll-sw-input';
    input.autocomplete = 'off';
    input.spellcheck = true;
    input.setAttribute('aria-label', 'Search this site');
    input.id = inputId;

    wrap.appendChild(label);
    wrap.appendChild(input);

    var status = document.createElement('p');
    status.className = 'll-sw-status';
    status.setAttribute('aria-live', 'polite');

    var results = document.createElement('ul');
    results.className = 'll-sw-results';
    results.hidden = true;

    var loadMoreBtn = document.createElement('button');
    loadMoreBtn.type = 'button';
    loadMoreBtn.className = 'll-sw-load-more';
    loadMoreBtn.id = loadMoreId;
    loadMoreBtn.textContent = 'Load more results';
    loadMoreBtn.hidden = true;

    root.appendChild(wrap);
    root.appendChild(status);
    root.appendChild(results);
    root.appendChild(loadMoreBtn);

    var timer = null;
    var controller = null;
    var searchUrl = joinBase(apiBase, 'search');
    var state = {
      query: '',
      page: 1,
      total: 0,
      loading: false
    };

    function clearEmptyMessage() {
      var prevEmpty = root.querySelector('.ll-sw-empty');
      if (prevEmpty) {
        prevEmpty.remove();
      }
    }

    function showEmptyMessage() {
      clearEmptyMessage();
      results.hidden = true;
      results.innerHTML = '';
      loadMoreBtn.hidden = true;
      var empty = document.createElement('p');
      empty.className = 'll-sw-empty';
      empty.textContent = 'No results found.';
      results.insertAdjacentElement('afterend', empty);
    }

    function setStatus(text) {
      status.textContent = text || '';
    }

    function appendResults(items) {
      clearEmptyMessage();
      items.forEach(function (row) {
        var li = document.createElement('li');
        li.className = 'll-sw-result';
        var link = document.createElement('a');
        link.href = row.url || '#';
        link.textContent = row.title || row.url || 'Untitled';
        link.rel = 'noopener';
        li.appendChild(link);
        if (row.description) {
          var desc = document.createElement('p');
          desc.className = 'll-sw-desc';
          desc.textContent = row.description;
          li.appendChild(desc);
        }
        results.appendChild(li);
      });
      results.hidden = false;
    }

    function updateStatusCount(shownCount) {
      if (!state.total) {
        setStatus('');
        return;
      }
      setStatus('Showing ' + shownCount + ' of ' + state.total + ' result' + (state.total === 1 ? '' : 's'));
    }

    function updateLoadMore(hasMore) {
      loadMoreBtn.hidden = !hasMore;
      loadMoreBtn.disabled = false;
      loadMoreBtn.textContent = 'Load more results';
    }

    function fetchPage(page, append) {
      if (state.loading) {
        return;
      }
      state.loading = true;
      if (controller) {
        controller.abort();
      }
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      if (!append) {
        setStatus('Searching…');
      } else {
        loadMoreBtn.disabled = true;
        loadMoreBtn.textContent = 'Loading…';
      }

      fetch(searchUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          query: state.query,
          page: page,
          limit: PAGE_SIZE
        }),
        signal: controller ? controller.signal : undefined
      })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('Search failed');
          }
          return res.json();
        })
        .then(function (data) {
          var items = (data && data.results) || [];
          state.page = data && data.page ? data.page : page;
          state.total = data && typeof data.total === 'number' ? data.total : items.length;

          if (!append) {
            results.innerHTML = '';
          }

          if (!append && !items.length) {
            showEmptyMessage();
            return;
          }

          appendResults(items);
          updateStatusCount(results.children.length);
          updateLoadMore(!!(data && data.has_more));
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return;
          }
          if (!append) {
            setStatus('Search unavailable.');
            results.hidden = true;
            loadMoreBtn.hidden = true;
          } else {
            loadMoreBtn.disabled = false;
            loadMoreBtn.textContent = 'Load more results';
          }
        })
        .finally(function () {
          state.loading = false;
        });
    }

    function runSearch(query) {
      state.query = query;
      state.page = 1;
      state.total = 0;
      loadMoreBtn.hidden = true;
      fetchPage(1, false);
    }

    loadMoreBtn.addEventListener('click', function () {
      if (!state.query || state.loading) {
        return;
      }
      fetchPage(state.page + 1, true);
    });

    input.addEventListener('input', function () {
      var q = input.value.trim();
      if (timer) {
        clearTimeout(timer);
      }
      if (q === '') {
        state.query = '';
        state.page = 1;
        state.total = 0;
        setStatus('');
        results.hidden = true;
        results.innerHTML = '';
        loadMoreBtn.hidden = true;
        clearEmptyMessage();
        return;
      }
      timer = setTimeout(function () {
        runSearch(q);
      }, DEBOUNCE_MS);
    });
  }

  function triggerCrawlTick(apiBase) {
    fetch(joinBase(apiBase, 'crawl_tick'), { method: 'GET', keepalive: true }).catch(function () {});
  }

  ready(function () {
    var targets = document.querySelectorAll('#ll-site-search, [data-ll-search]');
    if (!targets.length) {
      return;
    }
    var apiBase = getApiBase();
    targets.forEach(function (root) {
      mount(root, apiBase);
    });
    triggerCrawlTick(apiBase);
  });
})();
