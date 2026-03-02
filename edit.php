<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('feed');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){ header("location:feed.php"); exit(); }
$isSuperAdmin = auth_is_super_admin();
$userFeedPortion = auth_get_feed_portion();
$linkedRequestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$linkedRequest = null;

if($isSuperAdmin){
    $rowStmt = $conn->prepare("SELECT * FROM bilty WHERE id=? LIMIT 1");
    $rowStmt->bind_param("i", $id);
} else {
    $rowStmt = $conn->prepare("SELECT * FROM bilty WHERE id=? AND feed_portion=? LIMIT 1");
    $rowStmt->bind_param("is", $id, $userFeedPortion);
}
$rowStmt->execute();
$row = $rowStmt->get_result()->fetch_assoc();
$rowStmt->close();
if(!$row){ header("location:feed.php"); exit(); }
$editFeedPortion = normalize_feed_portion_local(isset($row['feed_portion']) ? (string)$row['feed_portion'] : $userFeedPortion);
$editFeedPortionLabel = feed_portion_label_local($editFeedPortion);
if($linkedRequestId > 0 && auth_can_direct_modify()){
    $candidate = fetch_pending_change_request_by_id_local($conn, $linkedRequestId);
    if(
        $candidate &&
        isset($candidate['action_type']) && (string)$candidate['action_type'] === 'feed_update' &&
        isset($candidate['entity_id']) && (int)$candidate['entity_id'] === $id &&
        isset($candidate['entity_table']) && strcasecmp((string)$candidate['entity_table'], 'bilty') === 0
    ){
        $linkedRequest = $candidate;
        $prefill = request_payload_decode_local(isset($candidate['payload']) ? (string)$candidate['payload'] : '');
        if(count($prefill) > 0){
            $map = ['sr_no', 'date', 'vehicle', 'bilty_no', 'party', 'location', 'bags', 'freight', 'tender'];
            foreach($map as $key){
                if(array_key_exists($key, $prefill)){
                    $row[$key] = $prefill[$key];
                }
            }
        }
    }
}

if(isset($_POST['update'])){
    $sr = isset($_POST['sr_no']) ? trim($_POST['sr_no']) : '';
    $d = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $v = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
    $b = isset($_POST['bilty']) ? trim($_POST['bilty']) : '';
    $party = isset($_POST['party']) ? trim($_POST['party']) : '';
    $l = isset($_POST['location']) ? trim($_POST['location']) : '';
    $bags = isset($_POST['bags']) ? max(0, (int)$_POST['bags']) : 0;
    $f = isset($_POST['freight']) ? (int)$_POST['freight'] : 0;
    $submittedTender = isset($_POST['tender']) ? (float)$_POST['tender'] : 0.0;
    $baseTender = $submittedTender;
    if(isset($_POST['tender_raw']) && trim((string)$_POST['tender_raw']) !== ''){
        $baseTender = (float)$_POST['tender_raw'];
    }
    if($baseTender < 0){
        $baseTender = 0.0;
    }
    $baseBags = 200;
    $scaledTender = ($bags > 0) ? (($baseTender / $baseBags) * $bags) : 0.0;
    $t = ($bags > 300) ? (int)round($scaledTender * 0.90) : (int)round($scaledTender);
    if(!auth_can_direct_modify()){
        $payload = [
            'sr_no' => $sr,
            'date' => $d,
            'vehicle' => $v,
            'bilty_no' => $b,
            'party' => $party,
            'location' => $l,
            'bags' => $bags,
            'freight' => $f,
            'tender' => $t
        ];
        $requestId = create_change_request_local($conn, 'feed', 'bilty', $id, 'feed_update', $payload, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
        if($requestId > 0){
            header("location:feed.php?req=submitted");
            exit();
        }
    } else {
        $p = $t - $f;
        $oldData = [
            'sr_no' => isset($row['sr_no']) ? $row['sr_no'] : '',
            'date' => isset($row['date']) ? $row['date'] : '',
            'vehicle' => isset($row['vehicle']) ? $row['vehicle'] : '',
            'bilty_no' => isset($row['bilty_no']) ? $row['bilty_no'] : '',
            'party' => isset($row['party']) ? $row['party'] : '',
            'location' => isset($row['location']) ? $row['location'] : '',
            'bags' => isset($row['bags']) ? $row['bags'] : 0,
            'freight' => isset($row['freight']) ? $row['freight'] : 0,
            'tender' => isset($row['tender']) ? $row['tender'] : 0,
        ];
        $stmt = $conn->prepare("UPDATE bilty SET sr_no=?, date=?, vehicle=?, bilty_no=?, party=?, location=?, bags=?, freight=?, original_freight=?, tender=?, profit=? WHERE id=?");
        $stmt->bind_param("sssssssiiiii", $sr, $d, $v, $b, $party, $l, $bags, $f, $f, $t, $p, $id);
        $stmt->execute(); $stmt->close();
        activity_notify_local(
            $conn,
            'feed',
            'bilty_updated_direct',
            'bilty',
            $id,
            'Feed bilty updated directly.',
            [
                'old' => $oldData ?: [],
                'new' => [
                    'sr_no' => $sr,
                    'date' => $d,
                    'vehicle' => $v,
                    'bilty_no' => $b,
                    'party' => $party,
                    'location' => $l,
                    'bags' => $bags,
                    'freight' => $f,
                    'tender' => $t
                ]
            ],
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
        );
        if($linkedRequest){
            $closeError = '';
            mark_change_request_handled_local(
                $conn,
                (int)$linkedRequest['id'],
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
                'Approved after admin manual edit in full view.',
                $closeError,
                ['feed_update'],
                $id,
                'bilty'
            );
        }
        header("location:feed.php"); exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Bilty — #<?php echo htmlspecialchars($row['bilty_no']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
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
  .field-meta { margin-top: 5px; font-size: 11px; font-family: var(--mono); color: var(--muted); }
  .field-meta.ok { color: var(--green); }
  .field-meta.err { color: var(--red); }

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
    <div class="form-sub">Feed Section: <?php echo htmlspecialchars($editFeedPortionLabel); ?></div>
    <?php if($linkedRequest): ?>
      <div class="form-sub">Pending Request: #<?php echo (int)$linkedRequest['id']; ?> (values prefilled)</div>
    <?php endif; ?>

    <form method="post">
      <div class="grid">
        <div class="field">
          <label for="sr_no">SR No</label>
          <input id="sr_no" name="sr_no" value="<?php echo htmlspecialchars($row['sr_no'] ?? ''); ?>" required>
          <div class="field-meta" id="tender_help">Tender auto-lookup enabled.</div>
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
        <?php if($isSuperAdmin): ?>
          <div class="field">
            <label for="tender">Tender</label>
            <input id="tender" type="number" name="tender" value="<?php echo htmlspecialchars($row['tender']); ?>" min="0" required>
            <input id="tender_raw" type="hidden" name="tender_raw" value="">
            <div class="field-meta" id="tender_discount_note"></div>
          </div>
        <?php else: ?>
          <input id="tender" type="hidden" name="tender" value="<?php echo htmlspecialchars($row['tender']); ?>">
          <input id="tender_raw" type="hidden" name="tender_raw" value="">
        <?php endif; ?>
      </div>

      <div class="form-footer">
        <a class="delete-btn" href="delete.php?id=<?php echo $id; ?>" onclick="return confirm('Delete this bilty?')">&#128465; Delete</a>
        <button class="update-btn" type="submit" name="update">Save</button>
      </div>
      <input id="edit_feed_portion" type="hidden" value="<?php echo htmlspecialchars($editFeedPortion); ?>">
    </form>
  </div>
</div>
<script>
(function(){
  var srInput = document.getElementById('sr_no');
  var tenderInput = document.getElementById('tender');
  var tenderRawInput = document.getElementById('tender_raw');
  var bagsInput = document.getElementById('bags');
  var tenderHelp = document.getElementById('tender_help');
  var tenderDiscountNote = document.getElementById('tender_discount_note');
  var feedPortionInput = document.getElementById('edit_feed_portion');
  var form = document.querySelector('form');
  var timer = null;
  var reqId = 0;
  var applyingTenderRule = false;

  function setHelp(text, type){
    if(!tenderHelp) return;
    tenderHelp.textContent = text || '';
    tenderHelp.className = 'field-meta' + (type ? ' ' + type : '');
  }

  function roundTender(v){
    return Math.round(v);
  }

  function parseNumeric(v){
    if(v === null || typeof v === 'undefined') return null;
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function setDiscountNote(text, cls){
    if(!tenderDiscountNote) return;
    tenderDiscountNote.textContent = text || '';
    tenderDiscountNote.className = 'field-meta' + (cls ? ' ' + cls : '');
  }

  function applyTenderBagRule(){
    if(!tenderInput || !tenderRawInput) return;
    var baseTender = parseNumeric(tenderRawInput.value);
    if(baseTender === null){
      setDiscountNote('', '');
      return;
    }

    var bags = bagsInput ? parseInt(bagsInput.value, 10) : 0;
    if(Number.isNaN(bags)) bags = 0;

    var baseBags = 200;
    var finalTender = (bags > 0) ? ((baseTender / baseBags) * bags) : 0;
    if(bags > 300){
      finalTender = finalTender * 0.90;
      setDiscountNote('Bags > 300: tender adjusted by -10%', 'ok');
    } else {
      setDiscountNote('', '');
    }

    applyingTenderRule = true;
    tenderInput.value = String(roundTender(finalTender));
    applyingTenderRule = false;
  }

  function lookupTender(){
    var sr = (srInput && srInput.value ? srInput.value : '').trim();
    if(sr === ''){
      setHelp('Enter SR No to auto fetch tender.', '');
      return;
    }

    reqId++;
    var cur = reqId;
    setHelp('Checking rate...', '');
    var portion = feedPortionInput ? (feedPortionInput.value || '') : '';

    fetch('add_bilty.php?lookup_tender=1&portion=' + encodeURIComponent(portion) + '&sr_no=' + encodeURIComponent(sr), { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(cur !== reqId) return;
        if(data && data.ok){
          if(tenderRawInput) tenderRawInput.value = String(data.rate);
          applyTenderBagRule();
          setHelp('Auto fill: ' + (data.value_column_label || data.column_label || 'selected'), 'ok');
        } else {
          setHelp((data && data.message) ? data.message : 'Rate not found', 'err');
        }
      })
      .catch(function(){
        if(cur !== reqId) return;
        setHelp('Cannot get rate.', 'err');
      });
  }

  if(srInput){
    srInput.addEventListener('input', function(){
      if(timer) clearTimeout(timer);
      timer = setTimeout(lookupTender, 250);
    });
    srInput.addEventListener('blur', lookupTender);
  }

  if(tenderInput){
    tenderInput.addEventListener('input', function(){
      if(applyingTenderRule) return;
      if(tenderRawInput) tenderRawInput.value = tenderInput.value;
      applyTenderBagRule();
    });
  }

  if(bagsInput){
    bagsInput.addEventListener('input', applyTenderBagRule);
    bagsInput.addEventListener('change', applyTenderBagRule);
  }

  if(form){
    form.addEventListener('submit', function(){
      if(tenderRawInput && String(tenderRawInput.value || '').trim() === '' && tenderInput){
        tenderRawInput.value = tenderInput.value;
      }
      applyTenderBagRule();
    });
  }

  if(tenderRawInput && tenderInput && String(tenderInput.value || '').trim() !== ''){
    tenderRawInput.value = tenderInput.value;
  }
  if(srInput && String(srInput.value || '').trim() !== ''){
    lookupTender();
  } else {
    setHelp('Enter SR No to auto fetch tender.', '');
  }
})();
</script>
</body>
</html>

