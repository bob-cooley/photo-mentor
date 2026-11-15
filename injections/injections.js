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
        '<span class="nav-galleries-lead">Galleries coming soon!</span>' +
        "In the meantime, I invite you to see my daily documentary photo posts on " +
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
     Fit-to-window, every page (2026-11-14). On desktop (window 1000px+
     wide), every page is shrunk with CSS zoom by the same amount: the
     amount the homepage needs so its header, slideshow and footer fit the
     window. Inner pages use the homepage's number (not their own height),
     so the logo, nav and margins line up across the whole site. 100% is
     the maximum, 75% the floor (below that the homepage scrolls).

     The homepage's height at 100% is its "fixed" part (header, pinstripes,
     footer, copyright) plus the 16:9 slideshow, whose width depends on the
     zoom (1300px max container, 48px padding each side). Solving that for
     the window height gives the zoom on any page without measuring the
     homepage. Every page uses the same FIT_FIXED number, so the zoom is
     identical site-wide. Re-measure FIT_FIXED if the homepage header or
     footer height changes (homepage content height minus slideshow height,
     at 100%, in a window shorter than the page).
     =================================================================== */
  /* HEAD SNIPPET (2026-11-14): pasted once into IO200's head Code Injection, right after the
     injections.css <link>. It runs before the first paint, so the page never shows at 100% and
     then jumps to the fit zoom (the logo stays visible through the fade, so a jump would show).
     It repeats fitZoom() below in short form: if FIT_* numbers change here, re-paste it.
     It also sets html.page-fade-in, which lifts the logo above the fade-in layer until it ends,
     and applies a saved light-mode choice (2026-11-15) so light pages never flash dark.

<script>
(function(){var d=document.documentElement;d.classList.add("page-fade-in");
try{if(localStorage.getItem("bcp-theme")==="light"){d.classList.add("theme-light");var m=document.querySelector('link[href*="mode-dark.css"]');if(m)m.disabled=true}}catch(e){}
function done(){d.classList.remove("page-fade-in")}
document.addEventListener("animationend",function(e){if(e.animationName==="page-fade-in")done()});
setTimeout(done,3000);
var w=innerWidth,h=innerHeight-2,f=316;if(w<1000)return;
var r=9/16;if(f+r*(Math.min(1300,w)-96)<=h)return;
var z=(h-r*w)/(f-r*96);if(!(z>w/1300))z=h/(f+r*1204);
z=Math.max(0.75,Math.min(1,z));if(z<1)d.style.zoom=z})();
</script>
  */
  var FIT_MIN = 0.75;
  var FIT_MARGIN = 2;           // px of slack so rounding never leaves a 1px scrollbar
  var FIT_MIN_WIDTH = 1000;     // phone/tablet layouts never zoom
  var FIT_MAX_WIDTH = 1300;     // #container max-width
  var FIT_PADDING = 96;         // #container padding, 48px each side
  var FIT_RATIO = 9 / 16;       // slideshow height / width
  var FIT_FIXED = 316;          // homepage height minus slideshow at 100%, measured 2026-11-15

  // zoom at which the homepage (fixed part + slideshow) fits the window
  function fitZoom(fixed) {
    var w = window.innerWidth;
    var h = window.innerHeight - FIT_MARGIN;
    if (fixed + FIT_RATIO * (Math.min(FIT_MAX_WIDTH, w) - FIT_PADDING) <= h) return 1;
    // layout narrower than the 1300px container max: slideshow width = w/zoom - padding
    var zoom = (h - FIT_RATIO * w) / (fixed - FIT_RATIO * FIT_PADDING);
    // layout at the container max: slideshow width is fixed at 1300 - padding
    if (!(zoom > w / FIT_MAX_WIDTH)) zoom = h / (fixed + FIT_RATIO * (FIT_MAX_WIDTH - FIT_PADDING));
    return Math.max(FIT_MIN, Math.min(1, zoom));
  }

  // full-window overlays (slideshow fullscreen, lightbox) must fill the real window, so no zoom then
  function fitOverlayOpen() {
    return !!(document.fullscreenElement ||
      document.querySelector("ul.gallery.fullscreen") ||
      document.body.classList.contains("glightbox-open") ||
      document.documentElement.classList.contains("glightbox-open"));
  }

  function fitPage() {
    var root = document.documentElement;
    if (window.innerWidth < FIT_MIN_WIDTH || fitOverlayOpen()) {
      root.style.zoom = "";
      return;
    }
    var zoom = fitZoom(FIT_FIXED);
    root.style.zoom = zoom < 1 ? String(zoom) : "";
  }

  function initFit() {
    // short debounce (a timer, not requestAnimationFrame, so it also runs where frames are throttled)
    var timer = null;
    function schedule() {
      window.clearTimeout(timer);
      timer = window.setTimeout(fitPage, 60);
    }
    fitPage();
    window.addEventListener("resize", schedule);
    window.addEventListener("load", schedule);
    window.setTimeout(schedule, 600);
    document.addEventListener("fullscreenchange", schedule);
    if (window.MutationObserver) {
      // the theme toggles classes for slideshow fullscreen and the lightbox
      var observer = new MutationObserver(schedule);
      observer.observe(document.body, { attributes: true, attributeFilter: ["class"] });
      observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
      var gallery = document.querySelector("main ul.gallery");
      if (gallery) observer.observe(gallery, { attributes: true, attributeFilter: ["class"] });
    }
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
      document.documentElement.classList.add("page-fade-out"); // keeps the logo above the veil (CSS)
      veil.classList.add("is-active");
      window.setTimeout(function () {
        window.location.assign(url.href);
      }, reduced ? PAGE_FADE_OUT_REDUCED_MS : PAGE_FADE_OUT_MS);
    }, true);

    // Back/Forward can restore this page from the browser cache with the veil still black
    window.addEventListener("pageshow", function (e) {
      if (!e.persisted) return;
      leaving = false;
      document.documentElement.classList.remove("page-fade-out");
      if (veil) veil.classList.remove("is-active");
    });
  }

  /* ===================================================================
     Light/dark toggle (2026-11-15). Dark is the default. The button in the
     header's top-right corner switches to light: it adds html.theme-light,
     turns off the theme's mode-dark.css and remembers the choice in
     localStorage ("bcp-theme"). The head Code Injection script applies a
     saved "light" before the first paint, so light pages never flash dark.
     The circle's rotation is CSS (injections.css, "Light mode").
     =================================================================== */
  var THEME_KEY = "bcp-theme";

  function isLight() {
    return document.documentElement.classList.contains("theme-light");
  }

  function applyTheme(light) {
    document.documentElement.classList.toggle("theme-light", light);
    var darkSheet = document.querySelector('link[href*="mode-dark.css"]');
    if (darkSheet) darkSheet.disabled = light;
  }

  function initThemeToggle() {
    var holder = document.querySelector("header .title-container");
    if (!holder || holder.querySelector(".theme-toggle")) return;
    // in case the head script isn't there, still honor a saved choice
    try {
      if (window.localStorage.getItem(THEME_KEY) === "light" && !isLight()) applyTheme(true);
    } catch (err) {
      // storage blocked: stay dark
    }
    var button = document.createElement("button");
    button.type = "button";
    button.className = "theme-toggle";
    button.innerHTML = '<img src="/theme-toggle.png" alt="" width="26" height="26">';
    function label() {
      var text = isLight() ? "Switch to dark mode" : "Switch to light mode";
      button.setAttribute("aria-label", text);
      button.title = text;
    }
    label();
    holder.appendChild(button);
    button.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation(); // keep the theme's header click handler (phone menu) out of it
      var light = !isLight();
      applyTheme(light);
      try {
        window.localStorage.setItem(THEME_KEY, light ? "light" : "dark");
      } catch (err) {
        // storage blocked: the choice lasts until the next page load
      }
      label();
    });
  }

  function init() {
    init404Flicker();
    initNavAmpersand();
    initGalleriesNote();
    initFooterTagline();
    initFit();
    initNavFade();
    initThemeToggle();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
