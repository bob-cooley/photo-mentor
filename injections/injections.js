/* injections.js - external script for bobcooleyphoto.com, deployed via GitHub Actions
   to the site root (same pipeline as injections.css). Loaded site-wide via a single
   <script defer src="/injections.js?v=1"> pasted into IO200's Code Injection field -
   each feature below guards for the page/element it needs, so it's safe to load on
   every page even though most of it only does anything on specific templates. */
(function () {
  "use strict";

  /* ===================================================================
     404 page: independent neon-tube flicker per digit ("4", "0", "4").
     Same burst-then-pause algorithm as defunkt.com's scheduleKFlicker()
     (checked their live site/JS for reference) - a burst of a few rapid
     flashes, then a long random pause before the next burst. Genuinely
     randomized every time (new counts/durations on each run), unlike
     the earlier fixed-length CSS @keyframes version this replaces.
     =================================================================== */
  var FLICKER_DIM_CLASSES = ["flicker-dim-1", "flicker-dim-2", "flicker-dim-3"];

  function pickDimClass() {
    return FLICKER_DIM_CLASSES[Math.floor(Math.random() * FLICKER_DIM_CLASSES.length)];
  }

  function scheduleFlicker(target, rates) {
    if (!target) return;

    function runBurst() {
      var flashesRemaining = rates.burstFlashesMin + Math.floor(Math.random() * rates.burstFlashesRange);

      function flash() {
        if (flashesRemaining <= 0) {
          var nextDelay = rates.burstPauseMin + Math.random() * rates.burstPauseRange;
          window.setTimeout(runBurst, nextDelay);
          return;
        }

        var dimClass = pickDimClass();
        target.classList.add(dimClass);

        window.setTimeout(function () {
          target.classList.remove(dimClass);
          flashesRemaining -= 1;
          var pause = rates.flashGapMin + Math.random() * rates.flashGapRange;
          window.setTimeout(flash, pause);
        }, rates.flashDurationMin + Math.random() * rates.flashDurationRange);
      }

      flash();
    }

    // stagger each digit's very first burst so they don't all start together on page load
    window.setTimeout(runBurst, Math.random() * 4000);
  }

  function init404Flicker() {
    var main404 = document.querySelector("main.template-error404");
    if (!main404) return; // not on the 404 page, nothing to do

    var h1 = main404.querySelector("h1");
    var p = main404.querySelector("p");

    var baseRates = {
      burstFlashesMin: 2,
      burstFlashesRange: 4,
      flashDurationMin: 45,
      flashDurationRange: 90,
      flashGapMin: 50,
      flashGapRange: 130
    };

    // each digit flickers at a different overall rate (different burstPause range),
    // so they read as independently failing rather than synchronized
    scheduleFlicker(main404, Object.assign({}, baseRates, { burstPauseMin: 900, burstPauseRange: 3500 }));  // digit 1 "4" - flickers most often
    scheduleFlicker(h1, Object.assign({}, baseRates, { burstPauseMin: 2200, burstPauseRange: 7000 }));      // digit 2 "0" - most stable
    scheduleFlicker(p, Object.assign({}, baseRates, { burstPauseMin: 1200, burstPauseRange: 5200 }));       // digit 3 "4" - medium rate
  }

  /* ===================================================================
     Header nav: wrap the " & " in "bio & credits" in span.nav-amp so
     injections.css can narrow just those two spaces (Courier New spaces
     are a full character wide). The label text itself is unchanged.
     =================================================================== */
  function initNavAmpersand() {
    var links = document.querySelectorAll("header nav .nav-item-title");
    Array.prototype.forEach.call(links, function (link) {
      Array.prototype.slice.call(link.childNodes).forEach(function (node) {
        if (node.nodeType !== 3) return;
        var i = node.nodeValue.indexOf(" & ");
        if (i === -1) return;
        var amp = node.splitText(i);
        amp.splitText(3);
        var span = document.createElement("span");
        span.className = "nav-amp";
        span.textContent = " & ";
        amp.parentNode.replaceChild(span, amp);
      });
    });
  }

  /* ===================================================================
     Header nav: "galleries" dropdown note. Desktop shows it on hover or
     keyboard focus; the phone menu shows it under the item (CSS handles
     both). Instagram and Threads URLs match the homepage body text; Foto added 2026-11-10.
     =================================================================== */
  function initGalleriesNote() {
    var links = document.querySelectorAll("header nav > ul > li > .nav-item-title");
    Array.prototype.forEach.call(links, function (link) {
      if (link.textContent.trim().toLowerCase() !== "galleries") return;
      var li = link.parentNode;
      if (li.querySelector(".nav-galleries-note")) return;
      var note = document.createElement("div");
      note.className = "nav-galleries-note";
      note.innerHTML =
        '<span class="nav-galleries-lead">Galleries coming soon!!</span>' +
        "In the meantime, I invite you to see my daily photo posts on " +
        '<a href="https://www.instagram.com/bobcooley/" target="_blank" rel="noopener">Instagram</a>, ' +
        '<a href="https://www.threads.com/@bobcooley" target="_blank" rel="noopener">Threads</a> or ' +
        '<a href="https://the.fotoapp.co/bobcooley" target="_blank" rel="noopener">Foto</a>.';
      li.classList.add("has-galleries-note");
      li.appendChild(note);
      // IO200 can't store a bare "#" link, so the galleries item points at "/#", which loads the
      // homepage from every other page. Cancel the click so "galleries" only opens this note.
      // REMOVE THIS once the galleries item gets a real URL in IO200, or it will block that link too.
      link.addEventListener("click", function (e) {
        e.preventDefault();
      });
    });
  }

  /* ===================================================================
     Footer tagline, left of the social icons (styled in injections.css).
     Homepage only (IO200 marks it main.frontpage).
     =================================================================== */
  function initFooterTagline() {
    if (!document.querySelector("main.frontpage")) return;
    var footer = document.querySelector("footer");
    if (!footer || footer.querySelector(".footer-tagline")) return;
    var p = document.createElement("p");
    p.className = "footer-tagline";
    p.textContent = "the photographic work of bob cooley";
    footer.insertBefore(p, footer.firstChild);
  }

  /* ===================================================================
     Page fade-out (2026-11-14). Only the top navigation (logo and header
     nav links, including future galleries sublinks) fades to black before
     changing pages. Every page fades in from black on load (CSS only, see
     injections.css). Clicking the link for the page you're already on does
     nothing, so the logo can't be clicked repeatedly to replay the fade.
     =================================================================== */
  var PAGE_FADE_OUT_MS = 300;
  var PAGE_FADE_OUT_REDUCED_MS = 120;

  // bobcooleyphoto.com and www.bobcooleyphoto.com both serve the site (the logo links to www)
  function siteHost(host) {
    return host.toLowerCase().replace(/^www\./, "");
  }

  function sitePath(path) {
    return path.replace(/\/+$/, "") || "/";
  }

  function initNavFade() {
    var header = document.querySelector("header");
    if (!header) return;
    var veil = null;
    var leaving = false;
    var reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // The theme's own click handler on <header> navigates with window.location.href and ignores
    // preventDefault: on the logo (a span that wraps a zero-size link) at every width, and on every
    // nav link at phone width. So this listens on <header> in the capture phase, ahead of the
    // theme, and stops the event from reaching the theme whenever it handles the click.
    header.addEventListener("click", function (e) {
      var link = null;
      if (e.target.closest) {
        var title = e.target.closest(".website-title");
        link = title ? title.querySelector("a") : e.target.closest("nav a");
      }
      if (!link || link.closest(".nav-galleries-note")) return; // Instagram/Threads/Foto links in the popup
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return; // new tab/window
      if (link.target && link.target !== "_self") return;

      // "galleries" points at the "/#" placeholder; its click only opens the note.
      // REMOVE THIS with the click-cancel in initGalleriesNote once galleries gets a real URL.
      if (link.parentNode.classList.contains("has-galleries-note")) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      var url;
      try {
        url = new URL(link.href, window.location.href);
      } catch (err) {
        return;
      }
      if (siteHost(url.host) !== siteHost(window.location.host)) return; // external link
      e.preventDefault();
      e.stopPropagation();
      // already on this page (logo on the homepage, "contact" on the contact page, etc.): do nothing
      if (sitePath(url.pathname) === sitePath(window.location.pathname) && url.search === window.location.search) return;
      if (leaving) return;
      leaving = true;
      if (!veil) {
        veil = document.createElement("div");
        veil.className = "page-fade-veil";
        veil.setAttribute("aria-hidden", "true");
        document.body.appendChild(veil);
        void veil.offsetWidth; // commit opacity 0 so the transition runs
      }
      veil.classList.add("is-active");
      window.setTimeout(function () {
        window.location.assign(url.href);
      }, reduced ? PAGE_FADE_OUT_REDUCED_MS : PAGE_FADE_OUT_MS);
    }, true);

    // Back/Forward can restore this page from the browser cache with the veil still black
    window.addEventListener("pageshow", function (e) {
      if (!e.persisted) return;
      leaving = false;
      if (veil) veil.classList.remove("is-active");
    });
  }

  function init() {
    init404Flicker();
    initNavAmpersand();
    initGalleriesNote();
    initFooterTagline();
    initNavFade();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
