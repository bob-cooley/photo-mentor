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
     Homepage fit-to-window (2026-11-10). On desktop (window 1000px+ wide),
     if the page is taller than the window, the whole page shrinks with CSS
     zoom so header, rotator and footer all fit. 100% is the maximum (never
     enlarged past the design size); 75% is the floor, below which the page
     scrolls as normal. Recomputed on every resize, so making the window
     bigger scales a shrunk page back up toward 100%.
     =================================================================== */
  var FIT_MIN = 0.75;
  var FIT_MARGIN = 2; // px of slack so rounding never leaves a 1px scrollbar

  function fitHomepage() {
    var root = document.documentElement;
    root.style.zoom = "";
    if (window.innerWidth < 1000) return;
    // the slideshow's fullscreen view must fill the real window, so no zoom while it's open
    if (document.querySelector("ul.gallery.fullscreen")) return;

    var height = root.scrollHeight;
    if (height <= window.innerHeight) return;

    // Zooming out widens the layout (up to the 1300px max), which makes the 16:9
    // rotator taller, so re-measure and correct a few times until it fits.
    var zoom = Math.max(FIT_MIN, (window.innerHeight - FIT_MARGIN) / height);
    for (var i = 0; i < 4; i++) {
      root.style.zoom = zoom;
      height = root.scrollHeight;
      if (height <= window.innerHeight || zoom <= FIT_MIN) break;
      zoom = Math.max(FIT_MIN, zoom * (window.innerHeight - FIT_MARGIN) / height);
    }
  }

  function initHomepageFit() {
    if (!document.querySelector("main.frontpage")) return;
    // short debounce (a timer, not requestAnimationFrame, so it also runs where frames are throttled)
    var timer = null;
    function schedule() {
      window.clearTimeout(timer);
      timer = window.setTimeout(fitHomepage, 60);
    }
    fitHomepage();
    window.addEventListener("resize", schedule);
    // the theme sizes the slideshow after its images load, so measure again then
    window.addEventListener("load", schedule);
    window.setTimeout(schedule, 600);
    // re-fit when the slideshow enters or leaves fullscreen (the theme toggles a class)
    var gallery = document.querySelector("main ul.gallery");
    if (gallery && window.MutationObserver) {
      new MutationObserver(schedule).observe(gallery, { attributes: true, attributeFilter: ["class"] });
    }
  }

  function init() {
    init404Flicker();
    initNavAmpersand();
    initGalleriesNote();
    initFooterTagline();
    initHomepageFit();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
