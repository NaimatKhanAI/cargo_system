<?php
session_start();
if(!isset($_SESSION['user'])){
    header("location:index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cargo Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11;
    --surface: #16181c;
    --surface2: #1e2128;
    --border: #2a2d35;
    --accent: #f0c040;
    --green: #22c55e;
    --blue: #60a5fa;
    --purple: #c084fc;
    --red: #ef4444;
    --text: #e8eaf0;
    --muted: #7c8091;
    --font: 'Syne', sans-serif;
    --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; display: flex; flex-direction: column; }

  .topbar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 32px; border-bottom: 1px solid var(--border); background: var(--surface);
  }
  .logo { display: flex; align-items: center; gap: 12px; }
  .logo-mark {
    width: 34px; height: 34px; background: var(--accent); display: flex; align-items: center;
    justify-content: center; font-size: 16px; font-weight: 800; color: #0e0f11;
  }
  .logo-text { font-size: 17px; font-weight: 800; letter-spacing: -0.5px; }
  .logout-btn {
    padding: 8px 18px; background: transparent; color: var(--muted); border: 1px solid var(--border);
    text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600;
    transition: all 0.15s;
  }
  .logout-btn:hover { color: var(--red); border-color: var(--red); background: rgba(239,68,68,0.06); }

  .main {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 48px 24px;
  }
  .greeting {
    text-align: center; margin-bottom: 48px;
  }
  .greeting-label {
    font-size: 11px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 10px;
  }
  .greeting-title { font-size: 36px; font-weight: 800; letter-spacing: -1px; }
  .greeting-title span { color: var(--accent); }

  .grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; width: min(780px, 100%);
  }
  .card {
    display: flex; flex-direction: column; gap: 12px;
    background: var(--surface); border: 1px solid var(--border);
    padding: 24px; text-decoration: none; color: var(--text);
    transition: all 0.18s; position: relative; overflow: hidden;
  }
  .card::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    transition: opacity 0.18s; opacity: 0;
  }
  .card:hover { background: var(--surface2); transform: translateY(-1px); }
  .card:hover::before { opacity: 1; }
  .card-feed::before { background: var(--accent); }
  .card-haleeb::before { background: var(--blue); }
  .card-account::before { background: var(--green); }
  .card-image::before { background: var(--purple); }

  .card-icon {
    width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
    font-size: 18px;
  }
  .icon-feed { background: rgba(240,192,64,0.12); border: 1px solid rgba(240,192,64,0.2); color: var(--accent); }
  .icon-haleeb { background: rgba(96,165,250,0.12); border: 1px solid rgba(96,165,250,0.2); color: var(--blue); }
  .icon-account { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.2); color: var(--green); }
  .icon-image { background: rgba(192,132,252,0.12); border: 1px solid rgba(192,132,252,0.2); color: var(--purple); }

  .card-title { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; }
  .card-desc { font-size: 13px; color: var(--muted); line-height: 1.5; }
  .card-arrow {
    position: absolute; right: 20px; top: 50%; transform: translateY(-50%);
    color: var(--border); font-size: 20px; transition: all 0.18s;
  }
  .card:hover .card-arrow { color: var(--muted); right: 16px; }

  @media(max-width: 600px) {
    .grid { grid-template-columns: 1fr; }
    .topbar { padding: 14px 16px; }
    .greeting-title { font-size: 26px; }
    .main { padding: 32px 16px; }
  }
</style>
</head>
<body>
<div class="topbar">
  <div class="logo">
    <div class="logo-mark">C</div>
    <span class="logo-text">Cargo Manager</span>
  </div>
  <a class="logout-btn" href="logout.php">Logout</a>
</div>

<div class="main">
  <div class="greeting">
    <div class="greeting-label">Welcome back</div>
    <div class="greeting-title">Cargo <span>Dashboard</span></div>
  </div>

  <div class="grid">
    <a class="card card-feed" href="feed.php">
      <div class="card-icon icon-feed">&#9646;</div>
      <div class="card-title">Feed</div>
      <div class="card-desc">Feed factory bilty records and profit details.</div>
      <span class="card-arrow">›</span>
    </a>
    <a class="card card-haleeb" href="haleeb.php">
      <div class="card-icon icon-haleeb">&#9647;</div>
      <div class="card-title">Haleeb</div>
      <div class="card-desc">Haleeb factory bilty records and tracking.</div>
      <span class="card-arrow">›</span>
    </a>
    <a class="card card-account" href="account.php">
      <div class="card-icon icon-account">&#8350;</div>
      <div class="card-title">Account Ledger</div>
      <div class="card-desc">Debit/credit entries and overall balances.</div>
      <span class="card-arrow">›</span>
    </a>
    <a class="card card-image" href="process_img.php">
      <div class="card-icon icon-image">&#9741;</div>
      <div class="card-title">Image Processing</div>
      <div class="card-desc">Rate-list image processing and data extraction.</div>
      <span class="card-arrow">›</span>
    </a>
  </div>
</div>
</body>
</html>