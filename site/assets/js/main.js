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
