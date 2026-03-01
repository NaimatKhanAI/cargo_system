<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_activity_review('dashboard.php');

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isSuperAdmin = auth_is_super_admin();
$msg = '';
$err = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
    $reviewNote = isset($_POST['review_note']) ? trim((string)$_POST['review_note']) : '';
    if($notificationId <= 0){
        $err = 'Invalid notification.';
    } elseif(isset($_POST['mark_new'])){
        $reviewError = '';
        $ok = activity_mark_new_local($conn, $notificationId, $userId, $reviewError);
        if($ok) $msg = 'Status reset to New.';
        else $err = $reviewError !== '' ? $reviewError : 'Could not reset status.';
    } elseif(isset($_POST['mark_ok'])){
        $reviewError = '';
        $ok = activity_review_mark_local($conn, $notificationId, $userId, false, $reviewNote, $reviewError);
        if($ok) $msg = 'Activity marked as OK.';
        else $err = $reviewError !== '' ? $reviewError : 'Could not mark as OK.';
    } elseif(isset($_POST['flag_admin'])){
        if($reviewNote === ''){
            $err = 'Please add note before flagging to admin.';
        } else {
            $reviewError = '';
            $ok = activity_review_mark_local($conn, $notificationId, $userId, true, $reviewNote, $reviewError);
            if($ok){
                activity_raise_admin_flag_request_local($conn, $notificationId, $userId, $reviewNote);
                $msg = 'Issue flagged for admin review.';
            }
            else $err = $reviewError !== '' ? $reviewError : 'Could not flag for admin.';
        }
    }
}

$statusFilter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'new';
if(!in_array($statusFilter, ['all', 'new', 'ok', 'flagged'], true)) $statusFilter = 'new';
if(!$isSuperAdmin && $statusFilter === 'flagged') $statusFilter = 'new';

$countAll = 0; $countNew = 0; $countOk = 0; $countFlagged = 0;
$countRes = $conn->query("SELECT status, COUNT(*) AS c FROM activity_notifications GROUP BY status");
while($countRes && $cRow = $countRes->fetch_assoc()){
    $st = strtolower((string)$cRow['status']);
    $cv = (int)$cRow['c'];
    $countAll += $cv;
    if($st === 'new') $countNew = $cv;
    elseif($st === 'ok') $countOk = $cv;
    elseif($st === 'flagged') $countFlagged = $cv;
}

$items = activity_fetch_items_local($conn, $statusFilter, 300, !$isSuperAdmin);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Activity Review</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11;
    --surface: #16181c;
    --surface2: #1e2128;
    --border: #2a2d35;
    --accent: #f0c040;
    --green: #22c55e;
    --red: #ef4444;
    --blue: #60a5fa;
    --text: #e8eaf0;
    --muted: #7c8091;
    --font: 'Syne', sans-serif;
    --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }
  .topbar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 28px; border-bottom: 1px solid var(--border); background: var(--surface);
  }
  .logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .logo h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .nav-btn {
    padding: 8px 14px; background: transparent; color: var(--muted);
    border: 1px solid var(--border); text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.15s;
  }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }

  .main { max-width: 1440px; margin: 0 auto; padding: 22px 28px; }
  .alert {
    padding: 12px 16px; margin-bottom: 14px; font-size: 13px;
    border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green);
  }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }
  .filters {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border); padding: 14px 16px; margin-bottom: 14px;
  }
  .f-btn {
    padding: 7px 12px; border: 1px solid var(--border); text-decoration: none;
    color: var(--muted); font-size: 12px; font-weight: 700; letter-spacing: 0.4px;
  }
  .f-btn.active, .f-btn:hover { background: var(--accent); color: #0e0f11; border-color: var(--accent); }
  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; min-width: 1100px; }
  thead tr { background: var(--surface2); }
  th {
    padding: 11px 12px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;
    color: var(--muted); border-bottom: 1px solid var(--border);
  }
  td { padding: 10px 12px; font-size: 12px; border-bottom: 1px solid rgba(42,45,53,0.7); vertical-align: top; font-family: var(--mono); }
  tbody tr:hover { background: var(--surface2); }
  .status-pill {
    display: inline-block; padding: 3px 10px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;
    border: 1px solid transparent;
  }
  .st-new { color: var(--blue); background: rgba(96,165,250,0.15); border-color: rgba(96,165,250,0.25); }
  .st-ok { color: var(--green); background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.25); }
  .st-flagged { color: var(--red); background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.25); }
  .msg { font-family: var(--font); font-size: 12px; line-height: 1.4; }
  .meta { color: var(--muted); font-size: 10px; margin-top: 4px; }
  .payload {
    background: var(--bg); border: 1px solid var(--border); padding: 6px; margin-top: 6px;
    font-size: 10px; color: var(--muted); max-width: 360px; white-space: pre-wrap; word-break: break-word;
  }
  .actions { display: flex; flex-direction: column; gap: 6px; min-width: 200px; }
  .actions input[type="text"] {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 7px 8px; font-size: 11px;
  }
  .act-row { display: flex; gap: 6px; }
  .btn-mini {
    flex: 1; padding: 7px 8px; border: 1px solid var(--border); background: var(--surface2); color: var(--text);
    font-size: 11px; font-weight: 700; cursor: pointer;
  }
  .btn-mini:hover { border-color: var(--muted); }
  .btn-mini.flag { color: var(--red); border-color: rgba(239,68,68,0.28); background: rgba(239,68,68,0.08); }
  .btn-mini.flag:hover { background: rgba(239,68,68,0.14); }
  .review-ok {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--green); font-size: 12px; font-weight: 700; font-family: var(--font);
  }
  .review-flagged {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--red); font-size: 12px; font-weight: 700; font-family: var(--font);
  }
  .review-new {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--blue); font-size: 12px; font-weight: 700; font-family: var(--font);
  }
  .empty { padding: 24px 14px; text-align: center; color: var(--muted); font-size: 13px; }

  @media(max-width: 780px){
    .topbar { padding: 14px 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .main { padding: 16px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="logo">
    <span class="badge">Review</span>
    <h1>Activity Notifications</h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn" href="account.php">Account</a>
    <?php if($isSuperAdmin): ?><a class="nav-btn" href="super_admin.php">Super Admin</a><?php endif; ?>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
  </div>
</div>

<div class="main">
  <?php if($msg !== ''): ?><div class="alert"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err !== ''): ?><div class="alert error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <div class="filters">
    <a class="f-btn <?php echo $statusFilter === 'new' ? 'active' : ''; ?>" href="activity_review.php?status=new">New (<?php echo $countNew; ?>)</a>
    <a class="f-btn <?php echo $statusFilter === 'ok' ? 'active' : ''; ?>" href="activity_review.php?status=ok">OK (<?php echo $countOk; ?>)</a>
    <?php if($isSuperAdmin): ?>
      <a class="f-btn <?php echo $statusFilter === 'flagged' ? 'active' : ''; ?>" href="activity_review.php?status=flagged">Flagged (<?php echo $countFlagged; ?>)</a>
    <?php endif; ?>
    <a class="f-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="activity_review.php?status=all">All (<?php echo $countAll; ?>)</a>
  </div>

  <div class="table-wrap">
    <?php if(count($items) === 0): ?>
      <div class="empty">No activity notifications found.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Module / Type</th>
            <th>Message</th>
            <th>Status</th>
            <th>Created</th>
            <th>Review</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $it): ?>
            <?php
              $st = strtolower((string)$it['status']);
              $stClass = $st === 'ok' ? 'st-ok' : ($st === 'flagged' ? 'st-flagged' : 'st-new');
              $payloadText = '';
              if(isset($it['payload']) && trim((string)$it['payload']) !== ''){
                  $payloadText = (string)$it['payload'];
              }
            ?>
            <tr>
              <td>#<?php echo (int)$it['id']; ?></td>
              <td>
                <div><?php echo htmlspecialchars(strtoupper((string)$it['module_key'])); ?></div>
                <div class="meta"><?php echo htmlspecialchars((string)$it['activity_type']); ?> / <?php echo htmlspecialchars((string)$it['reference_type']); ?> #<?php echo (int)$it['reference_id']; ?></div>
              </td>
              <td>
                <div class="msg"><?php echo htmlspecialchars((string)$it['message']); ?></div>
                <?php if($payloadText !== ''): ?>
                  <div class="payload"><?php echo htmlspecialchars($payloadText); ?></div>
                <?php endif; ?>
                <?php if(isset($it['review_note']) && trim((string)$it['review_note']) !== ''): ?>
                  <div class="meta">Review note: <?php echo htmlspecialchars((string)$it['review_note']); ?></div>
                <?php endif; ?>
              </td>
              <td><span class="status-pill <?php echo $stClass; ?>"><?php echo htmlspecialchars(strtoupper($st)); ?></span></td>
              <td>
                <div><?php echo htmlspecialchars((string)$it['created_at']); ?></div>
                <div class="meta">By: <?php echo htmlspecialchars((string)($it['created_by_name'] ?? 'System')); ?></div>
                <?php if(isset($it['reviewed_at']) && (string)$it['reviewed_at'] !== ''): ?>
                  <div class="meta">Reviewed: <?php echo htmlspecialchars((string)$it['reviewed_at']); ?></div>
                  <div class="meta">Reviewer: <?php echo htmlspecialchars((string)($it['reviewed_by_name'] ?? '')); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if($st === 'ok'): ?>
                  <span class="review-ok">&#10003; Reviewed</span>
                <?php elseif($st === 'flagged'): ?>
                  <span class="review-flagged">&#9873; Flagged to Admin</span>
                <?php else: ?>
                  <span class="review-new">&#9679; New</span>
                <?php endif; ?>
                <form method="post" class="actions" style="margin-top:8px;">
                  <input type="hidden" name="notification_id" value="<?php echo (int)$it['id']; ?>">
                  <input type="text" name="review_note" placeholder="Review note (required for flag)">
                  <div class="act-row">
                    <button class="btn-mini" type="submit" name="mark_ok">Mark OK</button>
                    <button class="btn-mini flag" type="submit" name="flag_admin">Flag to Admin</button>
                  </div>
                  <?php if($st !== 'new'): ?>
                    <div class="act-row">
                      <button class="btn-mini" type="submit" name="mark_new">Set New</button>
                    </div>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

