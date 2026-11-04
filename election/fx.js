/* Election-night effects: fireworks (Democrats hold both chambers),
   burning cards (Republicans hold both), or a large "meh." (split).
   window.ElectionFX.play("fireworks" | "flames" | "meh") */
(function () {
  var running = false;

  function overlay(extra) {
    var el = document.createElement("div");
    el.className = "fx-overlay " + (extra || "");
    document.body.appendChild(el);
    // any tap dismisses early
    el.addEventListener("click", function () { el.dispatchEvent(new Event("fx-dismiss")); });
    return el;
  }

  function fadeOut(el, ms, done) {
    el.style.transition = "opacity " + ms + "ms ease";
    el.style.opacity = "0";
    setTimeout(function () { el.remove(); if (done) done(); }, ms);
  }

  // ---------- fireworks (fireworks-js) ----------
  function fireworks(done) {
    var lib = window.Fireworks && (window.Fireworks.Fireworks || window.Fireworks.default || window.Fireworks);
    if (typeof lib !== "function") { done(); return; }
    var el = overlay("fx-fireworks");
    var fw = new lib(el, {
      autoresize: true,
      opacity: 0.5,
      acceleration: 1.05,
      friction: 0.97,
      gravity: 1.4,
      particles: 110,
      traceLength: 3,
      traceSpeed: 10,
      explosion: 7,
      intensity: 45,
      flickering: 50,
      lineStyle: "round",
      hue: { min: 0, max: 360 },
      delay: { min: 4, max: 10 },
      rocketsPoint: { min: 15, max: 85 },
      lineWidth: { explosion: { min: 1, max: 4 }, trace: { min: 1, max: 2 } },
      brightness: { min: 55, max: 85 },
      decay: { min: 0.012, max: 0.028 },
      mouse: { click: false, move: false, max: 1 },
      sound: { enabled: false }
    });
    fw.start();
    // heavy opening salvo, then a steadier show (launch() would end the show)
    setTimeout(function () { try { fw.updateOptions({ delay: { min: 10, max: 22 } }); } catch (e) {} }, 3000);
    var finished = false;
    function end() {
      if (finished) return;
      finished = true;
      fadeOut(el, 1200, function () { try { fw.stop(true); } catch (e) {} done(); });
    }
    el.addEventListener("fx-dismiss", end);
    setTimeout(end, 10000);
  }

  // ---------- burning cards (canvas particles) ----------
  function flames(done) {
    var el = overlay("fx-flames");
    var cv = document.createElement("canvas");
    el.appendChild(cv);
    var ctx = cv.getContext("2d");
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    function size() {
      cv.width = innerWidth * dpr; cv.height = innerHeight * dpr;
      cv.style.width = innerWidth + "px"; cv.style.height = innerHeight + "px";
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    size();
    addEventListener("resize", size);
    var cards = Array.prototype.slice.call(document.querySelectorAll(".card"));
    cards.forEach(function (c) { c.classList.add("burning"); });
    var parts = [];
    var MAX = 2600;
    var t0 = performance.now();
    var DUR = 10000;
    var stopSpawn = false;
    var raf;

    function spawn(r, n) {
      for (var i = 0; i < n && parts.length < MAX; i++) {
        var edge = Math.random();
        var x, y;
        if (edge < 0.7) { x = r.left + Math.random() * r.width; y = r.bottom - Math.random() * 6; }       // bottom edge
        else if (edge < 0.85) { x = r.left + Math.random() * 8; y = r.top + Math.random() * r.height; }    // left edge
        else { x = r.right - Math.random() * 8; y = r.top + Math.random() * r.height; }                    // right edge
        parts.push({
          x: x, y: y,
          vx: (Math.random() - 0.5) * 0.8,
          vy: -(1.2 + Math.random() * 2.6),
          s: 6 + Math.random() * Math.min(26, r.height / 6),
          life: 0,
          max: 35 + Math.random() * 45
        });
      }
    }

    function frame(now) {
      var elapsed = now - t0;
      if (elapsed > DUR) stopSpawn = true;
      ctx.clearRect(0, 0, innerWidth, innerHeight);
      if (!stopSpawn) {
        cards.forEach(function (c) {
          var r = c.getBoundingClientRect();
          if (r.bottom < -40 || r.top > innerHeight + 40) return;
          spawn(r, Math.max(3, Math.round(r.width / 45)));
        });
      }
      // smoke first (normal blend), then fire (additive)
      ctx.globalCompositeOperation = "source-over";
      for (var i = 0; i < parts.length; i++) {
        var p = parts[i], k = p.life / p.max;
        if (k > 0.7 && (i & 3) === 0) {  // thin, wispy smoke from a quarter of the embers
          var a = (1 - k) * 0.22;
          var sr = p.s * (0.6 + k * 0.8);
          var sg = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, sr);
          sg.addColorStop(0, "rgba(60,54,50," + a.toFixed(3) + ")");
          sg.addColorStop(1, "rgba(60,54,50,0)");
          ctx.fillStyle = sg;
          ctx.beginPath(); ctx.arc(p.x, p.y, sr, 0, 6.283); ctx.fill();
        }
      }
      ctx.globalCompositeOperation = "lighter";
      for (var j = parts.length - 1; j >= 0; j--) {
        var q = parts[j];
        q.life++;
        q.x += q.vx + Math.sin((q.life + q.y) * 0.15) * 0.6;
        q.y += q.vy;
        q.vy *= 0.985;
        var f = q.life / q.max;
        if (f >= 1) { parts.splice(j, 1); continue; }
        if (f <= 0.65) {
          var hue = 52 - f * 70;            // yellow -> orange -> red
          var light = 70 - f * 35;
          var alpha = (1 - f / 0.65) * 0.55;
          var rad = q.s * (1 - f * 0.6);
          var g = ctx.createRadialGradient(q.x, q.y, 0, q.x, q.y, rad);
          g.addColorStop(0, "hsla(" + hue + ",100%," + light + "%," + alpha + ")");
          g.addColorStop(1, "hsla(" + hue + ",100%," + light + "%,0)");
          ctx.fillStyle = g;
          ctx.beginPath(); ctx.arc(q.x, q.y, rad, 0, 6.283); ctx.fill();
        }
      }
      if (stopSpawn && parts.length === 0) return end(true);
      raf = requestAnimationFrame(frame);
    }
    raf = requestAnimationFrame(frame);

    var finished = false;
    function end(natural) {
      if (finished) return;
      finished = true;
      cancelAnimationFrame(raf);
      removeEventListener("resize", size);
      cards.forEach(function (c) { c.classList.remove("burning"); });
      fadeOut(el, natural ? 200 : 600, done);
    }
    el.addEventListener("fx-dismiss", function () { end(false); });
  }

  // ---------- meh ----------
  function meh(done) {
    var el = overlay("fx-meh");
    el.innerHTML = '<span>meh.</span>';
    var finished = false;
    function end() {
      if (finished) return;
      finished = true;
      fadeOut(el, 900, done);
    }
    el.addEventListener("fx-dismiss", end);
    setTimeout(end, 4500);
  }

  var FX = { fireworks: fireworks, flames: flames, meh: meh };

  window.ElectionFX = {
    play: function (name) {
      if (running || !FX[name]) return;
      running = true;
      FX[name](function () { running = false; });
    },
    // which effect a control result calls for, or null while undecided
    pick: function (ctl) {
      if (!ctl || !ctl.senate || !ctl.house) return null;
      var s = ctl.senate.party, h = ctl.house.party;
      if (s === "D" && h === "D") return "fireworks";
      if (s === "R" && h === "R") return "flames";
      return "meh";
    }
  };
})();
