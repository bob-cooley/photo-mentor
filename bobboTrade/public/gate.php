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
  .password-field button {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #a8acb1;
    font-size: 20px;
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
          <input type="password" name="password" id="password" autocomplete="current-password" autocapitalize="off" autocorrect="off" spellcheck="false" autofocus required>
          <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false">&#128065;</button>
        </div>
        <button type="submit">Enter</button>
      </form>
    </div>
  </div>
  <script>
    (function () {
      var input = document.getElementById('password');
      var toggle = document.getElementById('toggle-password');
      toggle.addEventListener('click', function () {
        var currentlyShowing = input.type === 'text';
        input.type = currentlyShowing ? 'password' : 'text';
        toggle.setAttribute('aria-label', currentlyShowing ? 'Show password' : 'Hide password');
        toggle.setAttribute('aria-pressed', String(!currentlyShowing));
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
      }
      window.addEventListener('resize', resize);

      var UP_COLOR = '#34c759';
      var DOWN_COLOR = '#ff6e64';
      var POINT_SPACING = 4;
      var STEP_INTERVAL_MS = 90;
      var LOOKBACK_STEPS = 40;
      var MIN_TREND_STEPS_RANGE = [90, 180];
      var REVERSAL_CHANCE_PER_STEP = 0.04;

      var points = [];
      var price, trend, stepsSinceReversal, minTrendSteps;

      function randRange(min, max) {
        return min + Math.random() * (max - min);
      }

      function resetState() {
        price = height * 0.55;
        trend = Math.random() < 0.5 ? 1 : -1;
        stepsSinceReversal = 0;
        minTrendSteps = randRange(MIN_TREND_STEPS_RANGE[0], MIN_TREND_STEPS_RANGE[1]);
        points = [];
      }

      function step() {
        stepsSinceReversal++;
        var noise = (Math.random() - 0.5) * height * 0.02;
        var bias = trend * height * 0.004;
        price += bias + noise;

        var min = height * 0.15, max = height * 0.85;
        if (price < min) { price = min; trend = 1; stepsSinceReversal = 0; }
        if (price > max) { price = max; trend = -1; stepsSinceReversal = 0; }

        if (stepsSinceReversal > minTrendSteps && Math.random() < REVERSAL_CHANCE_PER_STEP) {
          trend *= -1;
          stepsSinceReversal = 0;
          minTrendSteps = randRange(MIN_TREND_STEPS_RANGE[0], MIN_TREND_STEPS_RANGE[1]);
        }

        points.push(price);
        var maxPoints = Math.ceil(width / POINT_SPACING) + 2;
        while (points.length > maxPoints) points.shift();
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
        if (points.length < 2) return;

        var lookback = Math.min(points.length - 1, LOOKBACK_STEPS);
        var trendUp = points[points.length - 1] <= points[points.length - 1 - lookback];
        var color = trendUp ? UP_COLOR : DOWN_COLOR;

        ctx.beginPath();
        var firstX = width - (points.length - 1) * POINT_SPACING;
        points.forEach(function (y, i) {
          var x = width - (points.length - 1 - i) * POINT_SPACING;
          if (i === 0) ctx.moveTo(x, y);
          else ctx.lineTo(x, y);
        });
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.lineWidth = 2.5;
        ctx.strokeStyle = color;
        ctx.stroke();

        ctx.lineTo(width, height);
        ctx.lineTo(firstX, height);
        ctx.closePath();
        var gradient = ctx.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, color + '55');
        gradient.addColorStop(1, color + '00');
        ctx.fillStyle = gradient;
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
      var seedCount = Math.ceil(width / POINT_SPACING);
      for (var i = 0; i < seedCount; i++) step();
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
