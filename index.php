<?php
session_start();
include 'config/db.php';
$error = "";

if(isset($_SESSION['user']) && $_SESSION['user'] !== ''){
    header("location:dashboard.php");
    exit();
}

if(isset($_POST['login'])){
    $u = isset($_POST['user']) ? trim($_POST['user']) : '';
    $p = isset($_POST['pass']) ? trim($_POST['pass']) : '';
    if($u === '' || $p === ''){
        $error = "Username and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT username FROM users WHERE username=? AND password=? LIMIT 1");
        $stmt->bind_param("ss", $u, $p);
        $stmt->execute();
        $res = $stmt->get_result();
        if($res->num_rows > 0){
            $_SESSION['user'] = $u;
            unset($_SESSION['login_verified']);
            unset($_SESSION['pending_user']);
            header("location:dashboard.php");
            exit();
        } else {
            $error = "Wrong username or password.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login — Cargo</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --red: #ef4444; --text: #e8eaf0; --muted: #7c8091;
    --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text); font-family: var(--font);
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 20px;
  }
  /* subtle grid pattern */
  body::before {
    content: ''; position: fixed; inset: 0;
    background-image: linear-gradient(var(--border) 1px, transparent 1px), linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 48px 48px; opacity: 0.3; pointer-events: none;
  }

  .card {
    background: var(--surface); border: 1px solid var(--border);
    padding: 40px 36px; width: min(440px, 100%); position: relative; z-index: 1;
  }
  .card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }

  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; }
  .brand-mark {
    width: 36px; height: 36px; background: var(--accent); display: flex; align-items: center;
    justify-content: center; font-size: 17px; font-weight: 800; color: #0e0f11;
  }
  .brand-name { font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }

  .login-title { font-size: 24px; font-weight: 800; letter-spacing: -0.8px; margin-bottom: 6px; }
  .login-sub { font-size: 13px; color: var(--muted); margin-bottom: 28px; }

  .field { margin-bottom: 16px; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  .field input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 12px 14px; font-family: var(--font); font-size: 14px; transition: border-color 0.15s;
  }
  .field input:focus { outline: none; border-color: var(--accent); }
  .field input::placeholder { color: var(--muted); }

  .submit-btn {
    width: 100%; padding: 14px; background: var(--accent); color: #0e0f11; border: none;
    cursor: pointer; font-family: var(--font); font-size: 15px; font-weight: 800;
    letter-spacing: 0.3px; transition: background 0.15s; margin-top: 8px;
  }
  .submit-btn:hover { background: #e0b030; }

  .alert {
    padding: 11px 14px; margin-top: 14px; font-size: 13px;
    border-left: 3px solid var(--red); background: rgba(239,68,68,0.08); color: var(--red);
  }

  .footer-note { margin-top: 24px; text-align: center; font-size: 11px; color: var(--muted); font-family: var(--mono); letter-spacing: 0.5px; }
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-mark">C</div>
    <span class="brand-name">Cargo Manager</span>
  </div>
  <div class="login-title">Sign In</div>
  <div class="login-sub">Enter username and password.</div>

  <form method="post" autocomplete="off">
    <div class="field">
      <label for="user">Username</label>
      <input id="user" name="user" type="text" placeholder="Enter username" autocomplete="username">
    </div>
    <div class="field">
      <label for="pass">Password</label>
      <input id="pass" name="pass" type="password" placeholder="••••••••" autocomplete="current-password">
    </div>
    <button class="submit-btn" name="login" type="submit">Login →</button>
  </form>

  <?php if($error !== ""): ?>
    <div class="alert"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="footer-note">Cargo System</div>
</div>
</body>
</html>
