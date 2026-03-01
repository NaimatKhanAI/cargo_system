<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';
auth_require_login($conn);
auth_require_module_access('feed');

$isSuperAdmin = auth_is_super_admin();
$userFeedPortion = auth_get_feed_portion();

function normalize_date_label_local($v){ $v = strtolower(trim((string)$v)); $v = str_replace(['.', '-', ' '], '/', $v); $v = preg_replace('#/+#', '/', $v); $parts = explode('/', $v); if(count($parts) === 3){ $m = ltrim($parts[0], '0'); $d = ltrim($parts[1], '0'); $y = trim($parts[2]); if($m === '') $m = '0'; if($d === '') $d = '0'; return $m . '/' . $d . '/' . $y; } return $v; }
function normalize_header_local($v){ $v = strtolower(trim((string)$v)); $v = preg_replace('/\s+/', ' ', $v); $v = str_replace(['.', '(', ')'], '', $v); return $v; }
function normalize_digits_local($v){ $v = (string)$v; $map = ['?'=>'0','?'=>'1','?'=>'2','?'=>'3','?'=>'4','?'=>'5','?'=>'6','?'=>'7','?'=>'8','?'=>'9','?'=>'0','?'=>'1','?'=>'2','?'=>'3','?'=>'4','?'=>'5','?'=>'6','?'=>'7','?'=>'8','?'=>'9']; return strtr($v, $map); }
function canonical_sr_local($v){ $v = normalize_digits_local((string)$v); $v = strtolower(trim($v)); return preg_replace('/[^a-z0-9]/u', '', $v); }
function parse_rate_to_number_local($v){ $v = trim((string)$v); if($v === '') return null; $clean = preg_replace('/[^0-9.\-]/', '', $v); if($clean === '' || $clean === '-' || $clean === '.') return null; if(!is_numeric($clean)) return null; return (float)$clean; }
function get_setting_value_local($conn, $key, $default = ''){ $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $stmt->bind_param("s", $key); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close(); return ($row && isset($row['setting_value'])) ? (string)$row['setting_value'] : (string)$default; }
function set_setting_value_local($conn, $key, $value){ $stmt = $conn->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"); $stmt->bind_param("ss", $key, $value); $ok = $stmt->execute(); $stmt->close(); return $ok; }
function load_active_rate_columns_local($conn){ $out = []; $res = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC"); while($res && $r = $res->fetch_assoc()){ if((int)$r['is_deleted'] === 1) continue; $out[] = ['key' => (string)$r['column_key'], 'label' => (string)$r['column_label']]; } return $out; }
function find_column_label_local($columns, $key){ foreach($columns as $c){ if((string)$c['key'] === (string)$key) return (string)$c['label']; } return ''; }
function get_feed_portion_column_key_local($conn, $portionKey, $activeColumns){
    $portionKey = normalize_feed_portion_local($portionKey);
    $settingKey = feed_portion_setting_key_local($portionKey);
    $targetKey = trim((string)get_setting_value_local($conn, $settingKey, ''));
    $allowed = array_column($activeColumns, 'key');

    if($targetKey !== '' && in_array($targetKey, $allowed, true)) return $targetKey;

    $legacyKey = trim((string)get_setting_value_local($conn, 'bilty_rate_value_column', ''));
    if($legacyKey !== '' && in_array($legacyKey, $allowed, true)) return $legacyKey;

    if(count($activeColumns) > 0) return (string)$activeColumns[0]['key'];
    return '';
}

$activeColumns = load_active_rate_columns_local($conn);
$feedPortionOptions = feed_portion_options_local();

if(isset($_POST['save_portion_columns']) && $_POST['save_portion_columns'] === '1'){
    header('Content-Type: application/json; charset=utf-8');

    if(!$isSuperAdmin){
        echo json_encode(['ok' => false, 'message' => 'Only super admin can update mappings.']);
        exit();
    }

    if(count($activeColumns) === 0){
        echo json_encode(['ok' => false, 'message' => 'No rate columns available.']);
        exit();
    }

    $allowed = array_column($activeColumns, 'key');
    $saved = [];

    foreach($feedPortionOptions as $portionKey => $portionLabel){
        $field = 'portion_' . $portionKey;
        $selected = isset($_POST[$field]) ? trim((string)$_POST[$field]) : '';
        if($selected === '' || !in_array($selected, $allowed, true)){
            echo json_encode(['ok' => false, 'message' => 'Invalid column selected for ' . $portionLabel . '.']);
            exit();
        }

        if(!set_setting_value_local($conn, feed_portion_setting_key_local($portionKey), $selected)){
            echo json_encode(['ok' => false, 'message' => 'Could not save setting for ' . $portionLabel . '.']);
            exit();
        }

        $saved[$portionKey] = [
            'column_key' => $selected,
            'column_label' => find_column_label_local($activeColumns, $selected),
        ];
    }

    echo json_encode(['ok' => true, 'columns' => $saved]);
    exit();
}

if(isset($_GET['lookup_tender']) && $_GET['lookup_tender'] === '1'){
    header('Content-Type: application/json; charset=utf-8');

    $sr = isset($_GET['sr_no']) ? trim((string)$_GET['sr_no']) : '';
    if($sr === ''){ echo json_encode(['ok' => false, 'message' => 'SR No is required']); exit(); }

    $lookupPortion = $isSuperAdmin ? normalize_feed_portion_local(isset($_GET['portion']) ? (string)$_GET['portion'] : '') : $userFeedPortion;

    $defaultTargetLabel = '1/1/2026';
    $targetNorm = normalize_date_label_local($defaultTargetLabel);
    $targetKey = get_feed_portion_column_key_local($conn, $lookupPortion, $activeColumns);
    $targetLabel = find_column_label_local($activeColumns, $targetKey);
    $selectedSrKey = '';
    $selectedSrLabel = '';

    $colRes = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
    while($colRes && $c = $colRes->fetch_assoc()){
        if((int)$c['is_deleted'] === 1) continue;
        $key = (string)$c['column_key'];
        $lbl = (string)$c['column_label'];
        if($selectedSrKey === '' && normalize_header_local($lbl) === 'sr'){ $selectedSrKey = $key; $selectedSrLabel = $lbl; }
        if($selectedSrKey === '' && normalize_header_local($lbl) === 'sr no'){ $selectedSrKey = $key; $selectedSrLabel = $lbl; }
        if($targetKey === '' && normalize_date_label_local($lbl) === $targetNorm){ $targetKey = $key; $targetLabel = $lbl; }
        if($targetLabel === '' && $key === $targetKey){ $targetLabel = $lbl; }
    }

    if($selectedSrKey === ''){ $selectedSrKey = 'sr_no'; $selectedSrLabel = 'SR No'; }
    if($targetKey === ''){ $targetKey = 'rate1'; }
    if($targetLabel === ''){ $targetLabel = $defaultTargetLabel; }

    $srCanon = canonical_sr_local($sr);
    $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC");
    $row = null;

    while($rowsRes && $r = $rowsRes->fetch_assoc()){
        $candidate = '';
        if($selectedSrKey === 'sr_no') $candidate = isset($r['sr_no']) ? (string)$r['sr_no'] : '';
        elseif(array_key_exists($selectedSrKey, $r)) $candidate = (string)$r[$selectedSrKey];
        $extra = json_decode((string)$r['extra_data'], true);
        if($candidate === '' && is_array($extra) && isset($extra[$selectedSrKey])) $candidate = (string)$extra[$selectedSrKey];
        if($srCanon !== '' && canonical_sr_local($candidate) === $srCanon){ $row = $r; break; }
    }

    if(!$row && $selectedSrKey !== 'sr_no'){
        $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC");
        while($rowsRes && $r = $rowsRes->fetch_assoc()){
            $candidate = isset($r['sr_no']) ? (string)$r['sr_no'] : '';
            if($srCanon !== '' && canonical_sr_local($candidate) === $srCanon){ $row = $r; $selectedSrLabel = 'SR No'; break; }
        }
    }

    if(!$row){ echo json_encode(['ok' => false, 'message' => 'SR not found in selected column']); exit(); }

    $rateValue = '';
    if(array_key_exists($targetKey, $row)) $rateValue = (string)$row[$targetKey];
    else {
        $extra = json_decode((string)$row['extra_data'], true);
        if(is_array($extra) && isset($extra[$targetKey])) $rateValue = (string)$extra[$targetKey];
    }

    $numericRate = parse_rate_to_number_local($rateValue);
    if($numericRate === null){ echo json_encode(['ok' => false, 'message' => 'Rate not found for this SR', 'rate_raw' => $rateValue]); exit(); }

    echo json_encode([
        'ok' => true,
        'feed_portion' => $lookupPortion,
        'feed_portion_label' => feed_portion_label_local($lookupPortion),
        'column_label' => $targetLabel,
        'column_key' => $targetKey,
        'sr_column_label' => $selectedSrLabel,
        'sr_column_key' => $selectedSrKey,
        'value_column_label' => $targetLabel,
        'value_column_key' => $targetKey,
        'sr_no' => $sr,
        'rate_raw' => $rateValue,
        'rate' => $numericRate
    ]);
    exit();
}

$selectedFeedPortion = $isSuperAdmin ? normalize_feed_portion_local(isset($_GET['portion']) ? (string)$_GET['portion'] : '') : $userFeedPortion;
$portionColumnMap = [];
foreach($feedPortionOptions as $portionKey => $portionLabel){
    $columnKey = get_feed_portion_column_key_local($conn, $portionKey, $activeColumns);
    $portionColumnMap[$portionKey] = [
        'column_key' => $columnKey,
        'column_label' => find_column_label_local($activeColumns, $columnKey),
    ];
}

$currentPortionColumn = isset($portionColumnMap[$selectedFeedPortion]) ? (string)$portionColumnMap[$selectedFeedPortion]['column_key'] : '';
$currentPortionColumnLabel = isset($portionColumnMap[$selectedFeedPortion]) ? (string)$portionColumnMap[$selectedFeedPortion]['column_label'] : '';
if($currentPortionColumnLabel === '' && count($activeColumns) > 0) $currentPortionColumnLabel = (string)$activeColumns[0]['label'];
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Bilty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --green: #22c55e; --red: #ef4444; --blue: #60a5fa;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 32px; border-bottom: 1px solid var(--border); background: var(--surface); }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .topbar-right { display: flex; align-items: center; gap: 8px; }
  .nav-btn { padding: 8px 16px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .settings-btn { width: 38px; height: 38px; background: var(--surface2); border: 1px solid var(--border); color: var(--muted); cursor: pointer; font-size: 17px; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
  .settings-btn:hover { border-color: var(--accent); color: var(--accent); }

  .main { display: flex; align-items: flex-start; justify-content: center; padding: 40px 24px; }
  .form-card { background: var(--surface); border: 1px solid var(--border); padding: 32px; width: min(860px, 100%); position: relative; overflow: hidden; }
  .form-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }

  .form-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 28px; }

  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  .field input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 11px 14px; font-family: var(--font); font-size: 14px; transition: border-color 0.15s;
  }
  .field select {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 11px 14px; font-family: var(--font); font-size: 14px; transition: border-color 0.15s;
  }
  .field input:focus { outline: none; border-color: var(--accent); }
  .field select:focus { outline: none; border-color: var(--accent); }
  .field input[readonly] { opacity: 0.85; }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .field input::placeholder { color: var(--muted); }
  .field-meta { margin-top: 5px; font-size: 11px; font-family: var(--mono); color: var(--muted); }
  .field-meta.ok { color: var(--green); }
  .field-meta.err { color: var(--red); }
  .field-meta.info { color: var(--blue); }

  .form-footer { margin-top: 28px; display: flex; justify-content: flex-end; }
  .submit-btn { padding: 13px 36px; background: var(--accent); color: #0e0f11; border: none; cursor: pointer; font-family: var(--font); font-size: 14px; font-weight: 800; letter-spacing: 0.3px; transition: background 0.15s; }
  .submit-btn:hover { background: #e0b030; }

  /* MODAL */
  .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 200; }
  .modal.show { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); padding: 28px; width: min(520px, 100%); position: relative; }
  .modal-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }
  .modal-title { font-size: 16px; font-weight: 800; margin-bottom: 6px; }
  .modal-desc { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
  .modal-field { margin-bottom: 10px; }
  .modal-field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  .modal-field select { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 11px 14px; font-family: var(--font); font-size: 13px; cursor: pointer; }
  .modal-field select:focus { outline: none; border-color: var(--accent); }
  .modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
  .btn-cancel { padding: 10px 18px; background: var(--surface2); color: var(--muted); border: 1px solid var(--border); cursor: pointer; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .btn-cancel:hover { color: var(--text); border-color: var(--muted); }
  .btn-save { padding: 10px 22px; background: var(--accent); color: #0e0f11; border: none; cursor: pointer; font-family: var(--font); font-size: 13px; font-weight: 800; transition: background 0.15s; }
  .btn-save:hover { background: #e0b030; }

  @media(max-width: 640px) {
    .main { padding: 20px 14px; }
    .form-card { padding: 22px 16px; }
    .grid { grid-template-columns: 1fr; }
    .topbar { padding: 14px 16px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Feed</span>
    <h1>Add Bilty</h1>
  </div>
  <div class="topbar-right">
    <?php if($isSuperAdmin): ?>
      <button class="settings-btn" id="open_portion_settings" type="button" title="Tender column mapping">&#9881;</button>
    <?php endif; ?>
    <a class="nav-btn" href="feed.php">Back</a>
  </div>
</div>

<div class="main">
  <div class="form-card">
    <div class="form-title">Add Bilty</div>
    <form action="save_bilty.php" method="post">
      <div class="grid">
        <?php if($isSuperAdmin): ?>
        <div class="field">
          <label for="feed_portion">Feed Section</label>
          <select id="feed_portion" name="feed_portion" required>
            <?php foreach($feedPortionOptions as $portionKey => $portionLabel): ?>
              <option value="<?php echo htmlspecialchars($portionKey); ?>" <?php echo $selectedFeedPortion === $portionKey ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($portionLabel); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field-meta">Bilty will be saved in selected section.</div>
        </div>
        <?php else: ?>
        <div class="field">
          <label for="feed_portion_display">Feed Section</label>
          <input id="feed_portion_display" type="text" value="<?php echo htmlspecialchars(feed_portion_label_local($selectedFeedPortion)); ?>" readonly>
          <input id="feed_portion" type="hidden" name="feed_portion" value="<?php echo htmlspecialchars($selectedFeedPortion); ?>">
        </div>
        <?php endif; ?>

        <div class="field">
          <label for="tender_column_label">Tender Column</label>
          <input id="tender_column_label" type="text" value="<?php echo htmlspecialchars($currentPortionColumnLabel !== '' ? $currentPortionColumnLabel : 'Not set'); ?>" readonly>
          <div class="field-meta" id="tender_help"></div>
        </div>

        <div class="field">
          <label for="sr_no">SR No</label>
          <input id="sr_no" name="sr_no" placeholder="SR number" required>
        </div>
        <div class="field">
          <label for="date">Date</label>
          <input id="date" type="date" name="date" value="<?php echo $today; ?>" required>
        </div>
        <div class="field">
          <label for="vehicle">Vehicle</label>
          <input id="vehicle" name="vehicle" placeholder="Vehicle number" required>
        </div>
        <div class="field">
          <label for="bilty">Bilty No</label>
          <input id="bilty" name="bilty" placeholder="Bilty number" required>
        </div>
        <div class="field">
          <label for="party">Party</label>
          <input id="party" name="party" placeholder="Party name">
        </div>
        <div class="field">
          <label for="location">Location</label>
          <input id="location" name="location" placeholder="Pickup / drop location" required>
        </div>
        <div class="field">
          <label for="bags">Bags</label>
          <input id="bags" type="number" name="bags" placeholder="0" min="0" value="0" required>
        </div>
        <div class="field">
          <label for="freight">Freight</label>
          <input id="freight" type="number" name="freight" placeholder="0" min="0" required>
        </div>
        <div class="field">
          <label for="tender">Tender</label>
          <input id="tender" type="number" name="tender" placeholder="0" min="0" required>
          <input id="tender_raw" type="hidden" name="tender_raw" value="">
          <div class="field-meta" id="tender_discount_note"></div>
        </div>
      </div>
      <div class="form-footer">
        <button class="submit-btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<?php if($isSuperAdmin): ?>
<div class="modal" id="portion_settings_modal">
  <div class="modal-card">
    <div class="modal-title">Tender Column Mapping</div>
    <div class="modal-desc">Assign which rate-list column each feed section should use for tender auto-fill.</div>

    <?php foreach($feedPortionOptions as $portionKey => $portionLabel): ?>
      <div class="modal-field">
        <label for="portion_<?php echo htmlspecialchars($portionKey); ?>"><?php echo htmlspecialchars($portionLabel); ?></label>
        <select id="portion_<?php echo htmlspecialchars($portionKey); ?>">
          <?php foreach($activeColumns as $c): ?>
            <option value="<?php echo htmlspecialchars($c['key']); ?>" <?php echo isset($portionColumnMap[$portionKey]) && $portionColumnMap[$portionKey]['column_key'] === $c['key'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['label']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endforeach; ?>

    <div class="modal-actions">
      <button class="btn-cancel" type="button" id="close_portion_settings">Close</button>
      <button class="btn-save" type="button" id="save_portion_settings">Save</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var srInput = document.getElementById('sr_no');
  var tenderInput = document.getElementById('tender');
  var tenderRawInput = document.getElementById('tender_raw');
  var bagsInput = document.getElementById('bags');
  var tenderDiscountNote = document.getElementById('tender_discount_note');
  var tenderHelp = document.getElementById('tender_help');
  var tenderColumnLabelInput = document.getElementById('tender_column_label');
  var portionSelect = document.getElementById('feed_portion');
  var form = document.querySelector('form[action="save_bilty.php"]');
  var reqId = 0, timer = null;
  var applyingTenderRule = false;

  var isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
  var portionLabels = <?php echo json_encode($feedPortionOptions, JSON_UNESCAPED_UNICODE); ?>;
  var portionColumnMap = <?php echo json_encode($portionColumnMap, JSON_UNESCAPED_UNICODE); ?>;
  var defaultPortion = <?php echo json_encode($selectedFeedPortion, JSON_UNESCAPED_UNICODE); ?>;

  function getSelectedPortion(){
    if(portionSelect && portionSelect.value){ return portionSelect.value; }
    return defaultPortion;
  }

  function getCurrentColumnLabel(){
    var portion = getSelectedPortion();
    var cfg = portionColumnMap && portionColumnMap[portion] ? portionColumnMap[portion] : null;
    return cfg && cfg.column_label ? cfg.column_label : 'Not set';
  }

  function setHelp(text, type){
    if(!tenderHelp) return;
    tenderHelp.textContent = text || '';
    tenderHelp.className = 'field-meta' + (type ? ' ' + type : '');
  }

  function refreshPortionTenderInfo(){
    var portion = getSelectedPortion();
    var portionName = portionLabels && portionLabels[portion] ? portionLabels[portion] : 'Selected section';
    var columnLabel = getCurrentColumnLabel();
    if(tenderColumnLabelInput) tenderColumnLabelInput.value = columnLabel;
    setHelp('Tender column for ' + portionName + ': ' + columnLabel, '');
  }

  function lookupTender(){
    var sr = (srInput && srInput.value ? srInput.value : '').trim();
    if(sr === ''){
      refreshPortionTenderInfo();
      return;
    }

    reqId++;
    var cur = reqId;
    setHelp('Checking rate...', 'info');

    var portion = getSelectedPortion();
    fetch('add_bilty.php?lookup_tender=1&portion=' + encodeURIComponent(portion) + '&sr_no=' + encodeURIComponent(sr), { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(cur !== reqId) return;
        if(data && data.ok){
          if(tenderRawInput) tenderRawInput.value = String(data.rate);
          applyTenderBagRule();
          setHelp('Auto fill: ' + (data.value_column_label || data.column_label || 'selected') + ' (' + (data.feed_portion_label || '') + ')', 'ok');
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

  if(portionSelect){
    portionSelect.addEventListener('change', function(){
      refreshPortionTenderInfo();
      lookupTender();
    });
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

    var finalTender = baseTender;
    if(bags > 300){
      finalTender = baseTender * 0.90;
      setDiscountNote('300+ bags: tender adjusted by -10%', 'ok');
    } else {
      setDiscountNote('', '');
    }

    applyingTenderRule = true;
    tenderInput.value = String(roundTender(finalTender));
    applyingTenderRule = false;
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

  if(isSuperAdmin){
    var modal = document.getElementById('portion_settings_modal');
    var openBtn = document.getElementById('open_portion_settings');
    var closeBtn = document.getElementById('close_portion_settings');
    var saveBtn = document.getElementById('save_portion_settings');

    if(openBtn && modal){
      openBtn.addEventListener('click', function(){ modal.classList.add('show'); });
    }
    if(closeBtn && modal){
      closeBtn.addEventListener('click', function(){ modal.classList.remove('show'); });
    }
    if(modal){
      modal.addEventListener('click', function(e){ if(e.target === modal) modal.classList.remove('show'); });
    }

    if(saveBtn){
      saveBtn.addEventListener('click', function(){
        var body = 'save_portion_columns=1';
        var valid = true;

        Object.keys(portionLabels || {}).forEach(function(key){
          var sel = document.getElementById('portion_' + key);
          var val = sel ? (sel.value || '') : '';
          if(val === '') valid = false;
          body += '&portion_' + encodeURIComponent(key) + '=' + encodeURIComponent(val);
        });

        if(!valid){
          setHelp('Please choose columns for all feed sections.', 'err');
          return;
        }

        fetch('add_bilty.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: body
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if(data && data.ok){
            if(data.columns) portionColumnMap = data.columns;
            refreshPortionTenderInfo();
            lookupTender();
            if(modal) modal.classList.remove('show');
          } else {
            setHelp((data && data.message) ? data.message : 'Could not save mapping.', 'err');
          }
        })
        .catch(function(){
          setHelp('Could not save mapping.', 'err');
        });
      });
    }
  }

  if(tenderRawInput && tenderInput && String(tenderInput.value || '').trim() !== ''){
    tenderRawInput.value = tenderInput.value;
  }
  refreshPortionTenderInfo();
  applyTenderBagRule();
})();
</script>
</body>
</html>
