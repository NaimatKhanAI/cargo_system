<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
$canHaleebAccess = auth_has_module_access('haleeb');
$canLedgerAccess = auth_can_direct_modify('account');
if(!$canHaleebAccess && !$canLedgerAccess){
    header("location:dashboard.php?denied=" . urlencode('haleeb'));
    exit();
}
if(auth_is_viewer()){
    header("location:haleeb.php?denied=view_only");
    exit();
}
$isSuperAdmin = auth_is_super_admin();
$canDirectHaleebPay = auth_can_direct_modify('haleeb') || $canLedgerAccess;
$source = isset($_GET['src']) ? strtolower(trim((string)$_GET['src'])) : '';
$fallbackReturnUrl = $source === 'all_bilties' ? 'all_bilties.php' : 'haleeb.php';
$returnUrl = $fallbackReturnUrl;
$backRaw = isset($_GET['back']) ? trim((string)$_GET['back']) : '';
if($backRaw !== ''){
    $hasProtocol = preg_match('/^[a-z][a-z0-9+.-]*:/i', $backRaw) === 1;
    $hasCrlf = strpos($backRaw, "\r") !== false || strpos($backRaw, "\n") !== false;
    if(!$hasProtocol && !$hasCrlf && strpos($backRaw, '//') !== 0){
        $returnUrl = $backRaw;
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){
    $sep = strpos($returnUrl, '?') === false ? '?' : '&';
    header("location:" . $returnUrl . $sep . "pay=error");
    exit();
}

$allowedCategories = ['feed', 'haleeb', 'loan'];
$msg = ''; $err = '';
$today = date('Y-m-d');
$formDate = $today; $formCategory = 'haleeb'; $formAmountMode = 'account'; $formAmount = ''; $formNote = '';

$stmt = $conn->prepare("SELECT * FROM haleeb_bilty WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$row){
    $sep = strpos($returnUrl, '?') === false ? '?' : '&';
    header("location:" . $returnUrl . $sep . "pay=error");
    exit();
}

$paidStmt = $conn->prepare("SELECT SUM(amount) AS paid_total FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit'");
$paidStmt->bind_param("i", $id); $paidStmt->execute();
$paidRes = $paidStmt->get_result()->fetch_assoc(); $paidStmt->close();
$paidTotal = $paidRes && $paidRes['paid_total'] ? (float)$paidRes['paid_total'] : 0;
$commission = isset($row['commission']) ? (float)$row['commission'] : 0;
$baseFreight = max((float)$row['freight'] - $commission, 0);
$remainingFreight = max(0, $baseFreight - $paidTotal);

if(isset($_POST['pay_now'])){
    $formDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : $today;
    $formCategory = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : 'haleeb';
    $formAmountMode = isset($_POST['amount_mode']) ? strtolower(trim($_POST['amount_mode'])) : 'account';
    $payAmount = isset($_POST['pay_amount']) ? round((float)$_POST['pay_amount'], 3) : 0.0;
    $formAmount = $payAmount > 0 ? (string)$payAmount : '';
    $formNote = isset($_POST['note']) ? trim($_POST['note']) : '';

    if(!in_array($formCategory, $allowedCategories, true)) $err = 'Invalid category selected.';
    elseif(!in_array($formAmountMode, ['cash', 'account'], true)) $err = 'Invalid payment mode.';
    elseif($payAmount <= 0) $err = 'Pay amount must be greater than 0.';
    elseif($payAmount > $remainingFreight) $err = 'Pay amount cannot exceed remaining freight.';
    else {
        $baseNote = "Haleeb - Tok(" . $row['token_no'] . ") - ";
        $note = $formNote !== '' ? ($baseNote . " - " . $formNote) : $baseNote;
        if(!$canDirectHaleebPay){
            $payload = [
                'entry_date' => $formDate,
                'category' => $formCategory,
                'amount_mode' => $formAmountMode,
                'amount' => $payAmount,
                'note' => $note
            ];
            $requestId = create_change_request_local($conn, 'haleeb', 'haleeb_bilty', $id, 'haleeb_pay', $payload, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
            if($requestId > 0){
                $sep = strpos($returnUrl, '?') === false ? '?' : '&';
                header("location:" . $returnUrl . $sep . "pay=requested");
                exit();
            }
            $err = 'Payment request could not be sent.';
        } else {
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'debit', ?, NULL, ?, ?, ?)");
                $ins->bind_param("sssids", $formDate, $formCategory, $formAmountMode, $id, $payAmount, $note);
                $ins->execute();
                $entryId = (int)$ins->insert_id;
                $ins->close();
                activity_notify_local(
                    $conn,
                    'haleeb',
                    'payment_added_direct',
                    'account_entry',
                    $entryId,
                    'Haleeb payment posted directly.',
                    [
                        'haleeb_bilty_id' => $id,
                        'amount' => $payAmount,
                        'mode' => $formAmountMode,
                        'category' => $formCategory
                    ],
                    isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
                );
                $conn->commit();
                $sep = strpos($returnUrl, '?') === false ? '?' : '&';
                header("location:" . $returnUrl . $sep . "pay=success"); exit();
            } catch (Throwable $e) { $conn->rollback(); $err = 'Payment failed. Please try again.'; }
        }
    }
}

$paidPct = $baseFreight > 0 ? min(100, round($paidTotal / $baseFreight * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pay Now — Haleeb Token <?php echo htmlspecialchars($row['token_no']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #60a5fa; --green: #22c55e; --red: #ef4444; --yellow: #f0c040;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 32px; border-bottom: 1px solid var(--border); background: var(--surface); }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--green); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-btn { padding: 8px 16px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }

  .main { display: flex; justify-content: center; padding: 36px 24px; }
  .layout { display: grid; grid-template-columns: 1fr 380px; gap: 20px; width: min(900px, 100%); }

  .info-panel { display: flex; flex-direction: column; gap: 14px; }
  .panel-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 12px; }

  .info-card { background: var(--surface); border: 1px solid var(--border); padding: 20px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .info-label { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 4px; }
  .info-val { font-family: var(--mono); font-size: 14px; font-weight: 500; }
  .info-val.green { color: var(--green); }
  .info-val.yellow { color: var(--yellow); }
  .info-val.blue { color: var(--accent); }

  .type-badge { display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; background: rgba(96,165,250,0.12); color: var(--accent); border: 1px solid rgba(96,165,250,0.2); }

  .progress-card { background: var(--surface); border: 1px solid var(--border); padding: 20px; }
  .progress-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
  .progress-label { font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); }
  .progress-pct { font-family: var(--mono); font-size: 22px; font-weight: 500; color: var(--accent); }
  .progress-track { height: 6px; background: var(--surface2); border: 1px solid var(--border); overflow: hidden; margin-bottom: 10px; }
  .progress-fill { height: 100%; background: var(--accent); transition: width 0.6s ease; }
  .progress-detail { display: flex; justify-content: space-between; font-size: 12px; font-family: var(--mono); color: var(--muted); }

  .form-card { background: var(--surface); border: 1px solid var(--border); padding: 24px; position: relative; overflow: hidden; align-self: start; }
  .form-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--green); }
  .form-title { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; margin-bottom: 20px; }

  .alert { padding: 11px 14px; margin-bottom: 16px; font-size: 13px; border-left: 3px solid var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

  .field { margin-bottom: 14px; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .field input, .field select { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 10px 12px; font-family: var(--font); font-size: 13px; transition: border-color 0.15s; appearance: none; }
  .field input:focus, .field select:focus { outline: none; border-color: var(--green); }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .field input::placeholder { color: var(--muted); }
  .field-hint { font-size: 11px; font-family: var(--mono); color: var(--muted); margin-top: 4px; }

  .submit-btn { width: 100%; padding: 13px; background: var(--green); color: #0e0f11; border: none; cursor: pointer; font-family: var(--font); font-size: 14px; font-weight: 800; transition: background 0.15s; margin-top: 4px; }
  .submit-btn:hover { background: #16a34a; }

  @media(max-width: 720px) {
    .layout { grid-template-columns: 1fr; }
    .main { padding: 20px 14px; }
    .topbar { padding: 14px 16px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Pay</span>
    <h1>Pay Now — Token <?php echo htmlspecialchars($row['token_no']); ?></h1>
  </div>
  <a class="nav-btn" href="<?php echo htmlspecialchars($returnUrl); ?>">Back</a>
</div>

<div class="main">
  <div class="layout">
    <!-- LEFT INFO -->
    <div class="info-panel">
      <div class="info-card">
        <div class="panel-title">Details</div>
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Vehicle</div>
            <div class="info-val"><?php echo htmlspecialchars($row['vehicle']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Vehicle Type</div>
            <div class="info-val"><span class="type-badge"><?php echo htmlspecialchars($row['vehicle_type']); ?></span></div>
          </div>
          <div class="info-item">
            <div class="info-label">Party</div>
            <div class="info-val"><?php echo htmlspecialchars($row['party']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Location</div>
            <div class="info-val"><?php echo htmlspecialchars($row['location']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Delivery Note</div>
            <div class="info-val blue"><?php echo htmlspecialchars($row['delivery_note']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Token No</div>
            <div class="info-val blue"><?php echo htmlspecialchars($row['token_no']); ?></div>
          </div>
          <?php if($isSuperAdmin): ?>
            <div class="info-item">
              <div class="info-label">Tender</div>
              <div class="info-val yellow">Rs <?php echo format_amount_local((float)$row['tender'], 1); ?></div>
            </div>
          <?php endif; ?>
          <div class="info-item">
            <div class="info-label">Freight Total</div>
            <div class="info-val">Rs <?php echo format_amount_local((float)$row['freight'], 1); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Commission</div>
            <div class="info-val">Rs <?php echo format_amount_local($commission, 1); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Total Cost</div>
            <div class="info-val">Rs <?php echo format_amount_local($baseFreight, 1); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Paid</div>
            <div class="info-val green">Rs <?php echo format_amount_local($paidTotal, 1); ?></div>
          </div>
        </div>
      </div>

      <div class="progress-card">
        <div class="progress-header">
          <span class="progress-label">Progress</span>
          <span class="progress-pct"><?php echo $paidPct; ?>%</span>
        </div>
        <div class="progress-track">
          <div class="progress-fill" style="width:<?php echo $paidPct; ?>%"></div>
        </div>
        <div class="progress-detail">
          <span>Paid: Rs <?php echo format_amount_local($paidTotal, 1); ?></span>
          <span>Remaining: Rs <?php echo format_amount_local($remainingFreight, 1); ?></span>
        </div>
      </div>
    </div>

    <!-- RIGHT FORM -->
    <div class="form-card">
      <div class="form-title">Add Payment</div>

      <?php if($err !== ""): ?>
        <div class="alert"><?php echo htmlspecialchars($err); ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="field">
          <label for="entry_date">Date</label>
          <input id="entry_date" type="date" name="entry_date" value="<?php echo htmlspecialchars($formDate); ?>" required>
        </div>
        <div class="field">
          <label for="category">Category</label>
          <select id="category" name="category" required>
            <option value="feed" <?php echo $formCategory==='feed'?'selected':''; ?>>Feed</option>
            <option value="haleeb" <?php echo $formCategory==='haleeb'?'selected':''; ?>>Haleeb</option>
            <option value="loan" <?php echo $formCategory==='loan'?'selected':''; ?>>Loan</option>
          </select>
        </div>
        <div class="field">
          <label for="amount_mode">Mode</label>
          <select id="amount_mode" name="amount_mode" required>
            <option value="cash" <?php echo $formAmountMode==='cash'?'selected':''; ?>>Cash</option>
            <option value="account" <?php echo $formAmountMode==='account'?'selected':''; ?>>Account</option>
          </select>
        </div>
        <div class="field">
          <label for="pay_amount">Amount</label>
          <input id="pay_amount" type="number" name="pay_amount" min="0.001" step="any" max="<?php echo htmlspecialchars((string)$remainingFreight); ?>" value="<?php echo htmlspecialchars($formAmount); ?>" placeholder="0" required>
          <div class="field-hint">Max: Rs <?php echo format_amount_local($remainingFreight, 1); ?></div>
        </div>
        <div class="field">
          <label for="note">Note</label>
          <input id="note" type="text" name="note" value="<?php echo htmlspecialchars($formNote); ?>" placeholder="Payment note...">
        </div>
        <button class="submit-btn" type="submit" name="pay_now">Save Payment</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>

