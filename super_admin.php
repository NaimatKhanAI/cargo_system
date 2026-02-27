<?php
session_start();
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';

auth_require_login($conn);
auth_require_super_admin('dashboard.php');

$msg = '';
$err = '';

function bool_post_local($key){
    return isset($_POST[$key]) ? 1 : 0;
}

function decode_payload_local($raw){
    if(trim((string)$raw) === '') return [];
    $decoded = json_decode((string)$raw, true);
    if(!is_array($decoded)) return [];
    return $decoded;
}

function value_text_local($v){
    if($v === null) return '(empty)';
    $t = trim((string)$v);
    return $t === '' ? '(empty)' : $t;
}

function build_change_lines_local($conn, $requestRow){
    $actionType = isset($requestRow['action_type']) ? (string)$requestRow['action_type'] : '';
    $entityId = isset($requestRow['entity_id']) ? (int)$requestRow['entity_id'] : 0;
    $payload = decode_payload_local(isset($requestRow['payload']) ? (string)$requestRow['payload'] : '');
    $lines = [];

    if($actionType === 'feed_update'){
        $row = null;
        if($entityId > 0){
            $stmt = $conn->prepare("SELECT sr_no, date, vehicle, bilty_no, party, location, bags, freight, tender FROM bilty WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $entityId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $map = ['sr_no'=>'SR No','date'=>'Date','vehicle'=>'Vehicle','bilty_no'=>'Bilty No','party'=>'Party','location'=>'Location','bags'=>'Bags','freight'=>'Freight','tender'=>'Tender'];
        foreach($map as $k => $label){
            if(!array_key_exists($k, $payload)) continue;
            $old = $row && isset($row[$k]) ? $row[$k] : '';
            $new = $payload[$k];
            if((string)$old !== (string)$new){
                $lines[] = $label . ': ' . value_text_local($old) . ' -> ' . value_text_local($new);
            }
        }
    } elseif($actionType === 'haleeb_update'){
        $row = null;
        if($entityId > 0){
            $stmt = $conn->prepare("SELECT date, vehicle, vehicle_type, delivery_note, token_no, party, location, stops, freight, tender FROM haleeb_bilty WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $entityId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $map = ['date'=>'Date','vehicle'=>'Vehicle','vehicle_type'=>'Vehicle Type','delivery_note'=>'Delivery Note','token_no'=>'Token No','party'=>'Party','location'=>'Location','stops'=>'Stops','freight'=>'Freight','tender'=>'Tender'];
        foreach($map as $k => $label){
            if(!array_key_exists($k, $payload)) continue;
            $old = $row && isset($row[$k]) ? $row[$k] : '';
            $new = $payload[$k];
            if((string)$old !== (string)$new){
                $lines[] = $label . ': ' . value_text_local($old) . ' -> ' . value_text_local($new);
            }
        }
    } elseif($actionType === 'account_update'){
        $row = null;
        if($entityId > 0){
            $stmt = $conn->prepare("SELECT entry_date, category, entry_type, amount_mode, amount, note FROM account_entries WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $entityId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $map = ['entry_date'=>'Date','category'=>'Category','entry_type'=>'Type','amount_mode'=>'Mode','amount'=>'Amount','note'=>'Note'];
        foreach($map as $k => $label){
            if(!array_key_exists($k, $payload)) continue;
            $old = $row && isset($row[$k]) ? $row[$k] : '';
            $new = $payload[$k];
            if((string)$old !== (string)$new){
                $lines[] = $label . ': ' . value_text_local($old) . ' -> ' . value_text_local($new);
            }
        }
    } elseif($actionType === 'feed_delete'){
        $stmt = $conn->prepare("SELECT bilty_no, vehicle, party, location FROM bilty WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row){
            $lines[] = 'Delete Feed Bilty No: ' . value_text_local($row['bilty_no']);
            $lines[] = 'Vehicle: ' . value_text_local($row['vehicle']) . ', Party: ' . value_text_local($row['party']) . ', Location: ' . value_text_local($row['location']);
        } else {
            $lines[] = 'Delete Feed Bilty ID: ' . $entityId;
        }
    } elseif($actionType === 'haleeb_delete'){
        $stmt = $conn->prepare("SELECT token_no, vehicle, party, location FROM haleeb_bilty WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row){
            $lines[] = 'Delete Haleeb Token: ' . value_text_local($row['token_no']);
            $lines[] = 'Vehicle: ' . value_text_local($row['vehicle']) . ', Party: ' . value_text_local($row['party']) . ', Location: ' . value_text_local($row['location']);
        } else {
            $lines[] = 'Delete Haleeb Bilty ID: ' . $entityId;
        }
    } elseif($actionType === 'account_delete'){
        $stmt = $conn->prepare("SELECT entry_date, category, entry_type, amount FROM account_entries WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if($row){
            $lines[] = 'Delete Entry: ' . value_text_local($row['entry_date']) . ' | ' . value_text_local($row['category']) . ' | ' . value_text_local($row['entry_type']) . ' | Rs ' . value_text_local($row['amount']);
        } else {
            $lines[] = 'Delete Account Entry ID: ' . $entityId;
        }
    } elseif($actionType === 'feed_pay' || $actionType === 'haleeb_pay'){
        $lines[] = 'Payment Date: ' . value_text_local($payload['entry_date'] ?? '');
        $lines[] = 'Mode: ' . value_text_local($payload['amount_mode'] ?? '') . ', Category: ' . value_text_local($payload['category'] ?? '');
        $lines[] = 'Amount: Rs ' . value_text_local($payload['amount'] ?? '');
        $lines[] = 'Note: ' . value_text_local($payload['note'] ?? '');
    }

    if(count($lines) === 0){
        $lines[] = 'No specific change diff available.';
    }
    return $lines;
}

if(isset($_POST['create_user'])){
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
    $role = isset($_POST['role']) ? trim((string)$_POST['role']) : 'sub_admin';
    if($username === '' || $password === ''){
        $err = 'Username and password required.';
    } elseif(!in_array($role, ['super_admin', 'sub_admin'], true)){
        $err = 'Invalid role.';
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $chk->bind_param("s", $username);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if($exists){
            $err = 'Username already exists.';
        } else {
            $accessFeed = bool_post_local('can_access_feed');
            $accessHaleeb = bool_post_local('can_access_haleeb');
            $accessAccount = bool_post_local('can_access_account');
            $isActive = bool_post_local('is_active');
            if($role === 'super_admin'){
                $accessFeed = 1; $accessHaleeb = 1; $accessAccount = 1;
                $canManageUsers = 1;
            } else {
                $canManageUsers = 0;
            }
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $ins = $conn->prepare("INSERT INTO users(username, password, role, is_active, can_access_feed, can_access_haleeb, can_access_account, can_manage_users, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("sssiiiiii", $username, $password, $role, $isActive, $accessFeed, $accessHaleeb, $accessAccount, $canManageUsers, $createdBy);
            $ins->execute();
            $ins->close();
            $msg = 'User created.';
        }
    }
}

if(isset($_POST['update_user'])){
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $selfId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = isset($_POST['role']) ? trim((string)$_POST['role']) : 'sub_admin';
    $isActive = bool_post_local('is_active');
    $accessFeed = bool_post_local('can_access_feed');
    $accessHaleeb = bool_post_local('can_access_haleeb');
    $accessAccount = bool_post_local('can_access_account');
    $password = isset($_POST['new_password']) ? trim((string)$_POST['new_password']) : '';

    if($userId <= 0){
        $err = 'Invalid user.';
    } elseif(!in_array($role, ['super_admin', 'sub_admin'], true)){
        $err = 'Invalid role.';
    } else {
        if($userId === $selfId){
            // Super admin cannot reduce or alter own access.
            $role = 'super_admin';
            $isActive = 1;
            $accessFeed = 1;
            $accessHaleeb = 1;
            $accessAccount = 1;
            $canManageUsers = 1;
        } elseif($role === 'super_admin'){
            $accessFeed = 1; $accessHaleeb = 1; $accessAccount = 1;
            $canManageUsers = 1;
        } else {
            $canManageUsers = 0;
        }

        if($role !== 'super_admin'){
            $superCountRes = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='super_admin'");
            $superCount = $superCountRes ? (int)$superCountRes->fetch_assoc()['c'] : 0;
            $selfIsSuperStmt = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
            $selfIsSuperStmt->bind_param("i", $userId);
            $selfIsSuperStmt->execute();
            $selfRoleRow = $selfIsSuperStmt->get_result()->fetch_assoc();
            $selfIsSuperStmt->close();
            if($selfRoleRow && isset($selfRoleRow['role']) && $selfRoleRow['role'] === 'super_admin' && $superCount <= 1){
                $err = 'Last super admin cannot be downgraded.';
            }
        }

        if($err === ''){
            $upd = $conn->prepare("UPDATE users SET role=?, is_active=?, can_access_feed=?, can_access_haleeb=?, can_access_account=?, can_manage_users=? WHERE id=?");
            $upd->bind_param("siiiiii", $role, $isActive, $accessFeed, $accessHaleeb, $accessAccount, $canManageUsers, $userId);
            $upd->execute();
            $upd->close();

            if($password !== ''){
                $pwd = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                $pwd->bind_param("si", $password, $userId);
                $pwd->execute();
                $pwd->close();
            }
            $msg = $userId === $selfId ? 'Password updated. Own access remains fixed as super admin.' : 'User updated.';
        }
    }
}

if(isset($_POST['delete_user'])){
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $selfId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if($userId <= 0){
        $err = 'Invalid user.';
    } elseif($userId === $selfId){
        $err = 'You cannot delete your own account.';
    } else {
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
        $roleStmt->bind_param("i", $userId);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        if($roleRow && $roleRow['role'] === 'super_admin'){
            $superCountRes = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='super_admin'");
            $superCount = $superCountRes ? (int)$superCountRes->fetch_assoc()['c'] : 0;
            if($superCount <= 1){
                $err = 'Last super admin cannot be deleted.';
            }
        }
        if($err === ''){
            $del = $conn->prepare("DELETE FROM users WHERE id=?");
            $del->bind_param("i", $userId);
            $del->execute();
            $del->close();
            $msg = 'User deleted.';
        }
    }
}

if(isset($_POST['approve_request']) || isset($_POST['reject_request'])){
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $reviewNote = isset($_POST['review_note']) ? trim((string)$_POST['review_note']) : '';
    if($requestId <= 0){
        $err = 'Invalid request.';
    } else {
        $reqStmt = $conn->prepare("SELECT * FROM change_requests WHERE id=? AND status='pending' LIMIT 1");
        $reqStmt->bind_param("i", $requestId);
        $reqStmt->execute();
        $requestRow = $reqStmt->get_result()->fetch_assoc();
        $reqStmt->close();
        if(!$requestRow){
            $err = 'Request not found or already reviewed.';
        } else {
            $reviewedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            if(isset($_POST['approve_request'])){
                $conn->begin_transaction();
                try{
                    $applyError = '';
                    $okApply = apply_change_request_local($conn, $requestRow, $applyError);
                    if(!$okApply){
                        throw new Exception($applyError !== '' ? $applyError : 'Could not apply request.');
                    }
                    $status = 'approved';
                    $updReq = $conn->prepare("UPDATE change_requests SET status=?, reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE id=?");
                    $updReq->bind_param("sisi", $status, $reviewedBy, $reviewNote, $requestId);
                    $updReq->execute();
                    $updReq->close();
                    $conn->commit();
                    $msg = 'Request approved and applied.';
                } catch (Throwable $e){
                    $conn->rollback();
                    $err = 'Approve failed: ' . $e->getMessage();
                }
            } else {
                $status = 'rejected';
                $updReq = $conn->prepare("UPDATE change_requests SET status=?, reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE id=?");
                $updReq->bind_param("sisi", $status, $reviewedBy, $reviewNote, $requestId);
                $updReq->execute();
                $updReq->close();
                $msg = 'Request rejected.';
            }
        }
    }
}

$users = [];
$usersRes = $conn->query("SELECT id, username, role, is_active, can_access_feed, can_access_haleeb, can_access_account, created_at FROM users ORDER BY id ASC");
while($usersRes && $u = $usersRes->fetch_assoc()) $users[] = $u;

$pendingRequests = fetch_pending_change_requests_local($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Super Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --bg:#0e0f11; --surface:#16181c; --surface2:#1e2128; --border:#2a2d35; --accent:#f0c040; --green:#22c55e; --red:#ef4444; --text:#e8eaf0; --muted:#7c8091; --font:'Syne',sans-serif; }
  *{ box-sizing:border-box; margin:0; padding:0; }
  body{ background:var(--bg); color:var(--text); font-family:var(--font); min-height:100vh; }
  .topbar{ display:flex; justify-content:space-between; align-items:center; padding:16px 24px; border-bottom:1px solid var(--border); background:var(--surface); }
  .nav-btn{ padding:8px 12px; border:1px solid var(--border); color:var(--muted); text-decoration:none; background:transparent; font-size:13px; cursor:pointer; }
  .nav-btn:hover{ color:var(--text); border-color:var(--muted); background:var(--surface2); }
  .main{ max-width:1300px; margin:0 auto; padding:22px; }
  .alert{ padding:10px 12px; margin-bottom:12px; font-size:13px; border-left:3px solid var(--green); background:rgba(34,197,94,0.08); color:var(--green); }
  .alert.error{ border-color:var(--red); background:rgba(239,68,68,0.08); color:var(--red); }
  .panel{ background:var(--surface); border:1px solid var(--border); margin-bottom:14px; padding:14px; }
  .panel h2{ font-size:13px; letter-spacing:1px; text-transform:uppercase; color:var(--muted); margin-bottom:12px; }
  .row{ display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  input[type=text], input[type=password], select, textarea { background:var(--bg); border:1px solid var(--border); color:var(--text); padding:8px 10px; font-size:13px; font-family:var(--font); }
  textarea{ min-width:240px; min-height:34px; }
  label.chk{ display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--muted); }
  table{ width:100%; border-collapse:collapse; }
  th,td{ padding:8px; border-bottom:1px solid rgba(42,45,53,0.7); font-size:12px; text-align:left; vertical-align:top; }
  th{ color:var(--muted); text-transform:uppercase; font-size:10px; letter-spacing:1px; }
  .mini{ font-size:11px; color:var(--muted); }
  .badge{ display:inline-block; padding:2px 6px; border:1px solid var(--border); font-size:10px; text-transform:uppercase; letter-spacing:0.8px; }
  .pending{ color:var(--accent); border-color:rgba(240,192,64,0.25); }
  .approve{ color:var(--green); border-color:rgba(34,197,94,0.25); }
  .reject{ color:var(--red); border-color:rgba(239,68,68,0.25); }
  details.payload{ cursor:pointer; }
  .payload-box{ margin-top:6px; border:1px solid var(--border); background:var(--bg); padding:6px; max-width:360px; }
  .payload-line{ font-size:11px; color:var(--muted); margin-bottom:4px; word-break:break-word; }
  .payload-line strong{ color:var(--text); }
  .change-list{ margin:0; padding-left:16px; }
  .change-list li{ margin-bottom:4px; color:var(--text); font-size:11px; }
</style>
</head>
<body>
<div class="topbar">
  <strong>Super Admin Panel</strong>
  <div>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <a class="nav-btn" href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <?php if($msg !== ''): ?><div class="alert"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err !== ''): ?><div class="alert error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <div class="panel">
    <h2>Create Admin</h2>
    <form method="post">
      <div class="row">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <select name="role">
          <option value="sub_admin">Sub Admin</option>
          <option value="super_admin">Super Admin</option>
        </select>
        <label class="chk"><input type="checkbox" name="is_active" checked> Active</label>
        <label class="chk"><input type="checkbox" name="can_access_feed"> Feed</label>
        <label class="chk"><input type="checkbox" name="can_access_haleeb"> Haleeb</label>
        <label class="chk"><input type="checkbox" name="can_access_account"> Account</label>
        <button class="nav-btn" type="submit" name="create_user">Create</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2>Admins & Access</h2>
    <table>
      <thead>
        <tr><th>User</th><th>Role</th><th>Active</th><th>Feed</th><th>Haleeb</th><th>Account</th><th>Password</th><th>Save</th><th>Delete</th></tr>
      </thead>
      <tbody>
        <?php foreach($users as $u): ?>
          <?php $isSelf = ((int)$u['id'] === (int)($_SESSION['user_id'] ?? 0)); ?>
          <tr>
            <form method="post">
              <td>
                <?php echo htmlspecialchars($u['username']); ?>
                <div class="mini">ID: <?php echo (int)$u['id']; ?> | <?php echo htmlspecialchars((string)$u['created_at']); ?><?php echo $isSelf ? ' | Self' : ''; ?></div>
              </td>
              <td>
                <select name="role" <?php echo $isSelf ? 'disabled' : ''; ?>>
                  <option value="sub_admin" <?php echo $u['role'] === 'sub_admin' ? 'selected' : ''; ?>>sub_admin</option>
                  <option value="super_admin" <?php echo $u['role'] === 'super_admin' ? 'selected' : ''; ?>>super_admin</option>
                </select>
              </td>
              <td><label class="chk"><input type="checkbox" name="is_active" <?php echo (int)$u['is_active'] === 1 ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk"><input type="checkbox" name="can_access_feed" <?php echo (int)$u['can_access_feed'] === 1 ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk"><input type="checkbox" name="can_access_haleeb" <?php echo (int)$u['can_access_haleeb'] === 1 ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><label class="chk"><input type="checkbox" name="can_access_account" <?php echo (int)$u['can_access_account'] === 1 ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>></label></td>
              <td><input type="password" name="new_password" placeholder="keep same"></td>
              <td>
                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                <button class="nav-btn" type="submit" name="update_user">Update</button>
              </td>
              <td>
                <?php if(!$isSelf): ?>
                  <button class="nav-btn" type="submit" name="delete_user" onclick="return confirm('Delete user?')">Delete</button>
                <?php else: ?>
                  <span class="mini">Not allowed</span>
                <?php endif; ?>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h2>Pending Change Requests (<?php echo count($pendingRequests); ?>)</h2>
    <table>
      <thead>
        <tr><th>ID</th><th>By</th><th>Module</th><th>Action</th><th>Entity</th><th>Requested Change</th><th>Decision</th></tr>
      </thead>
      <tbody>
      <?php if(count($pendingRequests) === 0): ?>
        <tr><td colspan="7" class="mini">No pending requests.</td></tr>
      <?php else: ?>
        <?php foreach($pendingRequests as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?><div class="mini"><?php echo htmlspecialchars((string)$r['created_at']); ?></div></td>
            <td><?php echo htmlspecialchars($r['requested_by_name'] ?: ('User#' . (int)$r['requested_by'])); ?></td>
            <td><span class="badge pending"><?php echo htmlspecialchars((string)$r['module_key']); ?></span></td>
            <td><?php echo htmlspecialchars((string)$r['action_type']); ?></td>
            <td><?php echo htmlspecialchars((string)$r['entity_table']); ?> #<?php echo (int)$r['entity_id']; ?></td>
            <td>
              <?php $changeLines = build_change_lines_local($conn, $r); ?>
              <ul class="change-list">
                <?php foreach($changeLines as $line): ?>
                  <li><?php echo htmlspecialchars((string)$line); ?></li>
                <?php endforeach; ?>
              </ul>
              <?php $decodedPayload = decode_payload_local((string)$r['payload']); ?>
              <details class="payload">
                <summary>Raw Payload</summary>
                <div class="payload-box">
                  <?php if(count($decodedPayload) === 0): ?>
                    <div class="payload-line">No payload details.</div>
                  <?php else: ?>
                    <?php foreach($decodedPayload as $pk => $pv): ?>
                      <div class="payload-line">
                        <strong><?php echo htmlspecialchars((string)$pk); ?></strong>:
                        <?php
                          if(is_array($pv)){
                            echo htmlspecialchars(json_encode($pv, JSON_UNESCAPED_UNICODE));
                          } else {
                            echo htmlspecialchars((string)$pv);
                          }
                        ?>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </details>
            </td>
            <td>
              <form method="post">
                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                <input type="text" name="review_note" placeholder="Note (optional)">
                <div class="row" style="margin-top:6px;">
                  <button class="nav-btn approve" type="submit" name="approve_request">Approve</button>
                  <button class="nav-btn reject" type="submit" name="reject_request">Reject</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
