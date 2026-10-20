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

  var heroCanvas = document.getElementById('hero-canvas');
  if (heroCanvas && !reduceMotion) {
    var loadHero = function () {
      import('./hero-three.js').catch(function () {});
    };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(loadHero, { timeout: 2500 });
    } else {
      window.setTimeout(loadHero, 600);
    }
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  onScroll();
})();
