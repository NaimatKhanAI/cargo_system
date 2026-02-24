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

function canonical_sr_local($v){
  $v = strtolower(trim((string)$v));
  return preg_replace('/[^a-z0-9]/', '', $v);
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

if(isset($_GET['lookup_tender']) && $_GET['lookup_tender'] === '1'){
  header('Content-Type: application/json; charset=utf-8');

  $sr = isset($_GET['sr_no']) ? trim((string)$_GET['sr_no']) : '';
  if($sr === ''){
    echo json_encode(['ok' => false, 'message' => 'SR No is required']);
    exit();
  }

  $targetLabel = '1/1/2026';
  $targetNorm = normalize_date_label_local($targetLabel);
  $targetKey = 'rate1';
  $srKeys = ['sr_no'];

  $colRes = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
  while($colRes && $c = $colRes->fetch_assoc()){
    if((int)$c['is_deleted'] === 1){
      continue;
    }
    $key = isset($c['column_key']) ? (string)$c['column_key'] : '';
    $lbl = isset($c['column_label']) ? (string)$c['column_label'] : '';
    $lblNorm = normalize_header_local($lbl);

    if(in_array($lblNorm, ['sr', 'sr no', 'serial', 'serial no', 'serial number'], true) && $key !== ''){
      if(!in_array($key, $srKeys, true)){
        $srKeys[] = $key;
      }
    }

    if(normalize_date_label_local($lbl) === $targetNorm){
      $targetKey = $key;
      break;
    }
  }

  $srCanon = canonical_sr_local($sr);
  $rowsRes = $conn->query("SELECT sr_no, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC");
  $row = null;
  while($rowsRes && $r = $rowsRes->fetch_assoc()){
    $candidates = [];
    $candidates[] = isset($r['sr_no']) ? (string)$r['sr_no'] : '';

    $extra = json_decode((string)$r['extra_data'], true);
    if(is_array($extra)){
      foreach($srKeys as $k){
        if(isset($extra[$k])){
          $candidates[] = (string)$extra[$k];
        }
      }
    }

    foreach($candidates as $cand){
      if($srCanon !== '' && canonical_sr_local($cand) === $srCanon){
        $row = $r;
        break 2;
      }
    }
  }

  if(!$row){
    echo json_encode(['ok' => false, 'message' => 'SR not found in rate list']);
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
      'message' => 'Rate not found in 1/1/2026 column for this SR',
      'rate_raw' => $rateValue
    ]);
    exit();
  }

  echo json_encode([
    'ok' => true,
    'column_label' => $targetLabel,
    'column_key' => $targetKey,
    'sr_no' => $sr,
    'rate_raw' => $rateValue,
    'rate' => $numericRate
  ]);
  exit();
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
.back-link{
display:inline-block;
padding:10px 14px;
background:#ececec;
color:#111;
text-decoration:none;
border-radius:8px;
font-weight:600;
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
.actions button{
max-width:180px;
border-radius:8px;
font-weight:700;
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
<a class="back-link" href="dashboard.php">Back to Dashboard</a>
</div>

<form action="save_bilty.php" method="post">
<div class="grid">
<div class="field">
<label for="sr_no">SR No</label>
<input id="sr_no" name="sr_no" placeholder="Enter SR no" required>
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
<script>
(function(){
  var srInput = document.getElementById('sr_no');
  var tenderInput = document.getElementById('tender');
  var help = document.getElementById('tender_help');
  if(!srInput || !tenderInput || !help){
    return;
  }

  var reqId = 0;
  var timer = null;

  function setHelp(text, color){
    help.textContent = text || '';
    help.style.color = color || '#6b7280';
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
        setHelp('Tender auto-filled from ' + data.column_label + ' column.', '#15803d');
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
})();
</script>
</body>
</html>
