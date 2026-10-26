(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var labels = document.body.dataset;
  var header = document.querySelector('.site-header');
  var navToggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');

  if (navToggle && nav) {
    var toggleLabel = navToggle.querySelector('.sr-only');
    var setNav = function (open) {
      document.body.classList.toggle('nav-open', open);
      navToggle.setAttribute('aria-expanded', String(open));
      if (toggleLabel) toggleLabel.textContent = open ? (labels.menuClose || 'Close menu') : (labels.menuOpen || 'Open menu');
    };

    navToggle.addEventListener('click', function () {
      setNav(!document.body.classList.contains('nav-open'));
    });

    nav.addEventListener('click', function (event) {
      if (event.target.closest('a')) setNav(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
        setNav(false);
        navToggle.focus();
      }
    });
  }

  var searchDialog = document.getElementById('search-dialog');
  if (searchDialog && typeof searchDialog.showModal === 'function') {
    var searchOpeners = document.querySelectorAll('[data-search-open]');
    var searchInput = document.getElementById('search-input');
    var searchResults = document.getElementById('search-results');
    var searchStatus = document.getElementById('search-status');
    var searchHint = document.getElementById('search-hint');
    var searchClose = searchDialog.querySelector('.search-close');
    var searchRoot = document.body.getAttribute('data-root') || '';
    var searchPage = document.body.getAttribute('data-page') || '';
    var searchVersion = document.body.getAttribute('data-search-version') || '';
    var searchLang = (document.documentElement.lang || 'zh-CN').indexOf('en') === 0 ? 'en' : 'zh';
    var searchCache = null;
    var searchLoading = null;
    var searchItems = [];
    var searchActive = -1;
    var searchTrigger = null;
    var searchTimer = null;

    function normalizeSearch(value) {
      return String(value || '').toLocaleLowerCase();
    }

    function searchTerms(query) {
      return normalizeSearch(query).trim().split(/\s+/).filter(Boolean);
    }

    function countTerm(text, term) {
      var count = 0;
      var position = 0;
      while ((position = text.indexOf(term, position)) !== -1) {
        count += 1;
        position += term.length;
      }
      return count;
    }

    function loadSearchIndex() {
      if (searchCache) return Promise.resolve(searchCache);
      if (searchLoading) return searchLoading;
      var searchUrl = searchRoot + 'assets/search-index.json' + (searchVersion ? '?v=' + encodeURIComponent(searchVersion) : '');
      searchLoading = fetch(searchUrl, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (data) {
          searchCache = data && Array.isArray(data.pages) ? data.pages : [];
          return searchCache;
        })
        .catch(function (error) {
          searchLoading = null;
          throw error;
        });
      return searchLoading;
    }

    function clearSearchResults() {
      searchItems = [];
      searchActive = -1;
      while (searchResults.firstChild) searchResults.removeChild(searchResults.firstChild);
      searchInput.removeAttribute('aria-activedescendant');
    }

    function searchSnippet(text, terms) {
      var lower = normalizeSearch(text);
      var first = -1;
      terms.forEach(function (term) {
        var index = lower.indexOf(term);
        if (index !== -1 && (first === -1 || index < first)) first = index;
      });
      if (first === -1) return text.slice(0, 180);
      var start = Math.max(0, first - 56);
      var end = Math.min(text.length, start + 220);
      var value = text.slice(start, end).replace(/\s+/g, ' ').trim();
      return (start > 0 ? '…' : '') + value + (end < text.length ? '…' : '');
    }

    function highlightSearchText(element, text, terms) {
      var lower = normalizeSearch(text);
      var ranges = [];
      terms.forEach(function (term) {
        var position = 0;
        while ((position = lower.indexOf(term, position)) !== -1) {
          ranges.push([position, position + term.length]);
          position += term.length;
        }
      });
      ranges.sort(function (a, b) { return a[0] - b[0]; });
      var merged = [];
      ranges.forEach(function (range) {
        var last = merged[merged.length - 1];
        if (last && range[0] <= last[1]) {
          last[1] = Math.max(last[1], range[1]);
        } else {
          merged.push(range.slice());
        }
      });
      var cursor = 0;
      merged.forEach(function (range) {
        if (range[0] > cursor) element.appendChild(document.createTextNode(text.slice(cursor, range[0])));
        var mark = document.createElement('mark');
        mark.textContent = text.slice(range[0], range[1]);
        element.appendChild(mark);
        cursor = range[1];
      });
      if (cursor < text.length) element.appendChild(document.createTextNode(text.slice(cursor)));
    }

    function setSearchActive(index) {
      if (!searchItems.length) return;
      searchActive = (index + searchItems.length) % searchItems.length;
      searchItems.forEach(function (item, itemIndex) {
        var active = itemIndex === searchActive;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', String(active));
      });
      var activeItem = searchItems[searchActive];
      searchInput.setAttribute('aria-activedescendant', activeItem.id);
      activeItem.scrollIntoView({ block: 'nearest' });
    }

    function renderSearch(query) {
      query = String(query || '').trim();
      searchHint.hidden = query.length > 0;
      clearSearchResults();
      if (!query) {
        searchStatus.textContent = '';
        return;
      }
      if (!searchCache) {
        searchStatus.textContent = searchDialog.dataset.loading || '';
        return;
      }
      var terms = searchTerms(query);
      if (!terms.length) return;
      var hits = [];
      searchCache.forEach(function (page) {
        if (page.lang !== searchLang) return;
        (page.sections || []).forEach(function (section) {
          var pageTitle = normalizeSearch(page.title);
          var sectionTitle = normalizeSearch(section.title);
          var body = normalizeSearch(section.text);
          var haystack = pageTitle + ' ' + sectionTitle + ' ' + body;
          for (var i = 0; i < terms.length; i += 1) {
            if (haystack.indexOf(terms[i]) === -1) return;
          }
          var score = page.url === searchPage ? 40 : 0;
          if (pageTitle === normalizeSearch(query)) score += 80;
          if (pageTitle.indexOf(normalizeSearch(query)) !== -1) score += 30;
          if (sectionTitle === normalizeSearch(query)) score += 60;
          if (sectionTitle.indexOf(normalizeSearch(query)) !== -1) score += 20;
          terms.forEach(function (term) {
            score += Math.min(countTerm(pageTitle, term), 3) * 8;
            score += Math.min(countTerm(sectionTitle, term), 3) * 12;
            score += Math.min(countTerm(body, term), 8);
          });
          hits.push({ page: page, section: section, score: score });
        });
      });
      hits.sort(function (a, b) {
        return b.score - a.score
          || a.page.title.localeCompare(b.page.title)
          || a.section.title.localeCompare(b.section.title);
      });
      hits = hits.slice(0, 20);
      if (!hits.length) {
        searchStatus.textContent = searchDialog.dataset.empty || '';
        return;
      }
      searchStatus.textContent = hits.length + ' ' + (searchDialog.dataset.results || '');
      hits.forEach(function (hit, index) {
        var page = hit.page;
        var section = hit.section;
        var result = document.createElement('a');
        result.className = 'search-result';
        result.id = 'search-result-' + index;
        result.href = searchRoot + page.url + (section.id ? '#' + encodeURIComponent(section.id) : '');
        result.setAttribute('role', 'option');
        result.setAttribute('aria-selected', 'false');
        var meta = document.createElement('span');
        meta.className = 'search-result-meta';
        meta.textContent = page.title || '';
        var title = document.createElement('strong');
        title.className = 'search-result-title';
        var titleText = section.title || page.title || '';
        highlightSearchText(title, titleText, terms);
        var snippet = document.createElement('p');
        snippet.className = 'search-result-snippet';
        highlightSearchText(snippet, searchSnippet(section.text || '', terms), terms);
        result.appendChild(meta);
        result.appendChild(title);
        result.appendChild(snippet);
        result.addEventListener('mouseenter', function () { setSearchActive(index); });
        searchResults.appendChild(result);
      });
      searchItems = Array.prototype.slice.call(searchResults.querySelectorAll('.search-result'));
      searchActive = -1;
    }

    function openSearch(trigger) {
      searchTrigger = trigger || searchOpeners[0] || null;
      if (!searchDialog.open) searchDialog.showModal();
      var query = searchInput.value.trim();
      loadSearchIndex().then(function () {
        renderSearch(query);
        searchInput.focus();
        searchInput.select();
      }).catch(function () {
        searchStatus.textContent = searchDialog.dataset.error || '';
        searchInput.focus();
      });
    }

    searchOpeners.forEach(function (button) {
      button.addEventListener('click', function () { openSearch(button); });
    });
    if (searchClose) {
      searchClose.addEventListener('click', function () { searchDialog.close(); });
    }
    searchDialog.addEventListener('click', function (event) {
      if (event.target === searchDialog) searchDialog.close();
    });
    searchDialog.addEventListener('close', function () {
      if (searchTrigger) searchTrigger.focus();
    });
    searchInput.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      var query = searchInput.value;
      searchTimer = window.setTimeout(function () { renderSearch(query); }, 90);
    });
    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setSearchActive(searchActive + 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        setSearchActive(searchActive - 1);
      } else if (event.key === 'Enter' && searchActive >= 0 && searchItems[searchActive]) {
        event.preventDefault();
        searchItems[searchActive].click();
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && searchDialog.open) {
        searchDialog.close();
        return;
      }
      var target = event.target;
      var typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
      if (!typing && (event.key === '/' || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k'))) {
        event.preventDefault();
        openSearch();
      }
    });
  }

  var reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !reduceMotion) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach(function (element) { revealObserver.observe(element); });
  } else {
    reveals.forEach(function (element) { element.classList.add('is-visible'); });
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return fallbackCopy(text); });
    }
    return Promise.resolve(fallbackCopy(text));
  }

  function fallbackCopy(text) {
    var field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (error) { ok = false; }
    field.remove();
    return ok;
  }

  function copyFeedback(button) {
    if (button.dataset.busy) return;
    button.dataset.busy = '1';
    var original = button.textContent;
    button.textContent = labels.copiedLabel || 'Copied';
    button.classList.add('is-copied');
    window.setTimeout(function () {
      button.textContent = original;
      button.classList.remove('is-copied');
      delete button.dataset.busy;
    }, 1600);
  }

  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy'));
      if (!target) return;
      copyText(target.innerText.replace(/\s+$/, '')).then(function (ok) {
        if (ok) copyFeedback(button);
      });
    });
  });

  document.querySelectorAll('.docs-article pre').forEach(function (pre) {
    if (pre.parentElement && pre.parentElement.classList.contains('doc-pre')) return;
    var wrap = document.createElement('div');
    wrap.className = 'doc-pre';
    pre.parentNode.insertBefore(wrap, pre);
    wrap.appendChild(pre);
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy-btn';
    button.textContent = labels.copyLabel || 'Copy';
    button.addEventListener('click', function () {
      copyText(pre.innerText.replace(/\s+$/, '')).then(function (ok) {
        if (ok) copyFeedback(button);
      });
    });
    wrap.appendChild(button);
  });

  var toTop = document.getElementById('to-top');
  var toTopPet = toTop ? toTop.querySelector('.to-top-pet') : null;
  var ticking = false;

  function onScroll() {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(function () {
      var y = window.scrollY;
      if (header) header.classList.toggle('is-scrolled', y > 8);
      if (toTop) {
        var max = document.documentElement.scrollHeight - window.innerHeight;
        var progress = max > 0 ? Math.min(1, Math.max(0, y / max)) : 0;
        toTop.style.setProperty('--progress', progress.toFixed(4));
        toTop.classList.toggle('is-visible', y > 420);
      }
      ticking = false;
    });
  }

  if (toTop) {
    if (toTopPet) {
      var petDefault = toTopPet.getAttribute('src');
      var petCute = toTop.getAttribute('data-cute') || 'assets/img/pet/cute.svg';
      toTop.addEventListener('mouseenter', function () { toTopPet.src = petCute; });
      toTop.addEventListener('mouseleave', function () { toTopPet.src = petDefault; });
      toTop.addEventListener('focus', function () { toTopPet.src = petCute; });
      toTop.addEventListener('blur', function () { toTopPet.src = petDefault; });
    }
    toTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
  }

  var donateDialog = document.getElementById('donate-dialog');
  if (donateDialog && typeof donateDialog.showModal === 'function') {
    var donateImage = document.getElementById('donate-dialog-img');
    var donateTitle = document.getElementById('donate-dialog-title');
    var donateHint = document.getElementById('donate-dialog-hint');
    var donateTrigger = null;
    var donateOpenedAt = 0;

    document.querySelectorAll('[data-donate]').forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        if (donateImage) {
          donateImage.src = trigger.getAttribute('data-donate-src') || donateImage.src;
          donateImage.alt = trigger.getAttribute('data-donate-alt') || donateImage.alt;
        }
        if (donateTitle) donateTitle.textContent = trigger.getAttribute('data-donate-label') || '';
        if (donateHint) donateHint.textContent = trigger.getAttribute('data-donate-hint') || '';
        donateTrigger = trigger;
        if (!donateDialog.open) {
          donateDialog.showModal();
          donateOpenedAt = Date.now();
        }
      });
    });

    donateDialog.addEventListener('click', function (event) {
      if (event.target === donateDialog && Date.now() - donateOpenedAt > 300) {
        donateDialog.close();
      }
    });

    donateDialog.addEventListener('close', function () {
      if (donateTrigger) donateTrigger.focus();
    });
  }

  var heroCanvas = document.getElementById('hero-canvas');

  function whenIdle(callback, timeout) {
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(callback, { timeout: timeout || 2000 });
    } else {
      window.setTimeout(callback, 600);
    }
  }

  function loadDeferred() {
    if (heroCanvas && !reduceMotion) {
      whenIdle(function () {
        import('./hero-three.js').catch(function () {});
      });
    }
    whenIdle(function () {
      var script = document.createElement('script');
      script.async = true;
      script.src = '//busuanzi.ibruce.info/busuanzi/2.3/busuanzi.pure.mini.js';
      document.body.appendChild(script);
    }, 4000);
  }

  if (document.readyState === 'complete') {
    loadDeferred();
  } else {
    window.addEventListener('load', loadDeferred, { once: true });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  onScroll();
})();
