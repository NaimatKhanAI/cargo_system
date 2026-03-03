<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';

auth_require_login($conn);
$canOpenDetail = auth_has_module_access('account') || auth_has_module_access('feed') || auth_has_module_access('haleeb');
if(!$canOpenDetail){
    header("location:dashboard.php");
    exit();
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$src = strtolower(trim((string)($_GET['src'] ?? '')));
if(!in_array($type, ['feed', 'haleeb'], true) || $id <= 0){
    header("location:account.php");
    exit();
}

$row = null;
$paidTotal = 0.0;
$baseFreight = 0.0;
$remaining = 0.0;
$titleRef = '';

if($type === 'feed'){
    $stmt = $conn->prepare("SELECT b.*, COALESCE(NULLIF(u.username, ''), CASE WHEN b.added_by_user_id IS NULL THEN '-' ELSE CONCAT('User#', b.added_by_user_id) END) AS added_by_name FROM bilty b LEFT JOIN users u ON u.id=b.added_by_user_id WHERE b.id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row){
        header("location:account.php");
        exit();
    }

    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE bilty_id=? AND entry_type='debit'");
    $paidStmt->bind_param("i", $id);
    $paidStmt->execute();
    $paidRow = $paidStmt->get_result()->fetch_assoc();
    $paidStmt->close();
    $paidTotal = $paidRow && isset($paidRow['paid_total']) ? (float)$paidRow['paid_total'] : 0.0;

    $commission = isset($row['commission']) ? (float)$row['commission'] : 0.0;
    $baseFreight = isset($row['original_freight']) && $row['original_freight'] !== null
        ? (float)$row['original_freight']
        : max(((float)($row['freight'] ?? 0) - $commission), 0);
    $remaining = max(0, $baseFreight - $paidTotal);
    $titleRef = (string)($row['bilty_no'] ?? ('#' . $id));
} else {
    $stmt = $conn->prepare("SELECT h.*, COALESCE(NULLIF(u.username, ''), CASE WHEN h.added_by_user_id IS NULL THEN '-' ELSE CONCAT('User#', h.added_by_user_id) END) AS added_by_name FROM haleeb_bilty h LEFT JOIN users u ON u.id=h.added_by_user_id WHERE h.id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row){
        header("location:account.php");
        exit();
    }

    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit'");
    $paidStmt->bind_param("i", $id);
    $paidStmt->execute();
    $paidRow = $paidStmt->get_result()->fetch_assoc();
    $paidStmt->close();
    $paidTotal = $paidRow && isset($paidRow['paid_total']) ? (float)$paidRow['paid_total'] : 0.0;

    $commission = isset($row['commission']) ? (float)$row['commission'] : 0.0;
    $baseFreight = max(((float)($row['freight'] ?? 0) - $commission), 0);
    $remaining = max(0, $baseFreight - $paidTotal);
    $titleRef = (string)($row['token_no'] ?? ('#' . $id));
}

$backHref = 'account.php';
if($src === 'activity_review' && auth_can_review_activity()) $backHref = 'activity_review.php';
elseif($src === 'feed' && auth_has_module_access('feed')) $backHref = 'feed.php';
elseif($src === 'haleeb' && auth_has_module_access('haleeb')) $backHref = 'haleeb.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo $type === 'feed' ? 'Feed Bilty Detail' : 'Haleeb Bilty Detail'; ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root { --bg:#0e0f11; --surface:#16181c; --surface2:#1e2128; --border:#2a2d35; --accent:#f0c040; --green:#22c55e; --red:#ef4444; --blue:#60a5fa; --text:#e8eaf0; --muted:#7c8091; --font:'Syne',sans-serif; --mono:'DM Mono',monospace; }
  *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
  body { background:var(--bg); color:var(--text); font-family:var(--font); min-height:100vh; }
  .topbar { display:flex; justify-content:space-between; align-items:center; padding:18px 28px; border-bottom:1px solid var(--border); background:var(--surface); }
  .topbar-left { display:flex; align-items:center; gap:12px; }
  .badge { background:var(--accent); color:#0e0f11; font-size:10px; font-weight:800; padding:3px 8px; letter-spacing:1.5px; text-transform:uppercase; }
  .title { font-size:18px; font-weight:800; letter-spacing:-0.4px; }
  .nav-btn { padding:8px 14px; background:transparent; color:var(--muted); border:1px solid var(--border); text-decoration:none; font-size:13px; font-weight:600; }
  .nav-btn:hover { background:var(--surface2); color:var(--text); border-color:var(--muted); }
  .main { max-width:1100px; margin:0 auto; padding:24px 28px; }
  .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
  .card { background:var(--surface); border:1px solid var(--border); padding:14px 16px; }
  .k { color:var(--muted); font-size:10px; letter-spacing:1.2px; text-transform:uppercase; font-weight:700; margin-bottom:6px; }
  .v { font-family:var(--mono); font-size:18px; }
  .v.green { color:var(--green); }
  .v.red { color:var(--red); }
  .panel { background:var(--surface); border:1px solid var(--border); overflow-x:auto; }
  .panel-head { padding:13px 16px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
  .panel-title { font-size:11px; letter-spacing:2px; text-transform:uppercase; color:var(--muted); font-weight:700; }
  table { width:100%; border-collapse:collapse; }
  th { padding:10px 12px; text-align:left; font-size:10px; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); border-bottom:1px solid var(--border); background:var(--surface2); }
  td { padding:10px 12px; border-bottom:1px solid rgba(42,45,53,0.7); font-size:13px; font-family:var(--mono); }
  td.label { width:220px; color:var(--muted); font-family:var(--font); font-size:12px; }
  .tiny { color:var(--muted); font-size:11px; font-family:var(--font); }
  @media(max-width:780px){
    .topbar { padding:14px 16px; flex-direction:column; align-items:flex-start; gap:10px; }
    .main { padding:16px; }
    .stats { grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-left">
    <span class="badge"><?php echo $type === 'feed' ? 'Feed' : 'Haleeb'; ?></span>
    <div class="title">Bilty Detail - <?php echo htmlspecialchars($titleRef); ?></div>
  </div>
  <a class="nav-btn" href="<?php echo htmlspecialchars($backHref); ?>">Back</a>
</div>

<div class="main">
  <div class="stats">
    <div class="card">
      <div class="k">Total Cost</div>
      <div class="v">Rs <?php echo number_format($baseFreight, 2); ?></div>
    </div>
    <div class="card">
      <div class="k">Paid</div>
      <div class="v green">Rs <?php echo number_format($paidTotal, 2); ?></div>
    </div>
    <div class="card">
      <div class="k">Remaining</div>
      <div class="v <?php echo $remaining > 0.0001 ? 'red' : 'green'; ?>">Rs <?php echo number_format($remaining, 2); ?></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head">
      <span class="panel-title">Full Bilty Information</span>
      <span class="tiny">ID: <?php echo (int)$id; ?></span>
    </div>
    <table>
      <tbody>
      <?php if($type === 'feed'): ?>
        <tr><td class="label">SR No</td><td><?php echo htmlspecialchars((string)($row['sr_no'] ?? '')); ?></td></tr>
        <tr><td class="label">Date</td><td><?php echo htmlspecialchars((string)($row['date'] ?? '')); ?></td></tr>
        <tr><td class="label">Feed Section</td><td><?php echo htmlspecialchars(feed_portion_label_local((string)($row['feed_portion'] ?? ''))); ?></td></tr>
        <tr><td class="label">Bilty No</td><td><?php echo htmlspecialchars((string)($row['bilty_no'] ?? '')); ?></td></tr>
        <tr><td class="label">Vehicle</td><td><?php echo htmlspecialchars((string)($row['vehicle'] ?? '')); ?></td></tr>
        <tr><td class="label">Party</td><td><?php echo htmlspecialchars((string)($row['party'] ?? '')); ?></td></tr>
        <tr><td class="label">Location</td><td><?php echo htmlspecialchars((string)($row['location'] ?? '')); ?></td></tr>
        <tr><td class="label">Bags</td><td><?php echo (int)($row['bags'] ?? 0); ?></td></tr>
        <tr><td class="label">Freight</td><td>Rs <?php echo number_format((float)($row['freight'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Commission</td><td>Rs <?php echo number_format((float)($row['commission'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Driver Payment Type</td><td><?php echo htmlspecialchars((string)($row['freight_payment_type'] ?? 'to_pay')); ?></td></tr>
        <tr><td class="label">Tender</td><td>Rs <?php echo number_format((float)($row['tender'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Profit</td><td>Rs <?php echo number_format((float)($row['profit'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Added By</td><td><?php echo htmlspecialchars((string)($row['added_by_name'] ?? '-')); ?></td></tr>
      <?php else: ?>
        <tr><td class="label">Date</td><td><?php echo htmlspecialchars((string)($row['date'] ?? '')); ?></td></tr>
        <tr><td class="label">Token No</td><td><?php echo htmlspecialchars((string)($row['token_no'] ?? '')); ?></td></tr>
        <tr><td class="label">Vehicle</td><td><?php echo htmlspecialchars((string)($row['vehicle'] ?? '')); ?></td></tr>
        <tr><td class="label">Vehicle Type</td><td><?php echo htmlspecialchars((string)($row['vehicle_type'] ?? '')); ?></td></tr>
        <tr><td class="label">Delivery Note</td><td><?php echo htmlspecialchars((string)($row['delivery_note'] ?? '')); ?></td></tr>
        <tr><td class="label">Party</td><td><?php echo htmlspecialchars((string)($row['party'] ?? '')); ?></td></tr>
        <tr><td class="label">Location</td><td><?php echo htmlspecialchars((string)($row['location'] ?? '')); ?></td></tr>
        <tr><td class="label">Stops</td><td><?php echo htmlspecialchars((string)($row['stops'] ?? '')); ?></td></tr>
        <tr><td class="label">Freight</td><td>Rs <?php echo number_format((float)($row['freight'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Commission</td><td>Rs <?php echo number_format((float)($row['commission'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Driver Payment Type</td><td><?php echo htmlspecialchars((string)($row['freight_payment_type'] ?? 'to_pay')); ?></td></tr>
        <tr><td class="label">Tender</td><td>Rs <?php echo number_format((float)($row['tender'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Profit</td><td>Rs <?php echo number_format((float)($row['profit'] ?? 0), 2); ?></td></tr>
        <tr><td class="label">Added By</td><td><?php echo htmlspecialchars((string)($row['added_by_name'] ?? '-')); ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>

