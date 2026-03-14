<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';

auth_require_login($conn);
if(!auth_can_manage_users()){
    header("location:dashboard.php");
    exit();
}

$msg = '';
$err = '';

function bool_post_local($key){ return isset($_POST[$key]) ? 1 : 0; }
function decode_payload_local($raw){ if(trim((string)$raw) === '') return []; $decoded = json_decode((string)$raw, true); return is_array($decoded) ? $decoded : []; }
function value_text_local($v){ if($v === null) return '(empty)'; $t = trim((string)$v); return $t === '' ? '(empty)' : $t; }
function feed_portion_post_local($key){
    return feed_portion_list_to_csv_local(isset($_POST[$key]) ? $_POST[$key] : '');
}

function build_change_lines_local($conn, $requestRow){
    $actionType = isset($requestRow['action_type']) ? (string)$requestRow['action_type'] : '';
    $entityId = isset($requestRow['entity_id']) ? (int)$requestRow['entity_id'] : 0;
    $payload = decode_payload_local(isset($requestRow['payload']) ? (string)$requestRow['payload'] : '');
    $lines = [];

    if($actionType === 'feed_update'){
        $row = null;
        if($entityId > 0){ $stmt = $conn->prepare("SELECT sr_no, date, vehicle, bilty_no, party, location, bags, freight, commission, tender FROM bilty WHERE id=? LIMIT 1"); $stmt->bind_param("i", $entityId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
        $map = ['sr_no'=>'SR No','date'=>'Date','vehicle'=>'Vehicle','bilty_no'=>'Bilty No','party'=>'Party','location'=>'Location','bags'=>'Bags','freight'=>'Freight','commission'=>'Commission','tender'=>'Tender'];
        foreach($map as $k => $label){ if(!array_key_exists($k, $payload)) continue; $old = $row && isset($row[$k]) ? $row[$k] : ''; $new = $payload[$k]; if((string)$old !== (string)$new) $lines[] = ['label'=>$label,'old'=>$old,'new'=>$new]; }
    } elseif($actionType === 'haleeb_update'){
        $row = null;
        if($entityId > 0){ $stmt = $conn->prepare("SELECT date, vehicle, vehicle_type, delivery_note, token_no, party, location, stops, freight, commission, tender FROM haleeb_bilty WHERE id=? LIMIT 1"); $stmt->bind_param("i", $entityId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
        $map = ['date'=>'Date','vehicle'=>'Vehicle','vehicle_type'=>'Vehicle Type','delivery_note'=>'Delivery Note','token_no'=>'Token No','party'=>'Party','location'=>'Location','stops'=>'Stops','freight'=>'Freight','commission'=>'Commission','tender'=>'Tender'];
        foreach($map as $k => $label){ if(!array_key_exists($k, $payload)) continue; $old = $row && isset($row[$k]) ? $row[$k] : ''; $new = $payload[$k]; if((string)$old !== (string)$new) $lines[] = ['label'=>$label,'old'=>$old,'new'=>$new]; }
    } elseif($actionType === 'account_update'){
        $row = null;
        if($entityId > 0){ $stmt = $conn->prepare("SELECT entry_date, category, entry_type, amount_mode, amount, note FROM account_entries WHERE id=? LIMIT 1"); $stmt->bind_param("i", $entityId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); }
        $map = ['entry_date'=>'Date','category'=>'Category','entry_type'=>'Type','amount_mode'=>'Mode','amount'=>'Amount','note'=>'Note'];
        foreach($map as $k => $label){ if(!array_key_exists($k, $payload)) continue; $old = $row && isset($row[$k]) ? $row[$k] : ''; $new = $payload[$k]; if((string)$old !== (string)$new) $lines[] = ['label'=>$label,'old'=>$old,'new'=>$new]; }
    } elseif($actionType === 'feed_delete'){
        $stmt = $conn->prepare("SELECT bilty_no, vehicle, party, location FROM bilty WHERE id=? LIMIT 1"); $stmt->bind_param("i", $entityId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if($row){ $lines[] = ['label'=>'Delete Feed Bilty','old'=>'','new'=>$row['bilty_no'] . ' | ' . $row['vehicle'] . ' | ' . $row['party']]; } else { $lines[] = ['label'=>'Delete Feed Bilty ID','old'=>'','new'=>$entityId]; }
    } elseif($actionType === 'haleeb_delete'){
        $stmt = $conn->prepare("SELECT token_no, vehicle, party, location FROM haleeb_bilty WHERE id=? LIMIT 1"); $stmt->bind_param("i", $entityId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if($row){ $lines[] = ['label'=>'Delete Haleeb Token','old'=>'','new'=>$row['token_no'] . ' | ' . $row['vehicle'] . ' | ' . $row['party']]; } else { $lines[] = ['label'=>'Delete Haleeb ID','old'=>'','new'=>$entityId]; }
    } elseif($actionType === 'account_delete'){
        $stmt = $conn->prepare("SELECT entry_date, category, entry_type, amount FROM account_entries WHERE id=? LIMIT 1"); $stmt->bind_param("i", $entityId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if($row){ $lines[] = ['label'=>'Delete Entry','old'=>'','new'=>$row['entry_date'] . ' | ' . $row['category'] . ' | Rs ' . $row['amount']]; } else { $lines[] = ['label'=>'Delete Account Entry ID','old'=>'','new'=>$entityId]; }
    } elseif($actionType === 'feed_pay' || $actionType === 'haleeb_pay'){
        $lines[] = ['label'=>'Date','old'=>'','new'=>$payload['entry_date'] ?? ''];
        $lines[] = ['label'=>'Mode / Category','old'=>'','new'=>($payload['amount_mode'] ?? '') . ' / ' . ($payload['category'] ?? '')];
        $lines[] = ['label'=>'Amount','old'=>'','new'=>'Rs ' . ($payload['amount'] ?? '')];
        $lines[] = ['label'=>'Note','old'=>'','new'=>$payload['note'] ?? ''];
    } elseif($actionType === 'activity_flag'){
        $stmt = $conn->prepare("SELECT module_key, activity_type, message, review_note FROM activity_notifications WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($n){
            $lines[] = ['label'=>'Flagged Module','old'=>'','new'=>$n['module_key']];
            $lines[] = ['label'=>'Activity Type','old'=>'','new'=>$n['activity_type']];
            $lines[] = ['label'=>'Activity Message','old'=>'','new'=>$n['message']];
            $lines[] = ['label'=>'Reviewer Note','old'=>'','new'=>$n['review_note']];
        } else {
            $lines[] = ['label'=>'Flagged Notification ID','old'=>'','new'=>$entityId];
        }
    }

    if(count($lines) === 0) $lines[] = ['label'=>'No diff available','old'=>'','new'=>''];
    return $lines;
}

// Handle POST
if(isset($_POST['create_user'])){
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
    $role = isset($_POST['role']) ? trim((string)$_POST['role']) : 'sub_admin';
    $feedPortion = feed_portion_post_local('feed_portion');
    if($username === '' || $password === ''){ $err = 'Username and password required.'; }
    elseif(!in_array($role, ['super_admin','sub_admin','viewer'], true)){ $err = 'Invalid role.'; }
    else {
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1"); $chk->bind_param("s", $username); $chk->execute(); $exists = $chk->get_result()->num_rows > 0; $chk->close();
        if($exists){ $err = 'Username already exists.'; }
        else {
            $af = bool_post_local('can_access_feed'); $ah = bool_post_local('can_access_haleeb'); $aa = bool_post_local('can_access_account'); $aip = bool_post_local('can_access_image_processing'); $cra = bool_post_local('can_review_activity'); $ia = bool_post_local('is_active'); $cmu = bool_post_local('can_manage_users');
            if($role !== 'super_admin'){ $cmu = 0; }
            $cb = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $ins = $conn->prepare("INSERT INTO users(username, password, role, is_active, can_access_feed, feed_portion, can_access_haleeb, can_access_account, can_access_image_processing, can_manage_users, can_review_activity, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sssissiiiiii", $username, $password, $role, $ia, $af, $feedPortion, $ah, $aa, $aip, $cmu, $cra, $cb); $ins->execute(); $ins->close();
            $msg = 'User created.';
        }
    }
}
if(isset($_POST['update_user'])){
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $selfId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = isset($_POST['role']) ? trim((string)$_POST['role']) : 'sub_admin';
    $feedPortion = feed_portion_post_local('feed_portion');
    $ia = bool_post_local('is_active'); $af = bool_post_local('can_access_feed'); $ah = bool_post_local('can_access_haleeb'); $aa = bool_post_local('can_access_account'); $aip = bool_post_local('can_access_image_processing'); $cra = bool_post_local('can_review_activity'); $cmu = bool_post_local('can_manage_users');
    $password = isset($_POST['new_password']) ? trim((string)$_POST['new_password']) : '';
    if($userId <= 0){ $err = 'Invalid user.'; }
    elseif(!in_array($role, ['super_admin','sub_admin','viewer'], true)){ $err = 'Invalid role.'; }
    else {
        if($userId === $selfId){
            // Logged-in super admin cannot change own role/access; only password allowed.
            if($password !== ''){
                $pwd = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                $pwd->bind_param("si", $password, $userId);
                $pwd->execute();
                $pwd->close();
                $msg = 'Password updated. Own role/access cannot be changed.';
            } else {
                $msg = 'Own role/access cannot be changed.';
            }
        } else {
            if($role !== 'super_admin'){ $cmu = 0; }

            $srStmt = $conn->prepare("SELECT role, can_manage_users FROM users WHERE id=? LIMIT 1");
            $srStmt->bind_param("i", $userId);
            $srStmt->execute();
            $srRow = $srStmt->get_result()->fetch_assoc();
            $srStmt->close();

            $isCurrentManagerSuper = $srRow && $srRow['role'] === 'super_admin' && (int)$srRow['can_manage_users'] === 1;
            $willRemainManagerSuper = ($role === 'super_admin' && (int)$cmu === 1);
            if($isCurrentManagerSuper && !$willRemainManagerSuper){
                $scRes = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='super_admin' AND can_manage_users=1");
                $sc = $scRes ? (int)$scRes->fetch_assoc()['c'] : 0;
                if($sc <= 1) $err = 'Last user-management super admin cannot be downgraded.';
            }

            if($err === ''){
                $upd = $conn->prepare("UPDATE users SET role=?, is_active=?, can_access_feed=?, feed_portion=?, can_access_haleeb=?, can_access_account=?, can_access_image_processing=?, can_manage_users=?, can_review_activity=? WHERE id=?");
                $upd->bind_param("siiisiiiii", $role, $ia, $af, $feedPortion, $ah, $aa, $aip, $cmu, $cra, $userId); $upd->execute(); $upd->close();
                if($password !== ''){ $pwd = $conn->prepare("UPDATE users SET password=? WHERE id=?"); $pwd->bind_param("si", $password, $userId); $pwd->execute(); $pwd->close(); }
                $msg = 'User updated.';
            }
        }
    }
}
if(isset($_POST['delete_user'])){
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $selfId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if($userId <= 0){ $err = 'Invalid user.'; }
    elseif($userId === $selfId){ $err = 'You cannot delete your own account.'; }
    else {
        $rs = $conn->prepare("SELECT role, can_manage_users FROM users WHERE id=? LIMIT 1"); $rs->bind_param("i", $userId); $rs->execute(); $rr = $rs->get_result()->fetch_assoc(); $rs->close();
        if($rr && $rr['role'] === 'super_admin' && (int)$rr['can_manage_users'] === 1){ $scRes = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='super_admin' AND can_manage_users=1"); $sc = $scRes ? (int)$scRes->fetch_assoc()['c'] : 0; if($sc <= 1) $err = 'Last user-management super admin cannot be deleted.'; }
        if($err === ''){ $del = $conn->prepare("DELETE FROM users WHERE id=?"); $del->bind_param("i", $userId); $del->execute(); $del->close(); $msg = 'User deleted.'; }
    }
}
if(isset($_POST['approve_request']) || isset($_POST['reject_request'])){
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $reviewNote = isset($_POST['review_note']) ? trim((string)$_POST['review_note']) : '';
    if($requestId <= 0){
        $err = 'Invalid request.';
    } else {
        $reviewedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $reviewError = '';
        $isApprove = isset($_POST['approve_request']);
        $ok = review_change_request_local($conn, $requestId, $reviewedBy, $isApprove, $reviewNote, $reviewError, ['feed_update', 'feed_delete', 'haleeb_update', 'haleeb_delete', 'account_update', 'account_delete', 'activity_flag', 'feed_pay', 'haleeb_pay']);
        if($ok){
            $msg = $isApprove ? 'Request approved and applied.' : 'Request rejected.';
        } else {
            $err = $reviewError !== '' ? $reviewError : 'Request review failed.';
        }
    }
}

$users = [];
$usersRes = $conn->query("SELECT id, username, role, is_active, can_access_feed, feed_portion, can_access_haleeb, can_access_account, can_access_image_processing, can_manage_users, can_review_activity, created_at FROM users ORDER BY id ASC");
while($usersRes && $u = $usersRes->fetch_assoc()) $users[] = $u;
$pendingRequests = fetch_pending_change_requests_local($conn);
$selfId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$flaggedActivityCount = activity_count_flagged_for_admin_local($conn);
$feedPortionOptions = feed_portion_options_local();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Super Admin Panel</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --green: #22c55e; --red: #ef4444; --blue: #60a5fa; --purple: #c084fc;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  /* TOPBAR */
  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 28px; border-bottom: 1px solid var(--border); background: var(--surface); position: sticky; top: 0; z-index: 100; }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge-pill { background: var(--red); color: #fff; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 17px; font-weight: 800; letter-spacing: -0.4px; }
  .nav-links { display: flex; gap: 6px; }
  .nav-btn { padding: 7px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 12px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }

  .main { max-width: 1400px; margin: 0 auto; padding: 24px 28px; }

  /* ALERTS */
  .alert { padding: 11px 14px; margin-bottom: 14px; font-size: 13px; border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green); }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

  /* SECTION */
  .section { background: var(--surface); border: 1px solid var(--border); margin-bottom: 16px; }
  .section-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border); }
  .section-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  .section-count { font-family: var(--mono); font-size: 11px; color: var(--accent); }
  .section-body { padding: 16px 18px; }

  /* CREATE USER FORM */
  .create-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
  .cf-field { display: flex; flex-direction: column; gap: 5px; }
  .cf-label { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); }
  .cf-input, .cf-select { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 8px 10px; font-family: var(--font); font-size: 13px; min-width: 120px; }
  .cf-input:focus, .cf-select:focus { outline: none; border-color: var(--accent); }
  .chk-group { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding-top: 2px; }
  .chk-label { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--muted); cursor: pointer; white-space: nowrap; }
  .chk-label input[type="checkbox"] { accent-color: var(--accent); width: 13px; height: 13px; }
  .portion-group { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding-top: 2px; }
  .portion-label { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--muted); cursor: pointer; white-space: nowrap; }
  .portion-label input[type="checkbox"] { accent-color: var(--accent); width: 13px; height: 13px; }
  .btn-create { padding: 9px 20px; background: var(--accent); color: #0e0f11; border: none; cursor: pointer; font-family: var(--font); font-size: 13px; font-weight: 800; transition: background 0.15s; white-space: nowrap; align-self: flex-end; }
  .btn-create:hover { background: #e0b030; }

  /* USERS TABLE */
  .users-table { width: 100%; border-collapse: collapse; overflow-x: auto; display: block; }
  .users-table thead tr { background: var(--surface2); }
  .users-table th { padding: 9px 10px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  .users-table td { padding: 8px 10px; border-bottom: 1px solid rgba(42,45,53,0.5); vertical-align: middle; }
  .users-table tbody tr:hover { background: var(--surface2); }
  .user-name { font-size: 13px; font-weight: 700; }
  .user-meta { font-size: 10px; font-family: var(--mono); color: var(--muted); margin-top: 2px; }
  .self-badge { display: inline-block; padding: 1px 6px; background: rgba(96,165,250,0.12); color: var(--blue); border: 1px solid rgba(96,165,250,0.2); font-size: 9px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; margin-left: 4px; }
  .tbl-select { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 6px 8px; font-family: var(--font); font-size: 12px; }
  .tbl-input { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 6px 8px; font-family: var(--font); font-size: 12px; width: 110px; }
  .tbl-input::placeholder { color: var(--muted); }
  .tbl-input:focus, .tbl-select:focus { outline: none; border-color: var(--accent); }
  .btn-update { padding: 7px 12px; background: rgba(240,192,64,0.12); color: var(--accent); border: 1px solid rgba(240,192,64,0.25); cursor: pointer; font-family: var(--font); font-size: 11px; font-weight: 700; transition: all 0.15s; white-space: nowrap; }
  .btn-update:hover { background: rgba(240,192,64,0.22); }
  .btn-delete { padding: 7px 10px; background: rgba(239,68,68,0.08); color: var(--red); border: 1px solid rgba(239,68,68,0.2); cursor: pointer; font-family: var(--font); font-size: 11px; font-weight: 700; transition: all 0.15s; white-space: nowrap; }
  .btn-delete:hover { background: rgba(239,68,68,0.18); }
  .role-super { color: var(--red); font-size: 11px; font-weight: 700; }
  .role-sub { color: var(--muted); font-size: 11px; }

  /* CHANGE REQUESTS TABLE */
  .req-table { width: 100%; border-collapse: collapse; }
  .req-table thead tr { background: var(--surface2); }
  .req-table th { padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  .req-table td { padding: 10px 12px; border-bottom: 1px solid rgba(42,45,53,0.5); vertical-align: top; font-size: 13px; }
  .req-table tbody tr:hover { background: rgba(30,33,40,0.5); }

  .mod-badge { display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
  .mod-feed { background: rgba(240,192,64,0.1); color: var(--accent); border: 1px solid rgba(240,192,64,0.2); }
  .mod-haleeb { background: rgba(96,165,250,0.1); color: var(--blue); border: 1px solid rgba(96,165,250,0.2); }
  .mod-account { background: rgba(34,197,94,0.1); color: var(--green); border: 1px solid rgba(34,197,94,0.2); }
  .mod-other { background: rgba(192,132,252,0.1); color: var(--purple); border: 1px solid rgba(192,132,252,0.2); }

  .action-type { font-family: var(--mono); font-size: 11px; color: var(--muted); }
  .req-id { font-family: var(--mono); font-size: 12px; }
  .req-date { font-size: 10px; font-family: var(--mono); color: var(--muted); margin-top: 2px; }
  .req-by { font-size: 12px; font-weight: 600; }

  /* DIFF TABLE */
  .diff-list { width: 100%; border-collapse: collapse; }
  .diff-list td { padding: 3px 0; font-size: 11px; vertical-align: top; }
  .diff-label { color: var(--muted); font-weight: 600; font-family: var(--mono); width: 100px; white-space: nowrap; padding-right: 8px; }
  .diff-old { color: var(--red); font-family: var(--mono); text-decoration: line-through; opacity: 0.7; padding-right: 6px; }
  .diff-new { color: var(--green); font-family: var(--mono); }
  .diff-new-only { color: var(--text); font-family: var(--mono); }

  .payload-toggle { cursor: pointer; font-size: 10px; font-family: var(--mono); color: var(--muted); text-decoration: underline; margin-top: 6px; display: inline-block; }
  .payload-box { display: none; margin-top: 6px; background: var(--bg); border: 1px solid var(--border); padding: 8px; max-width: 320px; }
  .payload-box.show { display: block; }
  .payload-line { font-size: 10px; font-family: var(--mono); color: var(--muted); margin-bottom: 3px; word-break: break-all; }
  .payload-line strong { color: var(--text); }

  /* DECISION FORM */
  .decision-wrap { display: flex; flex-direction: column; gap: 6px; min-width: 180px; }
  .btn-editview {
    display: inline-block;
    text-align: center;
    padding: 8px 10px;
    background: rgba(96,165,250,0.12);
    color: var(--blue);
    border: 1px solid rgba(96,165,250,0.32);
    text-decoration: none;
    font-family: var(--font);
    font-size: 11px;
    font-weight: 700;
    transition: all 0.15s;
  }
  .btn-editview:hover { background: rgba(96,165,250,0.22); }
  .decision-note { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 7px 9px; font-family: var(--font); font-size: 12px; width: 100%; }
  .decision-note::placeholder { color: var(--muted); }
  .decision-note:focus { outline: none; border-color: var(--accent); }
  .decision-btns { display: flex; gap: 6px; }
  .btn-approve { padding: 8px 14px; background: rgba(34,197,94,0.12); color: var(--green); border: 1px solid rgba(34,197,94,0.3); cursor: pointer; font-family: var(--font); font-size: 12px; font-weight: 700; transition: all 0.15s; flex: 1; }
  .btn-approve:hover { background: rgba(34,197,94,0.22); }
  .btn-reject { padding: 8px 14px; background: rgba(239,68,68,0.08); color: var(--red); border: 1px solid rgba(239,68,68,0.2); cursor: pointer; font-family: var(--font); font-size: 12px; font-weight: 700; transition: all 0.15s; flex: 1; }
  .btn-reject:hover { background: rgba(239,68,68,0.18); }

  .empty-note { font-size: 12px; font-family: var(--mono); color: var(--muted); padding: 8px 0; }

  @media(max-width: 700px) {
    .topbar { padding: 14px 16px; }
    .main { padding: 16px; }
    .create-form { flex-direction: column; }
    .btn-create { width: 100%; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge-pill">Admin</span>
    <h1>Super Admin Panel</h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn" href="activity_review.php">Activity Review<?php echo $flaggedActivityCount > 0 ? ' (' . $flaggedActivityCount . ')' : ''; ?></a>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <a class="nav-btn" href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <?php if($msg !== ''): ?>
    <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>
  <?php if($err !== ''): ?>
    <div class="alert error"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <!-- CREATE USER -->
  <div class="section">
    <div class="section-head">
      <span class="section-title">Create New Admin</span>
    </div>
    <div class="section-body">
      <form method="post">
        <div class="create-form">
          <div class="cf-field">
            <span class="cf-label">Username</span>
            <input class="cf-input" type="text" name="username" placeholder="username" required>
          </div>
          <div class="cf-field">
            <span class="cf-label">Password</span>
            <input class="cf-input" type="password" name="password" placeholder="password" required>
          </div>
            <div class="cf-field">
              <span class="cf-label">Role</span>
              <select class="cf-select" name="role">
                <option value="sub_admin">Sub Admin</option>
                <option value="viewer">Viewer</option>
                <option value="super_admin">Super Admin</option>
              </select>
            </div>
            <div class="cf-field">
              <span class="cf-label">Feed Section</span>
              <div class="portion-group">
                <?php foreach($feedPortionOptions as $portionKey => $portionLabel): ?>
                  <label class="portion-label">
                    <input type="checkbox" name="feed_portion[]" value="<?php echo htmlspecialchars($portionKey); ?>">
                    <?php echo htmlspecialchars($portionLabel); ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <div class="cf-field">
            <span class="cf-label">Permissions</span>
            <div class="chk-group">
              <label class="chk-label"><input type="checkbox" name="is_active" checked> Active</label>
              <label class="chk-label"><input type="checkbox" name="can_access_feed"> Feed</label>
              <label class="chk-label"><input type="checkbox" name="can_access_haleeb"> Haleeb</label>
              <label class="chk-label"><input type="checkbox" name="can_access_account"> Account</label>
              <label class="chk-label"><input type="checkbox" name="can_access_image_processing"> Image Processing</label>
              <label class="chk-label"><input type="checkbox" name="can_review_activity"> Activity Review</label>
              <label class="chk-label"><input type="checkbox" name="can_manage_users"> Manage Users</label>
            </div>
          </div>
          <button class="btn-create" type="submit" name="create_user">Create User</button>
        </div>
      </form>
    </div>
  </div>

  <!-- USERS LIST -->
  <div class="section">
    <div class="section-head">
      <span class="section-title">Admins & Access</span>
      <span class="section-count"><?php echo count($users); ?> users</span>
    </div>
    <div style="overflow-x:auto;">
      <table class="users-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Active</th>
            <th>Feed</th>
            <th>Feed Section</th>
            <th>Haleeb</th>
            <th>Account</th>
            <th>Image Proc</th>
            <th>Act Review</th>
            <th>Users</th>
            <th>New Password</th>
            <th>Update</th>
            <th>Delete</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u):
            $isSelf = ((int)$u['id'] === $selfId);
          ?>
          <tr>
            <form method="post">
              <td>
                <div class="user-name">
                  <?php echo htmlspecialchars($u['username']); ?>
                  <?php if($isSelf): ?><span class="self-badge">You</span><?php endif; ?>
                </div>
                <div class="user-meta">ID: <?php echo (int)$u['id']; ?> · <?php echo htmlspecialchars(substr((string)$u['created_at'], 0, 10)); ?></div>
              </td>
              <td>
                <?php if($isSelf): ?>
                  <span class="<?php echo $u['role'] === 'super_admin' ? 'role-super' : 'role-sub'; ?>"><?php echo htmlspecialchars((string)$u['role']); ?></span>
                  <input type="hidden" name="role" value="<?php echo htmlspecialchars((string)$u['role']); ?>">
                <?php else: ?>
                  <select class="tbl-select" name="role">
                    <option value="sub_admin" <?php echo $u['role'] === 'sub_admin' ? 'selected' : ''; ?>>sub_admin</option>
                    <option value="viewer" <?php echo $u['role'] === 'viewer' ? 'selected' : ''; ?>>viewer</option>
                    <option value="super_admin" <?php echo $u['role'] === 'super_admin' ? 'selected' : ''; ?>>super_admin</option>
                  </select>
                <?php endif; ?>
              </td>
              <td><label class="chk-label"><input type="checkbox" name="is_active" <?php echo (int)$u['is_active'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk-label"><input type="checkbox" name="can_access_feed" <?php echo (int)$u['can_access_feed'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
                <td>
                  <?php if($isSelf): ?>
                    <span class="role-sub"><?php echo htmlspecialchars(feed_portion_labels_string_local($u['feed_portion'])); ?></span>
                    <input type="hidden" name="feed_portion" value="<?php echo htmlspecialchars(feed_portion_list_to_csv_local($u['feed_portion'])); ?>">
                  <?php else: ?>
                    <div class="portion-group">
                      <?php foreach($feedPortionOptions as $portionKey => $portionLabel): ?>
                        <label class="portion-label">
                          <input type="checkbox" name="feed_portion[]" value="<?php echo htmlspecialchars($portionKey); ?>" <?php echo feed_portion_list_has_key_local($u['feed_portion'], $portionKey) ? 'checked' : ''; ?>>
                          <?php echo htmlspecialchars($portionLabel); ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              <td><label class="chk-label"><input type="checkbox" name="can_access_haleeb" <?php echo (int)$u['can_access_haleeb'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk-label"><input type="checkbox" name="can_access_account" <?php echo (int)$u['can_access_account'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk-label"><input type="checkbox" name="can_access_image_processing" <?php echo (int)$u['can_access_image_processing'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk-label"><input type="checkbox" name="can_review_activity" <?php echo (int)$u['can_review_activity'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk-label"><input type="checkbox" name="can_manage_users" <?php echo (int)$u['can_manage_users'] ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><input class="tbl-input" type="password" name="new_password" placeholder="keep same"></td>
              <td>
                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                <button class="btn-update" type="submit" name="update_user">Update</button>
              </td>
              <td>
                <?php if(!$isSelf): ?>
                  <button class="btn-delete" type="submit" name="delete_user" onclick="return confirm('Delete user <?php echo htmlspecialchars($u['username']); ?>?')">Delete</button>
                <?php else: ?>
                  <span style="font-size:10px;color:var(--muted);">Protected</span>
                <?php endif; ?>
              </td>
            </form>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- CHANGE REQUESTS -->
  <div class="section">
    <div class="section-head">
      <span class="section-title">Pending Change Requests</span>
      <span class="section-count"><?php echo count($pendingRequests); ?> pending</span>
    </div>
    <div style="overflow-x:auto;">
      <?php if(count($pendingRequests) === 0): ?>
        <div class="section-body"><p class="empty-note">No pending requests.</p></div>
      <?php else: ?>
      <table class="req-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Requested By</th>
            <th>Module</th>
            <th>Action</th>
            <th>Changes</th>
            <th>Decision</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($pendingRequests as $r):
            $mod = strtolower((string)$r['module_key']);
            $modClass = str_contains($mod,'feed') ? 'mod-feed' : (str_contains($mod,'haleeb') ? 'mod-haleeb' : (str_contains($mod,'account') ? 'mod-account' : 'mod-other'));
            $changeLines = build_change_lines_local($conn, $r);
            $decodedPayload = decode_payload_local((string)$r['payload']);
            $actionType = isset($r['action_type']) ? (string)$r['action_type'] : '';
            $entityId = isset($r['entity_id']) ? (int)$r['entity_id'] : 0;
            $editHref = '';
            if($entityId > 0 && $actionType === 'feed_update'){
              $editHref = 'edit.php?id=' . $entityId . '&request_id=' . (int)$r['id'];
            } elseif($entityId > 0 && $actionType === 'haleeb_update'){
              $editHref = 'edit_haleeb_bilty.php?id=' . $entityId . '&request_id=' . (int)$r['id'];
            }
          ?>
          <tr>
            <td>
              <div class="req-id">#<?php echo (int)$r['id']; ?></div>
              <div class="req-date"><?php echo htmlspecialchars((string)$r['created_at']); ?></div>
            </td>
            <td>
              <div class="req-by"><?php echo htmlspecialchars($r['requested_by_name'] ?: ('User#' . (int)$r['requested_by'])); ?></div>
              <div style="font-size:10px;color:var(--muted);font-family:var(--mono);">Entity #<?php echo (int)$r['entity_id']; ?></div>
            </td>
            <td><span class="mod-badge <?php echo $modClass; ?>"><?php echo htmlspecialchars((string)$r['module_key']); ?></span></td>
            <td><span class="action-type"><?php echo htmlspecialchars((string)$r['action_type']); ?></span></td>
            <td>
              <table class="diff-list">
                <?php foreach($changeLines as $cl): ?>
                  <tr>
                    <td class="diff-label"><?php echo htmlspecialchars($cl['label']); ?></td>
                    <?php if($cl['old'] !== ''): ?>
                      <td class="diff-old"><?php echo htmlspecialchars(value_text_local($cl['old'])); ?></td>
                      <td style="color:var(--muted);padding:0 4px;font-size:10px;">→</td>
                      <td class="diff-new"><?php echo htmlspecialchars(value_text_local($cl['new'])); ?></td>
                    <?php else: ?>
                      <td class="diff-new-only" colspan="3"><?php echo htmlspecialchars(value_text_local($cl['new'])); ?></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </table>
              <?php if(count($decodedPayload) > 0): ?>
                <span class="payload-toggle" onclick="this.nextElementSibling.classList.toggle('show')">▸ Raw Payload</span>
                <div class="payload-box">
                  <?php foreach($decodedPayload as $pk => $pv): ?>
                    <div class="payload-line"><strong><?php echo htmlspecialchars((string)$pk); ?></strong>: <?php echo htmlspecialchars(is_array($pv) ? json_encode($pv, JSON_UNESCAPED_UNICODE) : (string)$pv); ?></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <form method="post">
                <div class="decision-wrap">
                  <?php if($editHref !== ''): ?>
                    <a class="btn-editview" href="<?php echo htmlspecialchars($editHref); ?>">Open Edit View</a>
                  <?php endif; ?>
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <input class="decision-note" type="text" name="review_note" placeholder="Note (optional)">
                  <div class="decision-btns">
                    <button class="btn-approve" type="submit" name="approve_request">✓ Approve</button>
                    <button class="btn-reject" type="submit" name="reject_request">✕ Reject</button>
                  </div>
                </div>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>

