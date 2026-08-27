<?php
/**
 * Front controller for the whole /bobboTrade/ path. Every request under
 * this directory is routed here by .htaccess, so a session check gates
 * the app shell, the JS/CSS bundle, AND the static JSON data files
 * underneath it — not just an HTML landing page.
 *
 * Replaces the earlier HTTP Basic Auth, which couldn't support a custom
 * login page: browser-native auth dialogs can't be restyled (no
 * show/hide-password toggle possible), and nothing renders behind them
 * since the server returns 401 before any page content is sent.
 *
 * To change the login password: generate a new bcrypt hash with
 *   htpasswd -nbBC 12 <username> <new-password>
 * and paste the hash portion (after the colon) into PASSWORD_HASH below.
 */

const PASSWORD_HASH = '$2y$12$xBXXPizlFlfnzlmdqq9QLu1bsOkil0tmuDDCYJnJHqCd3/.80przG';
const SESSION_COOKIE_DAYS = 90;
const APP_ROOT = __DIR__;
const BASE_PATH = '/bobboTrade/';
// Runtime-only file, never in git — a portfolio share count is real
// financial data and this repo is public. Written/read only through
// handle_portfolio_api() below, which is itself behind the session
// check. Deploys never touch it: it isn't part of the built dist/
// output, so the FTP sync (which only pushes/updates files present
// locally, never deletes extras) leaves it alone across releases —
// same pattern already used for ai_usage.json/insight.json.
const PORTFOLIO_FILE = APP_ROOT . '/portfolio-data.json';

session_name('bobbotrade_session');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * SESSION_COOKIE_DAYS,
    'path' => BASE_PATH,
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$loginError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (password_verify($_POST['password'], PASSWORD_HASH)) {
        $_SESSION['bobbotrade_authed'] = true;
        session_regenerate_id(true);
        header('Location: ' . BASE_PATH);
        exit;
    }
    $loginError = true;
}

if (empty($_SESSION['bobbotrade_authed'])) {
    render_login_page($loginError);
    exit;
}

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === BASE_PATH . 'api/portfolio') {
    handle_portfolio_api();
    exit;
}

serve_static_file();

function render_login_page(bool $error): void
{
    http_response_code($error ? 401 : 200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>bobboTrade</title>
<style>
  :root {
    --up: #34c759;
    --down: #ff6e64;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    height: 100%;
    background: #0b0d10;
    font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif;
    overflow: hidden;
  }
  #chart {
    position: fixed;
    inset: 0;
    opacity: 0.6;
    z-index: 0;
  }
  .login-wrap {
    position: relative;
    z-index: 1;
    min-height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .login-card {
    width: 100%;
    max-width: 360px;
    background: rgba(11, 13, 16, 0.78);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(20px);
    padding: 32px 28px;
    text-align: center;
  }
  .login-card h1 {
    color: #f2f3f5;
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 6px;
  }
  .login-card p.tagline {
    color: #a8acb1;
    font-size: 14px;
    margin: 0 0 24px;
  }
  .password-field {
    position: relative;
    margin-bottom: 16px;
  }
  .password-field input {
    width: 100%;
    background: #14171b;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 12px;
    color: #f2f3f5;
    font-size: 17px;
    padding: 14px 44px 14px 16px;
  }
  .password-field input:focus {
    outline: 2px solid var(--up);
    outline-offset: 1px;
  }
  .password-field input::placeholder {
    color: #6b6f76;
  }
  .password-field button {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: #a8acb1;
    padding: 8px;
    cursor: pointer;
    line-height: 1;
    opacity: 0.6;
  }
  .password-field button[aria-pressed="true"] {
    color: var(--up);
    opacity: 1;
  }
  .login-card button[type="submit"] {
    width: 100%;
    background: var(--up);
    color: #06240f;
    font-size: 17px;
    font-weight: 700;
    border: none;
    border-radius: 12px;
    padding: 14px;
    cursor: pointer;
  }
  .error {
    color: var(--down);
    font-size: 14px;
    margin: -8px 0 16px;
  }
</style>
</head>
<body>
  <canvas id="chart"></canvas>
  <div class="login-wrap">
    <div class="login-card">
      <h1>bobboTrade</h1>
      <p class="tagline">Private dashboard</p>
      <?php if ($error): ?><p class="error">Incorrect password. Try again.</p><?php endif; ?>
      <form method="post">
        <div class="password-field">
          <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" autocapitalize="off" autocorrect="off" spellcheck="false" autofocus required>
          <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false">
            <svg id="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <button type="submit">Enter</button>
      </form>
    </div>
  </div>
  <script>
    (function () {
      var input = document.getElementById('password');
      var toggle = document.getElementById('toggle-password');
      var eyeIcon = document.getElementById('eye-icon');
      var eyeOffIcon = document.getElementById('eye-off-icon');
      toggle.addEventListener('click', function () {
        var currentlyShowing = input.type === 'text';
        input.type = currentlyShowing ? 'password' : 'text';
        toggle.setAttribute('aria-label', currentlyShowing ? 'Show password' : 'Hide password');
        toggle.setAttribute('aria-pressed', String(!currentlyShowing));
        eyeIcon.style.display = currentlyShowing ? '' : 'none';
        eyeOffIcon.style.display = currentlyShowing ? 'none' : '';
      });
    })();

    (function () {
      var canvas = document.getElementById('chart');
      var ctx = canvas.getContext('2d');
      var width, height, dpr;

      function resize() {
        dpr = window.devicePixelRatio || 1;
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        tipX = width * (1 - MARGIN_RATIO);
      }
      window.addEventListener('resize', resize);

      var UP_COLOR = '#34c759';
      var DOWN_COLOR = '#ff6e64';
      var POINT_SPACING = 4;
      var STEP_INTERVAL_MS = 150;
      var DOT_RADIUS = 5;
      var MARGIN_RATIO = 0.1; // pen sits 10% in from the right edge
      var ERASE_MARGIN = 40; // px past the left edge before a point is dropped
      var VIEW_AMPLITUDE = 50; // fixed vertical span shown, in abstract value units — never auto-zooms
      var CENTER_EASE = 0.004; // how slowly the window pans to follow the baseline (must be slow relative to a regime's duration, or it cancels out peaks/valleys as they happen)
      var MEAN_REVERSION = 0.09; // how hard local noise gets pulled back toward the current regime's target each step
      var NOISE_SCALE = 3;
      var BASELINE_DRIFT_PER_STEP = 0.035; // the slow secular uptrend — deliberately tiny next to VIEW_AMPLITUDE
      var VOLATILITY_RAMP_STEPS = 650; // steps for local chop/regimes to ramp from calm to full strength
      var RAMP_MIN_FACTOR = 0.12; // chop never goes fully flat, even at age 0

      // Local price action is a mean-reverting oscillator (bounded, so
      // chop always stays a constant size on screen), not an unbounded
      // random walk — that was the actual bug last round: an unbounded
      // walk plus a view that auto-fits the whole visible history makes
      // the chop shrink relative to the frame as soon as any trend
      // accumulates, which reads as "just a climb" no matter how the
      // regimes are tuned. A separate, much slower baseline provides
      // the gentle long-term uptrend on top of that bounded chop.
      // Regimes bias the oscillator's reversion TARGET (a temporary
      // peak or valley to chase and settle back from) and volatility,
      // rather than adding permanent drift.
      var REGIMES = {
        normal:     { target: 0,                    vol: 1.0, min: 25, max: 55 },
        rally:      { target: VIEW_AMPLITUDE * 0.5,  vol: 1.1, min: 18, max: 34 },
        correction: { target: -VIEW_AMPLITUDE * 0.42, vol: 1.2, min: 15, max: 30 },
        shock:      { target: -VIEW_AMPLITUDE * 0.9, vol: 2.4, min: 3,  max: 6  },
        choppy:     { target: 0,                    vol: 2.0, min: 10, max: 22 },
        recovery:   { target: 0,                    vol: 1.3, min: 18, max: 34 }
      };
      var NEXT_AFTER = { shock: 'choppy', choppy: 'recovery', recovery: 'normal', rally: 'normal', correction: 'normal' };

      var values = [];
      var value, baseline, localOffset, regime, regimeStepsLeft, age;
      var displayCenter, displayMin, displayMax;
      var tipX;

      function randRange(min, max) {
        return min + Math.random() * (max - min);
      }

      function enterRegime(name) {
        regime = name;
        regimeStepsLeft = Math.round(randRange(REGIMES[name].min, REGIMES[name].max));
      }

      function resetState() {
        baseline = 0;
        localOffset = 0;
        value = 0;
        values = [];
        age = 0;
        // The window starts centered above 0, so the line begins below
        // the visible frame and climbs into view rather than snapping
        // in at the bottom edge immediately.
        displayCenter = VIEW_AMPLITUDE * 0.55;
        enterRegime('normal');
      }

      function maybeTransition() {
        regimeStepsLeft--;
        if (regimeStepsLeft > 0) return;
        if (regime === 'normal') {
          var roll = Math.random();
          if (roll < 0.05) enterRegime('shock');
          else if (roll < 0.32) enterRegime('rally');
          else if (roll < 0.58) enterRegime('correction');
          else enterRegime('normal');
        } else {
          enterRegime(NEXT_AFTER[regime] || 'normal');
        }
      }

      function step() {
        maybeTransition();
        var r = REGIMES[regime];
        // Chop and regime pull both ramp in from RAMP_MIN_FACTOR up to
        // full strength over the first VOLATILITY_RAMP_STEPS — a fresh
        // page load starts calm (small peaks/valleys) and gradually
        // gets livelier, rather than being fully volatile immediately.
        // Baseline drift is NOT ramped, so the gentle incline is
        // present at a steady rate throughout.
        age++;
        var t = Math.min(1, age / VOLATILITY_RAMP_STEPS);
        var ramp = RAMP_MIN_FACTOR + (1 - RAMP_MIN_FACTOR) * (t * t * (3 - 2 * t));

        // Sum of uniforms approximates a bell curve, so most ticks are
        // small with occasional larger swings — reads as more natural
        // up/down chatter than a flat uniform random walk.
        var noise = (Math.random() + Math.random() + Math.random() - 1.5) * r.vol * NOISE_SCALE;
        var pull = (r.target - localOffset) * MEAN_REVERSION;
        localOffset += (pull + noise) * ramp;
        baseline += BASELINE_DRIFT_PER_STEP;
        value = baseline + localOffset;
        values.push(value);

        var maxPoints = Math.ceil((tipX + ERASE_MARGIN) / POINT_SPACING) + 2;
        while (values.length > maxPoints) values.shift();
      }

      function updateViewWindow() {
        displayCenter += (value - displayCenter) * CENTER_EASE;
        displayMin = displayCenter - VIEW_AMPLITUDE / 2;
        displayMax = displayCenter + VIEW_AMPLITUDE / 2;
      }

      function valueToY(v) {
        return height - ((v - displayMin) / VIEW_AMPLITUDE) * height;
      }

      function drawGrid() {
        ctx.strokeStyle = 'rgba(255,255,255,0.06)';
        ctx.lineWidth = 1;
        var rows = 6;
        for (var i = 1; i < rows; i++) {
          var y = (height / rows) * i;
          ctx.beginPath();
          ctx.moveTo(0, y);
          ctx.lineTo(width, y);
          ctx.stroke();
        }
      }

      function draw() {
        ctx.clearRect(0, 0, width, height);
        drawGrid();
        if (values.length < 2) return;
        updateViewWindow();

        for (var i = 0; i < values.length - 1; i++) {
          var x0 = tipX - (values.length - 1 - i) * POINT_SPACING;
          var x1 = tipX - (values.length - 2 - i) * POINT_SPACING;
          var y0 = valueToY(values[i]);
          var y1 = valueToY(values[i + 1]);
          var color = values[i + 1] >= values[i] ? UP_COLOR : DOWN_COLOR;

          ctx.beginPath();
          ctx.moveTo(x0, y0);
          ctx.lineTo(x1, y1);
          ctx.lineTo(x1, height);
          ctx.lineTo(x0, height);
          ctx.closePath();
          ctx.fillStyle = color + '22';
          ctx.fill();

          ctx.beginPath();
          ctx.moveTo(x0, y0);
          ctx.lineTo(x1, y1);
          ctx.lineJoin = 'round';
          ctx.lineCap = 'round';
          ctx.lineWidth = 2.5;
          ctx.strokeStyle = color;
          ctx.stroke();
        }

        // The stylus tip — pinned 10% in from the right edge, only
        // ever moving vertically as it rides the newest value, like a
        // seismograph needle with the paper (the trace) feeding left
        // beneath it and erasing once it scrolls off-screen.
        var last = values.length - 1;
        var tipY = valueToY(values[last]);
        var tipColor = values[last] >= values[last - 1] ? UP_COLOR : DOWN_COLOR;
        ctx.save();
        ctx.shadowColor = tipColor;
        ctx.shadowBlur = 10;
        ctx.beginPath();
        ctx.arc(tipX, tipY, DOT_RADIUS, 0, Math.PI * 2);
        ctx.fillStyle = tipColor;
        ctx.fill();
        ctx.restore();
        ctx.beginPath();
        ctx.arc(tipX, tipY, DOT_RADIUS * 0.4, 0, Math.PI * 2);
        ctx.fillStyle = '#0b0d10';
        ctx.fill();
      }

      var lastTime = null;
      var accumulator = 0;

      function loop(time) {
        if (lastTime === null) lastTime = time;
        var dt = time - lastTime;
        lastTime = time;

        accumulator += dt;
        while (accumulator > STEP_INTERVAL_MS) {
          step();
          accumulator -= STEP_INTERVAL_MS;
        }
        draw();
        requestAnimationFrame(loop);
      }

      resize();
      resetState();
      // Silently fast-forward past the flat true origin (age 0) before
      // the first frame, so the erase boundary — where the buffer runs
      // out of history — is already off-screen from the start instead
      // of appearing as a hard vertical wall partway across the page.
      // The window stays centered high (see resetState), so none of
      // this pre-fill is actually visible; it only sets up what's
      // already "behind" the pen once real-time rendering begins.
      var prefillSteps = Math.ceil((tipX + ERASE_MARGIN) / POINT_SPACING) + 40;
      for (var p = 0; p < prefillSteps; p++) step();
      requestAnimationFrame(loop);
    })();
  </script>
</body>
</html>
    <?php
}

function serve_static_file(): void
{
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $relative = substr($uri, strlen(BASE_PATH));
    if ($relative === '' || $relative === false) {
        $relative = 'index.html';
    }
    $relative = ltrim($relative, '/');

    $path = realpath(APP_ROOT . '/' . $relative);
    $appRootReal = realpath(APP_ROOT);
    if ($path === false || strpos($path, $appRootReal) !== 0 || !is_file($path) || basename($path) === 'gate.php') {
        $path = APP_ROOT . '/index.html';
    }

    header('Content-Type: ' . mime_for($path));
    $cacheable = preg_match('/\.(js|css)$/', $path);
    header('Cache-Control: ' . ($cacheable ? 'public, max-age=31536000, immutable' : 'no-cache'));
    readfile($path);
}

function mime_for(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'html': return 'text/html; charset=utf-8';
        case 'js': return 'application/javascript';
        case 'css': return 'text/css';
        case 'json': return 'application/json';
        case 'png': return 'image/png';
        case 'svg': return 'image/svg+xml';
        case 'ico': return 'image/x-icon';
        case 'txt': return 'text/plain';
        default: return 'application/octet-stream';
    }
}

/**
 * Server-side portfolio persistence — GET returns the current share
 * count for a ticker, POST updates it. Both mom and the user see the
 * same value from any device, unlike the old browser-localStorage-only
 * fallback. Gated by the same session check as everything else in this
 * file (the caller already verified $_SESSION['bobbotrade_authed']
 * before reaching here).
 *
 * The store is keyed by ticker so each holding (MPC, COP, ...) has its
 * own share count. load_portfolio_store() transparently migrates the
 * original single-ticker file shape (a bare {"shares": ..., "updatedAt":
 * ...} object, from before multi-ticker support) into the new
 * {"MPC": {"shares": ..., "updatedAt": ...}} shape, attributing that
 * legacy value to MPC since it was the only ticker tracked at the time.
 */
function handle_portfolio_api(): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $ticker = strtoupper((string) ($_GET['ticker'] ?? ''));
    if (!preg_match('/^[A-Z]{1,10}$/', $ticker)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing ticker']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $store = load_portfolio_store();
        echo json_encode($store[$ticker] ?? ['shares' => null, 'updatedAt' => null]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $shares = is_array($body) ? ($body['shares'] ?? null) : null;

        if ($shares !== null && (!is_numeric($shares) || $shares < 0 || $shares > 100000000)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid share count']);
            return;
        }

        $entry = [
            'shares' => $shares !== null ? (float) $shares : null,
            'updatedAt' => gmdate('c'),
        ];

        $store = load_portfolio_store();
        $store[$ticker] = $entry;
        file_put_contents(PORTFOLIO_FILE, json_encode($store), LOCK_EX);
        echo json_encode($entry);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function load_portfolio_store(): array
{
    if (!is_file(PORTFOLIO_FILE)) {
        return [];
    }
    $contents = file_get_contents(PORTFOLIO_FILE);
    $decoded = $contents !== false ? json_decode($contents, true) : null;
    if (!is_array($decoded)) {
        return [];
    }
    if (array_key_exists('shares', $decoded)) {
        // Legacy single-ticker shape — migrate to MPC.
        return ['MPC' => $decoded];
    }
    return $decoded;
}
