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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init404Flicker);
  } else {
    init404Flicker();
  }
})();
