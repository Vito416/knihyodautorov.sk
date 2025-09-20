// public/eshop/js/app.js
// Malý, robustný skript pre:
//  - dismissovanie flash správ (klik na tlačidlo)
//  - automatické zavretie (data-autoclose in seconds)
//  - toggle mobilnej navigácie (aria-expanded)
//
// Bez 3rd-party knižníc. Bezpečný a ľahko testovateľný.

(function () {
  'use strict';

  // FLASH handling
  function initFlash() {
    var root = document.querySelector('.flash-messages');
    if (!root) return;

    // dismiss button handler
    root.addEventListener('click', function (e) {
      var t = e.target;
      if (!t) return;
      var dismiss = t.closest && t.closest('[data-flash-dismiss]') || (t.matches && t.matches('[data-flash-dismiss]') ? t : null);
      if (!dismiss) {
        // maybe clicked the × button inside element with data-flash-dismiss attribute on button itself
        if (t.dataset && t.dataset.flashDismiss) {
          var id = t.dataset.flashDismiss;
          var el = document.getElementById(id);
          if (el) hideFlash(el);
        }
        return;
      }
    }, false);

    // attach per-message close buttons
    var closeButtons = root.querySelectorAll('.flash-dismiss');
    Array.prototype.forEach.call(closeButtons, function (btn) {
      btn.addEventListener('click', function (ev) {
        var id = btn.getAttribute('data-flash-dismiss');
        if (id) {
          var el = document.getElementById(id);
          if (el) hideFlash(el);
        }
      }, false);
    });

    // auto-close
    var auto = root.querySelectorAll('[data-autoclose]');
    Array.prototype.forEach.call(auto, function (el) {
      var s = parseInt(el.getAttribute('data-autoclose'), 10);
      if (isFinite(s) && s > 0) {
        setTimeout(function () { hideFlash(el); }, s * 1000);
      }
    });
  }

  function hideFlash(el) {
    try {
      el.classList.add('flash-hide');
      // CSS should fade out .flash-hide then display:none; fallback remove after timeout
      setTimeout(function () {
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }, 350);
    } catch (e) { try { if (el && el.parentNode) el.parentNode.removeChild(el); } catch (_) {} }
  }

  // NAV toggle for mobile
  function initNavToggle() {
    var toggle = document.getElementById('nav-toggle');
    var nav = document.getElementById('main-nav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      nav.classList.toggle('open');
    }, false);
  }

  // init on DOM ready (or immediately if already)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initFlash(); initNavToggle();
    });
  } else {
    initFlash(); initNavToggle();
  }
})();