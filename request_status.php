<?php
session_start();
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
auth_require_login($conn);

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$username = isset($_SESSION['user']) ? (string)$_SESSION['user'] : '';
$canFeed = auth_has_module_access('feed');
$canHaleeb = auth_has_module_access('haleeb');
$canAccount = auth_has_module_access('account');
$isSuperAdmin = auth_is_super_admin();

function request_action_label_local($actionType){
    $label = trim((string)$actionType);
    if($label === '') return '-';
    $label = str_replace('_', ' ', $label);
    return ucwords($label);
}

function request_status_class_local($status){
    $s = strtolower(trim((string)$status));
    if($s === 'approved') return 'st-approved';
    if($s === 'rejected') return 'st-rejected';
    return 'st-pending';
}

function request_status_label_local($status){
    $s = strtolower(trim((string)$status));
    if($s === 'approved') return 'Approved';
    if($s === 'rejected') return 'Rejected';
    return 'Pending';
}

function get_owned_request_local($conn, $requestId, $userId){
    $stmt = $conn->prepare("SELECT id, module_key, entity_table, entity_id, action_type, payload, status, requested_by FROM change_requests WHERE id=? AND requested_by=? LIMIT 1");
    $stmt->bind_param("ii", $requestId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    if($requestId <= 0){
        header("location:request_status.php?msg=invalid");
        exit();
    }

    $requestRow = get_owned_request_local($conn, $requestId, $userId);
    if(!$requestRow){
        header("location:request_status.php?msg=not_found");
        exit();
    }

    if(isset($_POST['re_request'])){
        if((string)$requestRow['status'] !== 'rejected'){
            header("location:request_status.php?msg=not_rejected");
            exit();
        }

        $moduleKey = (string)$requestRow['module_key'];
        $entityTable = (string)$requestRow['entity_table'];
        $entityId = isset($requestRow['entity_id']) ? (int)$requestRow['entity_id'] : 0;
        $actionType = (string)$requestRow['action_type'];
        $payload = request_payload_decode_local(isset($requestRow['payload']) ? (string)$requestRow['payload'] : '');
        $newRequestId = create_change_request_local($conn, $moduleKey, $entityTable, $entityId, $actionType, $payload, $userId);
        header("location:request_status.php?msg=" . ($newRequestId > 0 ? 'rerequested' : 'rerequest_failed'));
        exit();
    }

    if(isset($_POST['delete_request'])){
        $status = (string)$requestRow['status'];
        if(!in_array($status, ['pending', 'rejected'], true)){
            header("location:request_status.php?msg=cannot_delete");
            exit();
        }

        $del = $conn->prepare("DELETE FROM change_requests WHERE id=? AND requested_by=?");
        $del->bind_param("ii", $requestId, $userId);
        $del->execute();
        $ok = $del->affected_rows > 0;
        $del->close();
        if($ok){
            activity_notify_local(
                $conn,
                (string)$requestRow['module_key'],
                'change_request_deleted_by_owner',
                'change_request',
                (int)$requestId,
                'Pending/rejected request deleted by requester',
                ['action_type' => (string)$requestRow['action_type']],
                $userId
            );
        }
        header("location:request_status.php?msg=" . ($ok ? 'deleted' : 'delete_failed'));
        exit();
    }

    header("location:request_status.php");
    exit();
}

$message = '';
$messageError = false;
if(isset($_GET['msg'])){
    $m = trim((string)$_GET['msg']);
    if($m === 'rerequested') $message = 'Request sent again for approval.';
    elseif($m === 'rerequest_failed'){ $message = 'Could not send request again.'; $messageError = true; }
    elseif($m === 'deleted') $message = 'Request deleted.';
    elseif($m === 'delete_failed'){ $message = 'Could not delete request.'; $messageError = true; }
    elseif($m === 'cannot_delete'){ $message = 'Only pending or rejected requests can be deleted.'; $messageError = true; }
    elseif($m === 'not_rejected'){ $message = 'Only rejected requests can be sent again.'; $messageError = true; }
    elseif($m === 'not_found'){ $message = 'Request not found.'; $messageError = true; }
    elseif($m === 'invalid'){ $message = 'Invalid request.'; $messageError = true; }
}

$requests = [];
$stmt = $conn->prepare("SELECT id, module_key, action_type, entity_id, status, review_note, created_at, reviewed_at FROM change_requests WHERE requested_by=? ORDER BY id DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
while($res && $row = $res->fetch_assoc()){
    $requests[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Request Status</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
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
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge {
    background: var(--accent); color: #0e0f11; font-size: 10px;
    font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase;
  }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; gap: 6px; flex-wrap: wrap; }
  .nav-btn {
    padding: 8px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border);
    text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s;
  }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }

  .main { max-width: 1260px; margin: 0 auto; padding: 22px 28px; }
  .hello {
    background: var(--surface); border: 1px solid var(--border); padding: 14px 16px; margin-bottom: 14px;
    font-size: 13px; color: var(--muted);
  }
  .hello strong { color: var(--text); }
  .alert {
    padding: 12px 16px; margin-bottom: 14px; font-size: 13px;
    border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green);
  }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-head {
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    font-size: 11px; color: var(--muted); font-weight: 700; letter-spacing: 1.8px; text-transform: uppercase;
  }
  table { width: 100%; border-collapse: collapse; min-width: 900px; }
  thead tr { background: var(--surface2); }
  th {
    text-align: left; padding: 11px 12px; font-size: 10px; color: var(--muted);
    font-weight: 700; letter-spacing: 1.3px; text-transform: uppercase; border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  td {
    padding: 10px 12px; font-size: 12px; font-family: var(--mono);
    border-bottom: 1px solid rgba(42,45,53,0.7); vertical-align: top;
  }
  tbody tr:hover { background: var(--surface2); }
  .small-note { color: var(--muted); font-family: var(--font); font-size: 12px; }
  .status-pill {
    display: inline-block; padding: 3px 10px; border: 1px solid transparent;
    font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;
  }
  .st-pending { color: var(--blue); background: rgba(96,165,250,0.15); border-color: rgba(96,165,250,0.25); }
  .st-approved { color: var(--green); background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.25); }
  .st-rejected { color: var(--red); background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.25); }
  .actions { display: flex; gap: 6px; }
  .btn-mini {
    padding: 6px 10px; border: 1px solid var(--border); background: var(--surface2); color: var(--text);
    font-size: 11px; font-family: var(--font); font-weight: 700; cursor: pointer; white-space: nowrap;
  }
  .btn-mini:hover { border-color: var(--muted); }
  .btn-mini.red { color: var(--red); border-color: rgba(239,68,68,0.28); background: rgba(239,68,68,0.08); }
  .btn-mini.red:hover { background: rgba(239,68,68,0.14); }
  .empty {
    padding: 26px 16px; text-align: center; color: var(--muted); font-size: 13px;
    background: var(--surface); border: 1px solid var(--border);
  }

  @media(max-width: 760px){
    .topbar { padding: 14px 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .main { padding: 16px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Requests</span>
    <h1>Request Status</h1>
  </div>
  <div class="nav-links">
    <?php if($canFeed): ?><a class="nav-btn" href="feed.php">Feed</a><?php endif; ?>
    <?php if($canHaleeb): ?><a class="nav-btn" href="haleeb.php">Haleeb</a><?php endif; ?>
    <?php if($canAccount): ?><a class="nav-btn" href="account.php">Account</a><?php endif; ?>
    <?php if($isSuperAdmin): ?><a class="nav-btn" href="super_admin.php">Super Admin</a><?php endif; ?>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
  </div>
</div>

<div class="main">
  <div class="hello">Logged in as <strong><?php echo htmlspecialchars($username !== '' ? $username : ('User#' . $userId)); ?></strong></div>

  <?php if($message !== ''): ?>
    <div class="alert <?php echo $messageError ? 'error' : ''; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <?php if(count($requests) === 0): ?>
    <div class="empty">No requests found.</div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="tbl-head">Your Request History</div>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Module</th>
            <th>Action</th>
            <th>Entity</th>
            <th>Status</th>
            <th>Review Note</th>
            <th>Requested At</th>
            <th>Reviewed At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($requests as $row): ?>
            <?php
              $status = isset($row['status']) ? (string)$row['status'] : 'pending';
              $statusClass = request_status_class_local($status);
              $canReRequest = $status === 'rejected';
              $canDelete = in_array($status, ['pending', 'rejected'], true);
            ?>
            <tr>
              <td><?php echo (int)$row['id']; ?></td>
              <td><?php echo htmlspecialchars(strtoupper((string)$row['module_key'])); ?></td>
              <td><?php echo htmlspecialchars(request_action_label_local($row['action_type'])); ?></td>
              <td><?php echo (int)$row['entity_id']; ?></td>
              <td><span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars(request_status_label_local($status)); ?></span></td>
              <td class="small-note"><?php echo htmlspecialchars((string)($row['review_note'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)$row['created_at']); ?></td>
              <td><?php echo htmlspecialchars((string)($row['reviewed_at'] ?? '')); ?></td>
              <td>
                <div class="actions">
                  <?php if($canReRequest): ?>
                    <form method="post" style="margin:0">
                      <input type="hidden" name="request_id" value="<?php echo (int)$row['id']; ?>">
                      <button class="btn-mini" type="submit" name="re_request">Re-request</button>
                    </form>
                  <?php endif; ?>
                  <?php if($canDelete): ?>
                    <form method="post" style="margin:0" onsubmit="return confirm('Delete this request?');">
                      <input type="hidden" name="request_id" value="<?php echo (int)$row['id']; ?>">
                      <button class="btn-mini red" type="submit" name="delete_request">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</body>
</html>

