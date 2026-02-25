<?php
include 'config/db.php';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){ header("location:feed.php"); exit(); }

if(isset($_POST['update'])){
    $sr = isset($_POST['sr_no']) ? trim($_POST['sr_no']) : '';
    $d = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $v = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
    $b = isset($_POST['bilty']) ? trim($_POST['bilty']) : '';
    $party = isset($_POST['party']) ? trim($_POST['party']) : '';
    $l = isset($_POST['location']) ? trim($_POST['location']) : '';
    $bags = isset($_POST['bags']) ? (int)$_POST['bags'] : 0;
    $f = isset($_POST['freight']) ? (int)$_POST['freight'] : 0;
    $t = isset($_POST['tender']) ? (int)$_POST['tender'] : 0;
    $p = $t - $f;
    $stmt = $conn->prepare("UPDATE bilty SET sr_no=?, date=?, vehicle=?, bilty_no=?, party=?, location=?, bags=?, freight=?, original_freight=?, tender=?, profit=? WHERE id=?");
    $stmt->bind_param("sssssssiiiii", $sr, $d, $v, $b, $party, $l, $bags, $f, $f, $t, $p, $id);
    $stmt->execute(); $stmt->close();
    header("location:feed.php"); exit();
}

$row = $conn->query("SELECT * FROM bilty WHERE id=" . (int)$id)->fetch_assoc();
if(!$row){ header("location:feed.php"); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Bilty — #<?php echo htmlspecialchars($row['bilty_no']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --red: #ef4444; --green: #22c55e;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 32px; border-bottom: 1px solid var(--border); background: var(--surface); }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-btn { padding: 8px 16px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }

  .main { display: flex; justify-content: center; padding: 36px 24px; }
  .form-card { background: var(--surface); border: 1px solid var(--border); padding: 32px; width: min(860px, 100%); position: relative; overflow: hidden; }
  .form-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }

  .form-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 6px; }
  .form-sub { font-size: 12px; font-family: var(--mono); color: var(--muted); margin-bottom: 28px; }

  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  .field input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 11px 14px; font-family: var(--font); font-size: 14px; transition: border-color 0.15s;
  }
  .field input:focus { outline: none; border-color: var(--accent); }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .field input::placeholder { color: var(--muted); }

  .form-footer { margin-top: 28px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
  .delete-btn {
    padding: 12px 20px; background: rgba(239,68,68,0.1); color: var(--red);
    border: 1px solid rgba(239,68,68,0.3); text-decoration: none; font-family: var(--font);
    font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.15s;
  }
  .delete-btn:hover { background: rgba(239,68,68,0.2); }
  .update-btn {
    padding: 12px 32px; background: var(--accent); color: #0e0f11; border: none;
    cursor: pointer; font-family: var(--font); font-size: 14px; font-weight: 800; transition: background 0.15s;
  }
  .update-btn:hover { background: #e0b030; }

  @media(max-width: 640px) {
    .topbar { padding: 14px 16px; }
    .main { padding: 20px 14px; }
    .form-card { padding: 22px 16px; }
    .grid { grid-template-columns: 1fr; }
    .form-footer { flex-direction: column-reverse; }
    .delete-btn, .update-btn { width: 100%; text-align: center; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Edit</span>
    <h1>Edit Bilty</h1>
  </div>
  <a class="nav-btn" href="feed.php">Back</a>
</div>

<div class="main">
  <div class="form-card">
    <div class="form-title">Edit Bilty</div>
    <div class="form-sub">Bilty: <?php echo htmlspecialchars($row['bilty_no']); ?> &nbsp;·&nbsp; ID: <?php echo $id; ?></div>

    <form method="post">
      <div class="grid">
        <div class="field">
          <label for="sr_no">SR No</label>
          <input id="sr_no" name="sr_no" value="<?php echo htmlspecialchars($row['sr_no'] ?? ''); ?>" required>
        </div>
        <div class="field">
          <label for="date">Date</label>
          <input id="date" type="date" name="date" value="<?php echo htmlspecialchars($row['date'] ? $row['date'] : date('Y-m-d')); ?>" required>
        </div>
        <div class="field">
          <label for="vehicle">Vehicle</label>
          <input id="vehicle" name="vehicle" value="<?php echo htmlspecialchars($row['vehicle']); ?>" required>
        </div>
        <div class="field">
          <label for="bilty">Bilty No</label>
          <input id="bilty" name="bilty" value="<?php echo htmlspecialchars($row['bilty_no']); ?>" required>
        </div>
        <div class="field">
          <label for="party">Party</label>
          <input id="party" name="party" value="<?php echo htmlspecialchars($row['party']); ?>">
        </div>
        <div class="field">
          <label for="location">Location</label>
          <input id="location" name="location" value="<?php echo htmlspecialchars($row['location']); ?>" required>
        </div>
        <div class="field">
          <label for="bags">Bags</label>
          <input id="bags" type="number" name="bags" value="<?php echo htmlspecialchars($row['bags'] ?? 0); ?>" min="0" required>
        </div>
        <div class="field">
          <label for="freight">Freight</label>
          <input id="freight" type="number" name="freight" value="<?php echo htmlspecialchars($row['freight']); ?>" min="0" required>
        </div>
        <div class="field">
          <label for="tender">Tender</label>
          <input id="tender" type="number" name="tender" value="<?php echo htmlspecialchars($row['tender']); ?>" min="0" required>
        </div>
      </div>

      <div class="form-footer">
        <a class="delete-btn" href="delete.php?id=<?php echo $id; ?>" onclick="return confirm('Delete this bilty?')">&#128465; Delete</a>
        <button class="update-btn" type="submit" name="update">Save</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
