<?php
include 'config/db.php';

function normalize_date_label_local($v){ $v = strtolower(trim((string)$v)); $v = str_replace(['.', '-', ' '], '/', $v); $v = preg_replace('#/+#', '/', $v); $parts = explode('/', $v); if(count($parts) === 3){ $m = ltrim($parts[0], '0'); $d = ltrim($parts[1], '0'); $y = trim($parts[2]); if($m === '') $m = '0'; if($d === '') $d = '0'; return $m . '/' . $d . '/' . $y; } return $v; }
function normalize_header_local($v){ $v = strtolower(trim((string)$v)); $v = preg_replace('/\s+/', ' ', $v); $v = str_replace(['.', '(', ')'], '', $v); return $v; }
function normalize_digits_local($v){ $v = (string)$v; $map = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']; return strtr($v, $map); }
function canonical_sr_local($v){ $v = normalize_digits_local((string)$v); $v = strtolower(trim($v)); return preg_replace('/[^a-z0-9]/u', '', $v); }
function parse_rate_to_number_local($v){ $v = trim((string)$v); if($v === '') return null; $clean = preg_replace('/[^0-9.\-]/', '', $v); if($clean === '' || $clean === '-' || $clean === '.') return null; if(!is_numeric($clean)) return null; return (float)$clean; }
function get_setting_value_local($conn, $key, $default = ''){ $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $stmt->bind_param("s", $key); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close(); return ($row && isset($row['setting_value'])) ? (string)$row['setting_value'] : (string)$default; }
function set_setting_value_local($conn, $key, $value){ $stmt = $conn->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"); $stmt->bind_param("ss", $key, $value); $ok = $stmt->execute(); $stmt->close(); return $ok; }
function load_active_rate_columns_local($conn){ $out = []; $res = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC"); while($res && $r = $res->fetch_assoc()){ if((int)$r['is_deleted'] === 1) continue; $out[] = ['key' => (string)$r['column_key'], 'label' => (string)$r['column_label']]; } return $out; }

if(isset($_POST['save_lookup_setting']) && $_POST['save_lookup_setting'] === '1'){
    header('Content-Type: application/json; charset=utf-8');
    $selected = isset($_POST['rate_value_column']) ? trim((string)$_POST['rate_value_column']) : '';
    $cols = load_active_rate_columns_local($conn); $allowed = array_column($cols, 'key');
    if($selected === '' || !in_array($selected, $allowed, true)){ echo json_encode(['ok' => false, 'message' => 'Invalid column selected']); exit(); }
    if(!set_setting_value_local($conn, 'bilty_rate_value_column', $selected)){ echo json_encode(['ok' => false, 'message' => 'Setting could not be saved']); exit(); }
    echo json_encode(['ok' => true, 'selected' => $selected]); exit();
}

if(isset($_GET['lookup_tender']) && $_GET['lookup_tender'] === '1'){
    header('Content-Type: application/json; charset=utf-8');
    $sr = isset($_GET['sr_no']) ? trim((string)$_GET['sr_no']) : '';
    if($sr === ''){ echo json_encode(['ok' => false, 'message' => 'SR No is required']); exit(); }
    $defaultTargetLabel = '1/1/2026'; $targetNorm = normalize_date_label_local($defaultTargetLabel);
    $targetKey = get_setting_value_local($conn, 'bilty_rate_value_column', ''); $targetLabel = ''; $selectedSrKey = ''; $selectedSrLabel = '';
    $colRes = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
    while($colRes && $c = $colRes->fetch_assoc()){ if((int)$c['is_deleted'] === 1) continue; $key = (string)$c['column_key']; $lbl = (string)$c['column_label']; if($selectedSrKey === '' && normalize_header_local($lbl) === 'sr'){ $selectedSrKey = $key; $selectedSrLabel = $lbl; } if($selectedSrKey === '' && normalize_header_local($lbl) === 'sr no'){ $selectedSrKey = $key; $selectedSrLabel = $lbl; } if($targetKey !== '' && $key === $targetKey){ $targetLabel = $lbl; } if($targetKey === '' && normalize_date_label_local($lbl) === $targetNorm){ $targetKey = $key; $targetLabel = $lbl; } }
    if($selectedSrKey === ''){ $selectedSrKey = 'sr_no'; $selectedSrLabel = 'SR No'; } if($targetKey === ''){ $targetKey = 'rate1'; $targetLabel = $defaultTargetLabel; } if($targetLabel === '') $targetLabel = $defaultTargetLabel;
    $srCanon = canonical_sr_local($sr); $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC"); $row = null;
    while($rowsRes && $r = $rowsRes->fetch_assoc()){ $candidate = ''; if($selectedSrKey === 'sr_no') $candidate = isset($r['sr_no']) ? (string)$r['sr_no'] : ''; elseif(array_key_exists($selectedSrKey, $r)) $candidate = (string)$r[$selectedSrKey]; $extra = json_decode((string)$r['extra_data'], true); if($candidate === '' && is_array($extra) && isset($extra[$selectedSrKey])) $candidate = (string)$extra[$selectedSrKey]; if($srCanon !== '' && canonical_sr_local($candidate) === $srCanon){ $row = $r; break; } }
    if(!$row && $selectedSrKey !== 'sr_no'){ $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC"); while($rowsRes && $r = $rowsRes->fetch_assoc()){ $candidate = isset($r['sr_no']) ? (string)$r['sr_no'] : ''; if($srCanon !== '' && canonical_sr_local($candidate) === $srCanon){ $row = $r; $selectedSrLabel = 'SR No'; break; } } }
    if(!$row){ echo json_encode(['ok' => false, 'message' => 'SR not found in selected column']); exit(); }
    $rateValue = ''; if(array_key_exists($targetKey, $row)) $rateValue = (string)$row[$targetKey]; else { $extra = json_decode((string)$row['extra_data'], true); if(is_array($extra) && isset($extra[$targetKey])) $rateValue = (string)$extra[$targetKey]; }
    $numericRate = parse_rate_to_number_local($rateValue);
    if($numericRate === null){ echo json_encode(['ok' => false, 'message' => 'Rate not found for this SR', 'rate_raw' => $rateValue]); exit(); }
    echo json_encode(['ok' => true, 'column_label' => $targetLabel, 'column_key' => $targetKey, 'sr_column_label' => $selectedSrLabel, 'sr_column_key' => $selectedSrKey, 'value_column_label' => $targetLabel, 'value_column_key' => $targetKey, 'sr_no' => $sr, 'rate_raw' => $rateValue, 'rate' => $numericRate]); exit();
}

$activeColumns = load_active_rate_columns_local($conn);
$savedValueLookupColumn = get_setting_value_local($conn, 'bilty_rate_value_column', '');
$savedValueLookupLabel = '';
foreach($activeColumns as $c){ if($c['key'] === $savedValueLookupColumn){ $savedValueLookupLabel = $c['label']; break; } }
if($savedValueLookupColumn === '' && count($activeColumns) > 0){ $savedValueLookupColumn = $activeColumns[0]['key']; $savedValueLookupLabel = $activeColumns[0]['label']; }
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Bilty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
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
  .modal-card { background: var(--surface); border: 1px solid var(--border); padding: 28px; width: min(480px, 100%); position: relative; }
  .modal-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }
  .modal-title { font-size: 16px; font-weight: 800; margin-bottom: 6px; }
  .modal-desc { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
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
    <a class="nav-btn" href="feed.php">Back</a>
  </div>
</div>

<div class="main">
  <div class="form-card">
    <div class="form-title">Add Bilty</div>
    <form action="save_bilty.php" method="post">
      <div class="grid">
        <div class="field">
          <label for="rate_value_column">Tender Column</label>
          <select id="rate_value_column" name="rate_value_column" required>
            <?php foreach($activeColumns as $c): ?>
              <option value="<?php echo htmlspecialchars($c['key']); ?>" <?php echo $c['key'] === $savedValueLookupColumn ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['label'] . ' (' . $c['key'] . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="sr_no">SR No</label>
          <input id="sr_no" name="sr_no" placeholder="SR number" required>
          <div class="field-meta" id="tender_help">Tender column: <span id="value_lookup_name"><?php echo htmlspecialchars($savedValueLookupLabel !== '' ? $savedValueLookupLabel : 'Not set'); ?></span></div>
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
        </div>
      </div>
      <div class="form-footer">
        <button class="submit-btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var srInput = document.getElementById('sr_no');
  var tenderInput = document.getElementById('tender');
  var valueLookupSelect = document.getElementById('rate_value_column');
  var valueLookupName = document.getElementById('value_lookup_name');
  var reqId = 0, timer = null;

  function setHelp(text, type){
    var el = document.getElementById('tender_help');
    if(!el) return;
    el.textContent = text || '';
    el.className = 'field-meta' + (type ? ' ' + type : '');
  }

  function lookupTender(){
    var sr = (srInput && srInput.value ? srInput.value : '').trim();
    if(sr === ''){
      setHelp('Tender column: ' + (valueLookupName ? valueLookupName.textContent : ''), '');
      return;
    }

    reqId++;
    var cur = reqId;
    setHelp('Checking rate...', 'info');

    fetch('add_bilty.php?lookup_tender=1&sr_no=' + encodeURIComponent(sr), { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(cur !== reqId) return;
        if(data && data.ok){
          if(tenderInput) tenderInput.value = data.rate;
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

  if(valueLookupSelect){
    valueLookupSelect.addEventListener('change', function(){
      var col = valueLookupSelect.value || '';
      if(col === '') return;

      fetch('add_bilty.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: 'save_lookup_setting=1&rate_value_column=' + encodeURIComponent(col)
      })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(data && data.ok){
          var label = valueLookupSelect.options[valueLookupSelect.selectedIndex] ? valueLookupSelect.options[valueLookupSelect.selectedIndex].text : '';
          if(valueLookupName) valueLookupName.textContent = label;
          lookupTender();
        } else {
          setHelp((data && data.message) ? data.message : 'Could not save.', 'err');
        }
      })
      .catch(function(){
        setHelp('Could not save.', 'err');
      });
    });
  }
})();
</script>
</body>
</html>

