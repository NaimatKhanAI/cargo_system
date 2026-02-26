<?php
include 'config/db.php';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){ header("location:haleeb.php"); exit(); }

function normalize_lookup_token($v){
    $v = strtolower(trim((string)$v));
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

if(isset($_POST['update'])){
    $d  = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $v  = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
    $vt = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : '';
    $dn = isset($_POST['delivery_note']) ? trim($_POST['delivery_note']) : '';
    $tn = isset($_POST['token_no']) ? trim($_POST['token_no']) : '';
    $party = isset($_POST['party']) ? trim($_POST['party']) : '';
    $l  = isset($_POST['location']) ? trim($_POST['location']) : '';
    $stops = isset($_POST['stops']) ? trim($_POST['stops']) : '';
    $t  = isset($_POST['tender']) ? (int)$_POST['tender'] : 0;
    $f  = isset($_POST['freight']) ? (int)$_POST['freight'] : 0;
    $p  = $t - $f;
    $stmt = $conn->prepare("UPDATE haleeb_bilty SET date=?, vehicle=?, vehicle_type=?, delivery_note=?, token_no=?, party=?, location=?, stops=?, freight=?, tender=?, profit=? WHERE id=?");
    $stmt->bind_param("ssssssssiiii", $d, $v, $vt, $dn, $tn, $party, $l, $stops, $f, $t, $p, $id);
    $stmt->execute(); $stmt->close();
    header("location:haleeb.php"); exit();
}

$stmt = $conn->prepare("SELECT * FROM haleeb_bilty WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$row){ header("location:haleeb.php"); exit(); }

$locationOptions = [];
$locationSeen = [];
$locRes = $conn->query("SELECT DISTINCT custom_to FROM haleeb_image_processed_rates WHERE custom_to IS NOT NULL AND custom_to <> '' ORDER BY custom_to ASC");
while($locRes && $lrow = $locRes->fetch_assoc()){
    $location = trim((string)$lrow['custom_to']);
    if($location === '') continue;
    $locKey = normalize_lookup_token($location);
    if(isset($locationSeen[$locKey])) continue;
    $locationSeen[$locKey] = true;
    $locationOptions[] = $location;
}

$vehicleTypeOptions = [];
$vehicleTypeLookup = [];
$vehicleTypeSeen = [];
$vehicleColumns = [];
$vtRes = $conn->query("SELECT column_key, column_label FROM haleeb_rate_list_columns WHERE is_deleted=0 AND column_key LIKE 'custom_%' ORDER BY display_order ASC, id ASC");
while($vtRes && $vrow = $vtRes->fetch_assoc()){
    $columnKey = (string)$vrow['column_key'];
    $columnLabel = trim((string)$vrow['column_label']);
    if($columnKey === '') continue;
    if($columnLabel === '') $columnLabel = $columnKey;

    $labelKey = normalize_lookup_token($columnLabel);
    if(!isset($vehicleTypeSeen[$labelKey])){
      $vehicleTypeSeen[$labelKey] = true;
      $vehicleTypeOptions[] = $columnLabel;
    }

    $vehicleTypeLookup[$labelKey] = $columnKey;
    $vehicleTypeLookup[normalize_lookup_token($columnKey)] = $columnKey;
    $vehicleColumns[] = ['column_key' => $columnKey];
}

$rateLookup = [];
if(count($vehicleColumns) > 0){
  $rateRes = $conn->query("SELECT id, custom_to, custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data FROM haleeb_image_processed_rates ORDER BY id DESC");
  while($rateRes && $rateRow = $rateRes->fetch_assoc()){
    $location = trim((string)$rateRow['custom_to']);
    if($location === '') continue;

    $locKey = normalize_lookup_token($location);
    if(isset($rateLookup[$locKey])) continue;

    $extra = [];
    if(isset($rateRow['extra_data']) && $rateRow['extra_data'] !== ''){
      $decoded = json_decode((string)$rateRow['extra_data'], true);
      if(is_array($decoded)) $extra = $decoded;
    }

    $ratesForLocation = [];
    foreach($vehicleColumns as $vehicleCol){
      $key = $vehicleCol['column_key'];
      $value = '';
      if(array_key_exists($key, $rateRow)){
        $value = (string)$rateRow[$key];
      } elseif(isset($extra[$key])){
        $value = (string)$extra[$key];
      }
      $ratesForLocation[$key] = trim($value);
    }

    $rateLookup[$locKey] = $ratesForLocation;
  }
}

$jsonVehicleTypeLookup = json_encode($vehicleTypeLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if($jsonVehicleTypeLookup === false) $jsonVehicleTypeLookup = '{}';
$jsonRateLookup = json_encode($rateLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if($jsonRateLookup === false) $jsonRateLookup = '{}';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Haleeb Bilty — Token <?php echo htmlspecialchars($row['token_no']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #60a5fa; --red: #ef4444; --green: #22c55e;
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
  .update-btn:hover { background: #3b82f6; color: #fff; }

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
    <h1>Edit Haleeb Bilty</h1>
  </div>
  <a class="nav-btn" href="haleeb.php">Back</a>
</div>

<div class="main">
  <div class="form-card">
    <div class="form-title">Edit Haleeb Bilty</div>
    <div class="form-sub">Token: <?php echo htmlspecialchars($row['token_no']); ?> &nbsp;·&nbsp; ID: <?php echo $id; ?></div>

    <form method="post">
      <div class="grid">
        <div class="field">
          <label for="date">Date</label>
          <input id="date" type="date" name="date" value="<?php echo htmlspecialchars($row['date']); ?>" required>
        </div>
        <div class="field">
          <label for="vehicle">Vehicle</label>
          <input id="vehicle" name="vehicle" value="<?php echo htmlspecialchars($row['vehicle']); ?>" required>
        </div>
        <div class="field">
          <label for="vehicle_type">Vehicle Type</label>
          <input id="vehicle_type" name="vehicle_type" value="<?php echo htmlspecialchars($row['vehicle_type']); ?>" list="vehicle_type_list" required>
        </div>
        <div class="field">
          <label for="delivery_note">Delivery Note</label>
          <input id="delivery_note" name="delivery_note" value="<?php echo htmlspecialchars($row['delivery_note']); ?>" required>
        </div>
        <div class="field">
          <label for="token_no">Token No</label>
          <input id="token_no" name="token_no" value="<?php echo htmlspecialchars($row['token_no']); ?>" required>
        </div>
        <div class="field">
          <label for="party">Party</label>
          <input id="party" name="party" value="<?php echo htmlspecialchars($row['party']); ?>">
        </div>
        <div class="field">
          <label for="location">Location</label>
          <input id="location" name="location" value="<?php echo htmlspecialchars($row['location']); ?>" list="location_list" required>
        </div>
        <div class="field">
          <label for="stops">Stops</label>
          <input id="stops" name="stops" value="<?php echo htmlspecialchars(isset($row['stops']) ? $row['stops'] : ''); ?>" list="stops_list" placeholder="same city / out city" required>
        </div>
        <div class="field">
          <label for="tender">Tender</label>
          <input id="tender" type="number" name="tender" value="<?php echo htmlspecialchars($row['tender']); ?>" min="0" required>
        </div>
        <div class="field">
          <label for="freight">Freight</label>
          <input id="freight" type="number" name="freight" value="<?php echo htmlspecialchars($row['freight']); ?>" min="0" required>
        </div>
      </div>

      <div class="form-footer">
        <a class="delete-btn" href="delete_haleeb_bilty.php?id=<?php echo $id; ?>" onclick="return confirm('Delete this bilty?')">&#128465; Delete</a>
        <button class="update-btn" type="submit" name="update">Save</button>
      </div>
    </form>
    <datalist id="location_list">
      <?php foreach($locationOptions as $opt): ?>
        <option value="<?php echo htmlspecialchars($opt); ?>">
      <?php endforeach; ?>
    </datalist>
    <datalist id="vehicle_type_list">
      <?php foreach($vehicleTypeOptions as $opt): ?>
        <option value="<?php echo htmlspecialchars($opt); ?>">
      <?php endforeach; ?>
    </datalist>
    <datalist id="stops_list"></datalist>
  </div>
</div>
<script>
(function(){
  var stopsInput = document.getElementById('stops');
  var stopsList = document.getElementById('stops_list');
  var locationInput = document.getElementById('location');
  var vehicleTypeInput = document.getElementById('vehicle_type');
  var tenderInput = document.getElementById('tender');
  if(stopsInput && stopsList && stopsList.options.length === 0){
    ['same city', 'out city'].forEach(function(v){
      var opt = document.createElement('option');
      opt.value = v;
      stopsList.appendChild(opt);
    });
  }
  if(!locationInput || !vehicleTypeInput || !tenderInput) return;

  var vehicleTypeLookup = <?php echo $jsonVehicleTypeLookup; ?>;
  var rateLookup = <?php echo $jsonRateLookup; ?>;

  function normalizeToken(v){
    return String(v || '').trim().toLowerCase().replace(/\s+/g, ' ');
  }

  function parseRateNumber(raw){
    var cleaned = String(raw || '').replace(/,/g, '').replace(/\s+/g, '').trim();
    if(cleaned === '') return null;
    var n = Number(cleaned);
    if(!Number.isFinite(n)) return null;
    return Math.round(n);
  }

  function normalizeAlphaNum(v){
    return String(v || '').toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  function getVehicleBucket(vehicleTypeRaw){
    var k = normalizeAlphaNum(vehicleTypeRaw);
    if(k === 'mazda') return 'mazda';
    if(k === '14ft') return '14ft';
    if(k === '20ft') return '20ft';
    if(k.indexOf('40ft') === 0) return '40ft';
    return '';
  }

  function getStopsAddon(stopsRaw, vehicleTypeRaw){
    var stop = normalizeAlphaNum(stopsRaw);
    var bucket = getVehicleBucket(vehicleTypeRaw);
    if(bucket === '') return 0;

    var sameCity = {
      mazda: 3000,
      '14ft': 3000,
      '20ft': 5000,
      '40ft': 7000
    };
    var outCity = {
      mazda: 4000,
      '14ft': 4000,
      '20ft': 8000,
      '40ft': 8000
    };

    if(stop === 'samecity') return sameCity[bucket] || 0;
    if(stop === 'outcity') return outCity[bucket] || 0;
    return 0;
  }

  function getBaseTenderFromRateList(){
    var locationKey = normalizeToken(locationInput.value);
    var vehicleInputKey = normalizeToken(vehicleTypeInput.value);
    if(locationKey === '' || vehicleInputKey === '') return null;
    if(!Object.prototype.hasOwnProperty.call(rateLookup, locationKey)) return null;

    var vehicleKey = vehicleTypeLookup[vehicleInputKey] || vehicleInputKey;
    var row = rateLookup[locationKey];
    if(!Object.prototype.hasOwnProperty.call(row, vehicleKey)) return null;
    return parseRateNumber(row[vehicleKey]);
  }

  function tryAutoTender(){
    var baseTender = getBaseTenderFromRateList();
    var addon = getStopsAddon(stopsInput ? stopsInput.value : '', vehicleTypeInput.value);
    if(baseTender === null && addon === 0) return;
    tenderInput.value = (baseTender === null ? 0 : baseTender) + addon;
  }

  locationInput.addEventListener('change', tryAutoTender);
  locationInput.addEventListener('blur', tryAutoTender);
  vehicleTypeInput.addEventListener('change', tryAutoTender);
  vehicleTypeInput.addEventListener('blur', tryAutoTender);
  if(stopsInput){
    stopsInput.addEventListener('change', tryAutoTender);
    stopsInput.addEventListener('blur', tryAutoTender);
  }
  tryAutoTender();
})();
</script>
</body>
</html>
