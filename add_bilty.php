<?php
include 'config/db.php';

function normalize_date_label_local($v){
  $v = strtolower(trim((string)$v));
  $v = str_replace(['.', '-', ' '], '/', $v);
  $v = preg_replace('#/+#', '/', $v);
  $parts = explode('/', $v);
  if(count($parts) === 3){
    $m = ltrim($parts[0], '0');
    $d = ltrim($parts[1], '0');
    $y = trim($parts[2]);
    if($m === ''){ $m = '0'; }
    if($d === ''){ $d = '0'; }
    return $m . '/' . $d . '/' . $y;
  }
  return $v;
}

function normalize_header_local($v){
  $v = strtolower(trim((string)$v));
  $v = preg_replace('/\s+/', ' ', $v);
  $v = str_replace(['.', '(', ')'], '', $v);
  return $v;
}

function normalize_digits_local($v){
  $v = (string)$v;
  $map = [
    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
  ];
  return strtr($v, $map);
}

function canonical_sr_local($v){
  $v = normalize_digits_local((string)$v);
  $v = strtolower(trim($v));
  return preg_replace('/[^a-z0-9]/u', '', $v);
}

function parse_rate_to_number_local($v){
  $v = trim((string)$v);
  if($v === ''){
    return null;
  }
  $clean = preg_replace('/[^0-9.\-]/', '', $v);
  if($clean === '' || $clean === '-' || $clean === '.'){
    return null;
  }
  if(!is_numeric($clean)){
    return null;
  }
  return (float)$clean;
}

function get_setting_value_local($conn, $key, $default = ''){
  $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
  $stmt->bind_param("s", $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if($row && isset($row['setting_value'])){
    return (string)$row['setting_value'];
  }
  return (string)$default;
}

function set_setting_value_local($conn, $key, $value){
  $stmt = $conn->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
  $stmt->bind_param("ss", $key, $value);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function load_active_rate_columns_local($conn){
  $out = [];
  $res = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
  while($res && $r = $res->fetch_assoc()){
    if((int)$r['is_deleted'] === 1){
      continue;
    }
    $out[] = [
      'key' => (string)$r['column_key'],
      'label' => (string)$r['column_label'],
    ];
  }
  return $out;
}

if(isset($_POST['save_lookup_setting']) && $_POST['save_lookup_setting'] === '1'){
  header('Content-Type: application/json; charset=utf-8');
  $selected = isset($_POST['rate_value_column']) ? trim((string)$_POST['rate_value_column']) : '';
  $cols = load_active_rate_columns_local($conn);
  $allowed = [];
  foreach($cols as $c){
    $allowed[] = $c['key'];
  }
  if($selected === '' || !in_array($selected, $allowed, true)){
    echo json_encode(['ok' => false, 'message' => 'Invalid column selected']);
    exit();
  }
  if(!set_setting_value_local($conn, 'bilty_rate_value_column', $selected)){
    echo json_encode(['ok' => false, 'message' => 'Setting could not be saved']);
    exit();
  }
  echo json_encode(['ok' => true, 'selected' => $selected]);
  exit();
}

if(isset($_GET['lookup_tender']) && $_GET['lookup_tender'] === '1'){
  header('Content-Type: application/json; charset=utf-8');

  $sr = isset($_GET['sr_no']) ? trim((string)$_GET['sr_no']) : '';
  if($sr === ''){
    echo json_encode(['ok' => false, 'message' => 'SR No is required']);
    exit();
  }

  $defaultTargetLabel = '1/1/2026';
  $targetNorm = normalize_date_label_local($defaultTargetLabel);
  $targetKey = get_setting_value_local($conn, 'bilty_rate_value_column', '');
  $targetLabel = '';
  $selectedSrKey = '';
  $selectedSrLabel = '';

  $colRes = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
  while($colRes && $c = $colRes->fetch_assoc()){
    if((int)$c['is_deleted'] === 1){
      continue;
    }
    $key = isset($c['column_key']) ? (string)$c['column_key'] : '';
    $lbl = isset($c['column_label']) ? (string)$c['column_label'] : '';
    if($selectedSrKey === '' && normalize_header_local($lbl) === 'sr'){
      $selectedSrKey = $key;
      $selectedSrLabel = $lbl;
    }
    if($selectedSrKey === '' && normalize_header_local($lbl) === 'sr no'){
      $selectedSrKey = $key;
      $selectedSrLabel = $lbl;
    }
    if($targetKey !== '' && $key === $targetKey){
      $targetLabel = $lbl;
    }

    if($targetKey === '' && normalize_date_label_local($lbl) === $targetNorm){
      $targetKey = $key;
      $targetLabel = $lbl;
    }
  }

  if($selectedSrKey === ''){
    $selectedSrKey = 'sr_no';
    $selectedSrLabel = 'SR No';
  }
  if($targetKey === ''){
    $targetKey = 'rate1';
    $targetLabel = $defaultTargetLabel;
  }
  if($targetLabel === ''){
    $targetLabel = $defaultTargetLabel;
  }

  $srCanon = canonical_sr_local($sr);
  $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC");
  $row = null;
  while($rowsRes && $r = $rowsRes->fetch_assoc()){
    $candidate = '';
    if($selectedSrKey === 'sr_no'){
      $candidate = isset($r['sr_no']) ? (string)$r['sr_no'] : '';
    } elseif(array_key_exists($selectedSrKey, $r)){
      $candidate = (string)$r[$selectedSrKey];
    }
    $extra = json_decode((string)$r['extra_data'], true);
    if($candidate === '' && is_array($extra) && isset($extra[$selectedSrKey])){
      $candidate = (string)$extra[$selectedSrKey];
    }

    if($srCanon !== '' && canonical_sr_local($candidate) === $srCanon){
      $row = $r;
      break;
    }
  }

  if(!$row && $selectedSrKey !== 'sr_no'){
    $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC");
    while($rowsRes && $r = $rowsRes->fetch_assoc()){
      $candidate = isset($r['sr_no']) ? (string)$r['sr_no'] : '';
      if($srCanon !== '' && canonical_sr_local($candidate) === $srCanon){
        $row = $r;
        $selectedSrLabel = 'SR No';
        break;
      }
    }
  }

  if(!$row){
    echo json_encode(['ok' => false, 'message' => 'SR not found in selected column']);
    exit();
  }

  $rateValue = '';
  if(array_key_exists($targetKey, $row)){
    $rateValue = (string)$row[$targetKey];
  } else {
    $extra = json_decode((string)$row['extra_data'], true);
    if(is_array($extra) && isset($extra[$targetKey])){
      $rateValue = (string)$extra[$targetKey];
    }
  }

  $numericRate = parse_rate_to_number_local($rateValue);
  if($numericRate === null){
    echo json_encode([
      'ok' => false,
      'message' => 'Rate not found in selected value column for this SR',
      'rate_raw' => $rateValue
    ]);
    exit();
  }

  echo json_encode([
    'ok' => true,
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

$activeColumns = load_active_rate_columns_local($conn);
$savedValueLookupColumn = get_setting_value_local($conn, 'bilty_rate_value_column', '');
$savedValueLookupLabel = '';
foreach($activeColumns as $c){
  if($c['key'] === $savedValueLookupColumn){
    $savedValueLookupLabel = $c['label'];
    break;
  }
}
if($savedValueLookupColumn === '' && count($activeColumns) > 0){
  $savedValueLookupColumn = $activeColumns[0]['key'];
  $savedValueLookupLabel = $activeColumns[0]['label'];
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html>
<head>
<title>Add Bilty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.page-wrap{
max-width:900px;
margin:25px auto;
}
.form-card{
background:#fff;
border:1px solid #ddd;
border-radius:10px;
padding:20px;
box-shadow:0 10px 24px rgba(0,0,0,0.06);
}
.form-head{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:15px;
}
.head-right{
display:flex;
gap:8px;
align-items:center;
}
.back-link{
display:inline-block;
padding:10px 14px;
background:#ececec;
color:#111;
text-decoration:none;
border-radius:8px;
font-weight:600;
}
.settings-btn{
display:inline-flex;
align-items:center;
justify-content:center;
width:42px;
height:42px;
background:#111827;
color:#fff;
border:none;
border-radius:8px;
cursor:pointer;
font-size:18px;
}
.grid{
display:grid;
grid-template-columns:repeat(2, minmax(220px, 1fr));
gap:12px 16px;
}
.field label{
display:block;
margin:0 0 6px;
font-size:14px;
color:#333;
font-weight:600;
}
.field input{
max-width:none;
margin:0;
border:1px solid #cfcfcf;
border-radius:8px;
background:#fafafa;
}
.actions{
margin-top:16px;
display:flex;
justify-content:flex-end;
}
.help{
margin-top:8px;
font-size:12px;
color:#6b7280;
}
.sr-setting{
margin-top:6px;
font-size:12px;
color:#374151;
}
.actions button{
max-width:180px;
border-radius:8px;
font-weight:700;
}
.modal{
position:fixed;
inset:0;
background:rgba(0,0,0,0.45);
display:none;
align-items:center;
justify-content:center;
padding:16px;
}
.modal.show{
display:flex;
}
.modal-card{
width:min(520px, 100%);
background:#fff;
border-radius:10px;
border:1px solid #ddd;
padding:16px;
}
.modal-title{
font-size:18px;
font-weight:700;
margin:0 0 8px;
}
.modal-row{
margin-top:10px;
}
.modal-row label{
display:block;
font-size:13px;
font-weight:600;
margin-bottom:6px;
}
.modal-row select{
width:100%;
padding:10px;
border:1px solid #ccc;
border-radius:8px;
}
.modal-actions{
display:flex;
justify-content:flex-end;
gap:8px;
margin-top:12px;
}
.modal-actions button{
padding:9px 12px;
border-radius:8px;
border:none;
cursor:pointer;
}
.btn-cancel{
background:#e5e7eb;
color:#111;
}
.btn-save{
background:#111827;
color:#fff;
}
@media(max-width:700px){
.grid{
grid-template-columns:1fr;
}
.form-head{
align-items:flex-start;
flex-direction:column;
}
.actions{
justify-content:stretch;
}
.actions button{
max-width:none;
}
}
</style>
</head>
<body>
<div class="page-wrap">
<div class="form-card">
<div class="form-head">
<h2>Add Bilty</h2>
<div class="head-right">
<button type="button" class="settings-btn" id="open_settings" title="Tender Lookup Settings" aria-label="Tender Lookup Settings">&#9881;</button>
<a class="back-link" href="feed.php">Back to Feed</a>
</div>
</div>

<form action="save_bilty.php" method="post">
<div class="grid">
<div class="field">
<label for="sr_no">SR No</label>
<input id="sr_no" name="sr_no" placeholder="Enter SR no" required>
<div class="sr-setting">Value column: <span id="value_lookup_name"><?php echo htmlspecialchars($savedValueLookupLabel !== '' ? $savedValueLookupLabel : 'Not set'); ?></span></div>
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
<label for="freight">Freight</label>
<input id="freight" type="number" name="freight" placeholder="Freight amount" min="0" required>
</div>
<div class="field">
<label for="tender">Tender</label>
<input id="tender" type="number" name="tender" placeholder="Tender amount" min="0" required>
<div id="tender_help" class="help"></div>
</div>
</div>

<div class="actions">
<button type="submit">Save Bilty</button>
</div>
</form>
</div>
</div>
<div class="modal" id="settings_modal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-title">Tender Lookup Settings</div>
    <div class="muted">SR matching is fixed to SR. Select which column value should be used for Tender.</div>
    <form id="settings_form">
      <div class="modal-row">
        <label for="rate_value_column">Tender Value Column</label>
        <select id="rate_value_column" name="rate_value_column" required>
          <?php foreach($activeColumns as $c){ ?>
            <option value="<?php echo htmlspecialchars($c['key']); ?>" <?php echo $c['key'] === $savedValueLookupColumn ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['label'] . ' (' . $c['key'] . ')'); ?>
            </option>
          <?php } ?>
        </select>
      </div>
      <div class="modal-actions">
        <button class="btn-cancel" type="button" id="close_settings">Cancel</button>
        <button class="btn-save" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var srInput = document.getElementById('sr_no');
  var tenderInput = document.getElementById('tender');
  var help = document.getElementById('tender_help');
  var openBtn = document.getElementById('open_settings');
  var closeBtn = document.getElementById('close_settings');
  var modal = document.getElementById('settings_modal');
  var settingsForm = document.getElementById('settings_form');
  var valueLookupSelect = document.getElementById('rate_value_column');
  var valueLookupName = document.getElementById('value_lookup_name');
  if(!srInput || !tenderInput || !help){
    return;
  }

  var reqId = 0;
  var timer = null;

  function setHelp(text, color){
    help.textContent = text || '';
    help.style.color = color || '#6b7280';
  }

  function openModal(){
    if(!modal){ return; }
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal(){
    if(!modal){ return; }
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }

  function lookupTender(){
    var sr = (srInput.value || '').trim();
    if(sr === ''){
      setHelp('', '#6b7280');
      return;
    }

    reqId++;
    var currentReq = reqId;
    setHelp('Checking rate list...', '#2563eb');

    fetch('add_bilty.php?lookup_tender=1&sr_no=' + encodeURIComponent(sr), {
      headers: { 'Accept': 'application/json' }
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if(currentReq !== reqId){
        return;
      }
      if(data && data.ok){
        tenderInput.value = data.rate;
        setHelp('Tender auto-filled from ' + (data.value_column_label || data.column_label || 'selected') + ' column.', '#15803d');
      } else {
        setHelp((data && data.message) ? data.message : 'Rate not found.', '#b91c1c');
      }
    })
    .catch(function(){
      if(currentReq !== reqId){
        return;
      }
      setHelp('Unable to fetch rate right now.', '#b91c1c');
    });
  }

  function scheduleLookup(){
    if(timer){
      clearTimeout(timer);
    }
    timer = setTimeout(lookupTender, 250);
  }

  srInput.addEventListener('input', scheduleLookup);
  srInput.addEventListener('blur', lookupTender);

  if(openBtn){
    openBtn.addEventListener('click', openModal);
  }
  if(closeBtn){
    closeBtn.addEventListener('click', closeModal);
  }
  if(modal){
    modal.addEventListener('click', function(e){
      if(e.target === modal){
        closeModal();
      }
    });
  }
  if(settingsForm){
    settingsForm.addEventListener('submit', function(e){
      e.preventDefault();
      if(!valueLookupSelect){ return; }
      var col = valueLookupSelect.value || '';
      if(col === ''){ return; }
      setHelp('Saving lookup settings...', '#2563eb');

      fetch('add_bilty.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: 'save_lookup_setting=1&rate_value_column=' + encodeURIComponent(col)
      })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(data && data.ok){
          var label = '';
          if(valueLookupSelect.options[valueLookupSelect.selectedIndex]){
            label = valueLookupSelect.options[valueLookupSelect.selectedIndex].text;
          }
          if(valueLookupName){
            valueLookupName.textContent = label;
          }
          setHelp('Lookup settings saved.', '#15803d');
          closeModal();
          lookupTender();
        } else {
          setHelp((data && data.message) ? data.message : 'Could not save settings.', '#b91c1c');
        }
      })
      .catch(function(){
        setHelp('Could not save settings right now.', '#b91c1c');
      });
    });
  }
})();
</script>
</body>
</html>

