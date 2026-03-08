<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
$isSuperAdmin = auth_is_super_admin();
$today = date('Y-m-d');
$flashSuccess = '';
$flashError = '';
$flashOld = [];
$resultFlag = isset($_GET['r']) ? strtolower(trim((string)$_GET['r'])) : '';
if(isset($_SESSION['add_haleeb_success'])){
    $flashSuccess = trim((string)$_SESSION['add_haleeb_success']);
    unset($_SESSION['add_haleeb_success']);
}
if(isset($_SESSION['add_haleeb_error'])){
    $flashError = (string)$_SESSION['add_haleeb_error'];
    unset($_SESSION['add_haleeb_error']);
}
if(isset($_SESSION['add_haleeb_old']) && is_array($_SESSION['add_haleeb_old'])){
    $flashOld = $_SESSION['add_haleeb_old'];
    unset($_SESSION['add_haleeb_old']);
}

$formErrorMessage = '';
if($flashError === 'save_failed'){
    $formErrorMessage = 'Haleeb bilty save nahi ho saki. Dobara try karein.';
}
$centerNoticeType = '';
$centerNoticeTitle = '';
$centerNoticeMessage = '';
if($flashSuccess !== ''){
    $centerNoticeType = 'success';
    $centerNoticeTitle = 'Saved';
    $centerNoticeMessage = $flashSuccess;
} elseif($formErrorMessage !== ''){
    $centerNoticeType = 'error';
    $centerNoticeTitle = 'Error';
    $centerNoticeMessage = $formErrorMessage;
} elseif($resultFlag === 'success'){
    $centerNoticeType = 'success';
    $centerNoticeTitle = 'Saved';
    $centerNoticeMessage = 'Haleeb bilty save ho gai.';
} elseif($resultFlag === 'error'){
    $centerNoticeType = 'error';
    $centerNoticeTitle = 'Error';
    $centerNoticeMessage = 'Request complete nahi ho saki.';
}
$formValues = [
    'date' => isset($flashOld['date']) && trim((string)$flashOld['date']) !== '' ? trim((string)$flashOld['date']) : $today,
    'vehicle' => isset($flashOld['vehicle']) ? trim((string)$flashOld['vehicle']) : '',
    'vehicle_type' => isset($flashOld['vehicle_type']) ? trim((string)$flashOld['vehicle_type']) : '',
    'driver_phone_no' => isset($flashOld['driver_phone_no']) ? trim((string)$flashOld['driver_phone_no']) : '',
    'party' => isset($flashOld['party']) ? trim((string)$flashOld['party']) : '',
    'location' => isset($flashOld['location']) ? trim((string)$flashOld['location']) : '',
    'delivery_status' => isset($flashOld['delivery_status']) ? strtolower(trim((string)$flashOld['delivery_status'])) : 'not_received',
    'token_no' => isset($flashOld['token_no']) ? trim((string)$flashOld['token_no']) : '',
    'delivery_note' => isset($flashOld['delivery_note']) ? trim((string)$flashOld['delivery_note']) : '',
    'tender' => isset($flashOld['tender']) ? trim((string)$flashOld['tender']) : '0',
    'freight' => isset($flashOld['freight']) ? trim((string)$flashOld['freight']) : '',
];
if(!in_array($formValues['delivery_status'], ['received', 'not_received'], true)){
    $formValues['delivery_status'] = 'not_received';
}

function normalize_lookup_token($v){
    $v = strtolower(trim((string)$v));
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

$locationOptions = [];
$locationSeen = [];
$locRes = $conn->query("SELECT DISTINCT custom_to FROM haleeb_image_processed_rates WHERE custom_to IS NOT NULL AND custom_to <> '' ORDER BY custom_to ASC");
while($locRes && $row = $locRes->fetch_assoc()){
    $location = trim((string)$row['custom_to']);
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
while($vtRes && $row = $vtRes->fetch_assoc()){
    $columnKey = (string)$row['column_key'];
    $columnLabel = trim((string)$row['column_label']);
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
<title>Add Haleeb Bilty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #60a5fa; --green: #22c55e; --red: #ef4444;
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

  .main { display: flex; align-items: flex-start; justify-content: center; padding: 40px 24px; }
  .form-card { background: var(--surface); border: 1px solid var(--border); padding: 32px; width: min(860px, 100%); position: relative; overflow: hidden; }
  .form-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }

  .form-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 28px; }
  .status-notice { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; padding: 18px; z-index: 500; }
  .status-notice.show { display: flex; }
  .status-card { width: min(360px, 100%); background: var(--surface); border: 1px solid var(--border); padding: 18px 16px 16px; text-align: center; box-shadow: 0 12px 32px rgba(0,0,0,0.35); }
  .status-icon { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 12px; position: relative; }
  .status-icon::before, .status-icon::after { content: ''; position: absolute; background: #fff; border-radius: 2px; }
  .status-notice.success .status-icon { background: var(--green); }
  .status-notice.success .status-icon::before { width: 10px; height: 4px; transform: rotate(45deg); left: 16px; top: 34px; }
  .status-notice.success .status-icon::after { width: 24px; height: 4px; transform: rotate(-45deg); left: 23px; top: 29px; }
  .status-notice.error .status-icon { background: var(--red); }
  .status-notice.error .status-icon::before { width: 30px; height: 4px; transform: rotate(45deg); left: 17px; top: 30px; }
  .status-notice.error .status-icon::after { width: 30px; height: 4px; transform: rotate(-45deg); left: 17px; top: 30px; }
  .status-title { font-size: 18px; font-weight: 800; margin-bottom: 6px; }
  .status-text { font-size: 13px; color: var(--text); line-height: 1.45; word-break: break-word; }

  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }
  .field.span-2 { grid-column: 1 / -1; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  .field input, .field select {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 11px 14px; font-family: var(--font); font-size: 14px; transition: border-color 0.15s;
  }
  .field input:focus, .field select:focus { outline: none; border-color: var(--accent); }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .field input::placeholder { color: var(--muted); }
  .field select.status-tag { font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
  .field select.status-tag.is-received { border-color: rgba(34,197,94,0.65); color: #86efac; background: rgba(34,197,94,0.09); }
  .field select.status-tag.is-not-received { border-color: rgba(239,68,68,0.65); color: #fca5a5; background: rgba(239,68,68,0.09); }
  .stops-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .stop-box { border: 1px solid var(--border); background: var(--bg); padding: 10px; min-height: 92px; }
  .stop-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
  .stop-title { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); }
  .stop-add {
    border: 1px solid var(--border); background: var(--surface2); color: var(--text);
    padding: 4px 8px; font-size: 11px; font-family: var(--font); cursor: pointer;
  }
  .stop-add:hover { border-color: var(--muted); }
  .stop-list { display: grid; gap: 8px; min-height: 28px; }
  .stop-item { border: 1px solid var(--border); background: var(--surface2); padding: 8px; display: grid; gap: 8px; }
  .stop-item-head { display: flex; justify-content: space-between; align-items: center; }
  .stop-item-title { font-size: 10px; letter-spacing: 1px; color: var(--muted); text-transform: uppercase; font-weight: 700; }
  .stop-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
  .stop-input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 7px 8px; font-family: var(--font); font-size: 12px;
  }
  .stop-input:focus { outline: none; border-color: var(--accent); }
  .stop-remove {
    border: 1px solid rgba(239,68,68,0.35); background: rgba(239,68,68,0.12); color: #fca5a5;
    cursor: pointer; font-size: 11px; line-height: 1; padding: 5px 7px; font-family: var(--font);
  }
  .stop-remove:hover { background: rgba(239,68,68,0.2); color: #fecaca; }
  .stop-empty { font-size: 11px; color: var(--muted); }
  .stops-error { margin-top: 8px; font-size: 11px; color: #fca5a5; min-height: 16px; }

  .form-footer { margin-top: 28px; display: flex; justify-content: flex-end; }
  .submit-btn { padding: 13px 36px; background: var(--accent); color: #0e0f11; border: none; cursor: pointer; font-family: var(--font); font-size: 14px; font-weight: 800; transition: background 0.15s; }
  .submit-btn:hover { background: #3b82f6; color: #fff; }

  @media(max-width: 640px) {
    .main { padding: 20px 14px; }
    .form-card { padding: 22px 16px; }
    .grid { grid-template-columns: 1fr; }
    .topbar { padding: 14px 16px; }
    .stop-fields { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Haleeb</span>
    <h1>Add Haleeb Bilty</h1>
  </div>
  <a class="nav-btn" href="haleeb.php">Back</a>
</div>

<?php if($centerNoticeType !== '' && $centerNoticeMessage !== ''): ?>
<div class="status-notice <?php echo htmlspecialchars($centerNoticeType); ?>" id="status_notice">
  <div class="status-card">
    <div class="status-icon" aria-hidden="true"></div>
    <div class="status-title"><?php echo htmlspecialchars($centerNoticeTitle); ?></div>
    <div class="status-text"><?php echo htmlspecialchars($centerNoticeMessage); ?></div>
  </div>
</div>
<?php endif; ?>

<div class="main">
  <div class="form-card">
    <div class="form-title">Add Haleeb Bilty</div>
    <form action="save_haleeb_bilty.php" method="post">
      <div class="grid">
        <div class="field">
          <label for="date">Date</label>
          <input id="date" type="date" name="date" value="<?php echo htmlspecialchars($formValues['date']); ?>" required>
        </div>
        <div class="field">
          <label for="vehicle">Vehicle</label>
          <input id="vehicle" name="vehicle" placeholder="Vehicle number" value="<?php echo htmlspecialchars($formValues['vehicle']); ?>" required>
        </div>
        <div class="field">
          <label for="vehicle_type">Vehicle Type</label>
          <input id="vehicle_type" name="vehicle_type" placeholder="Truck" list="vehicle_type_list" value="<?php echo htmlspecialchars($formValues['vehicle_type']); ?>" required>
        </div>
        <div class="field">
          <label for="driver_phone_no">Driver Phone No</label>
          <input id="driver_phone_no" name="driver_phone_no" placeholder="03xx-xxxxxxx" value="<?php echo htmlspecialchars($formValues['driver_phone_no']); ?>" inputmode="tel">
        </div>
        <div class="field">
          <label for="party">Party</label>
          <input id="party" name="party" placeholder="Party name" value="<?php echo htmlspecialchars($formValues['party']); ?>">
        </div>
        <div class="field">
          <label for="location">Location</label>
          <input id="location" name="location" placeholder="Location" list="location_list" value="<?php echo htmlspecialchars($formValues['location']); ?>" required>
        </div>
        <div class="field">
          <label for="delivery_status">Delivery Status</label>
          <select id="delivery_status" name="delivery_status" class="status-tag <?php echo $formValues['delivery_status'] === 'received' ? 'is-received' : 'is-not-received'; ?>" required>
            <option value="not_received" <?php echo $formValues['delivery_status'] !== 'received' ? 'selected' : ''; ?>>Not Received</option>
            <option value="received" <?php echo $formValues['delivery_status'] === 'received' ? 'selected' : ''; ?>>Received</option>
          </select>
        </div>
        <div class="field">
          <label for="token_no">Token No</label>
          <input id="token_no" name="token_no" placeholder="Token number" value="<?php echo htmlspecialchars($formValues['token_no']); ?>" required>
        </div>
        <div class="field">
          <label for="delivery_note">Delivery Note</label>
          <input id="delivery_note" name="delivery_note" placeholder="Delivery note number" value="<?php echo htmlspecialchars($formValues['delivery_note']); ?>" required>
        </div>
        <div class="field span-2">
          <label>Stops</label>
          <div class="stops-grid">
            <div class="stop-box">
              <div class="stop-head">
                <span class="stop-title">Same City</span>
                <button class="stop-add" type="button" id="add_same_stop">+ Add</button>
              </div>
              <div class="stop-list" id="same_stop_list"></div>
              <input type="hidden" id="same_city_count" name="same_city_count" value="0">
            </div>
            <div class="stop-box">
              <div class="stop-head">
                <span class="stop-title">Out City</span>
                <button class="stop-add" type="button" id="add_out_stop">+ Add</button>
              </div>
              <div class="stop-list" id="out_stop_list"></div>
              <input type="hidden" id="out_city_count" name="out_city_count" value="0">
            </div>
          </div>
          <input type="hidden" id="same_stops_json" name="same_stops_json" value="[]">
          <input type="hidden" id="out_stops_json" name="out_stops_json" value="[]">
          <div class="stops-error" id="stops_error"></div>
        </div>
        <?php if($isSuperAdmin): ?>
          <div class="field">
            <label for="tender">Tender</label>
            <input id="tender" type="number" name="tender" placeholder="0" value="<?php echo htmlspecialchars($formValues['tender']); ?>" min="0" step="any" required>
          </div>
        <?php else: ?>
          <input id="tender" type="hidden" name="tender" value="<?php echo htmlspecialchars($formValues['tender']); ?>">
        <?php endif; ?>
        <div class="field">
          <label for="freight">Freight</label>
          <input id="freight" type="number" name="freight" placeholder="0" value="<?php echo htmlspecialchars($formValues['freight']); ?>" min="0" step="any" required>
        </div>
      </div>
      <div class="form-footer">
        <button class="submit-btn" type="submit">Save</button>
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
  </div>
</div>
<script>
(function(){
  var locationInput = document.getElementById('location');
  var vehicleTypeInput = document.getElementById('vehicle_type');
  var tenderInput = document.getElementById('tender');
  var deliveryStatusInput = document.getElementById('delivery_status');
  var addSameStopBtn = document.getElementById('add_same_stop');
  var addOutStopBtn = document.getElementById('add_out_stop');
  var sameStopList = document.getElementById('same_stop_list');
  var outStopList = document.getElementById('out_stop_list');
  var sameCityCountInput = document.getElementById('same_city_count');
  var outCityCountInput = document.getElementById('out_city_count');
  var sameStopsJsonInput = document.getElementById('same_stops_json');
  var outStopsJsonInput = document.getElementById('out_stops_json');
  var stopsError = document.getElementById('stops_error');
  var form = document.querySelector('form[action="save_haleeb_bilty.php"]');
  if(!locationInput || !vehicleTypeInput || !tenderInput || !addSameStopBtn || !addOutStopBtn || !sameStopList || !outStopList || !sameCityCountInput || !outCityCountInput || !sameStopsJsonInput || !outStopsJsonInput || !form) return;

  var vehicleTypeLookup = <?php echo $jsonVehicleTypeLookup; ?>;
  var rateLookup = <?php echo $jsonRateLookup; ?>;
  var sameStops = [];
  var outStops = [];

  function normalizeToken(v){
    return String(v || '').trim().toLowerCase().replace(/\s+/g, ' ');
  }

  function parseRateNumber(raw){
    var cleaned = String(raw || '').replace(/,/g, '').replace(/\s+/g, '').trim();
    if(cleaned === '') return null;
    var n = Number(cleaned);
    if(!Number.isFinite(n)) return null;
    return n;
  }

  function roundMoney(v){
    return Math.round(v * 1000) / 1000;
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
    if(baseTender === null) return;
    tenderInput.value = String(roundMoney(baseTender));
  }

  function syncDeliveryStatusTag(){
    if(!deliveryStatusInput) return;
    if(String(deliveryStatusInput.value) === 'received'){
      deliveryStatusInput.classList.add('is-received');
      deliveryStatusInput.classList.remove('is-not-received');
      return;
    }
    deliveryStatusInput.classList.add('is-not-received');
    deliveryStatusInput.classList.remove('is-received');
  }

  function makeStopField(placeholder, value, onInput){
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'stop-input';
    input.placeholder = placeholder;
    input.value = value || '';
    input.addEventListener('input', function(){
      onInput(String(input.value || ''));
    });
    return input;
  }

  function makeStopItem(stop, title, onRemove, onUpdate){
    var box = document.createElement('div');
    box.className = 'stop-item';

    var head = document.createElement('div');
    head.className = 'stop-item-head';
    var ttl = document.createElement('span');
    ttl.className = 'stop-item-title';
    ttl.textContent = title;
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'stop-remove';
    removeBtn.textContent = 'Remove';
    removeBtn.addEventListener('click', onRemove);
    head.appendChild(ttl);
    head.appendChild(removeBtn);

    var fields = document.createElement('div');
    fields.className = 'stop-fields';
    fields.appendChild(makeStopField('Delivery Note', stop.delivery_note, function(v){ stop.delivery_note = v; onUpdate(); }));
    fields.appendChild(makeStopField('Party', stop.party, function(v){ stop.party = v; onUpdate(); }));
    fields.appendChild(makeStopField('City', stop.location, function(v){ stop.location = v; onUpdate(); }));

    box.appendChild(head);
    box.appendChild(fields);
    return box;
  }

  function renderStopList(target, list, labelPrefix, removeCb){
    target.innerHTML = '';
    if(list.length === 0){
      var empty = document.createElement('span');
      empty.className = 'stop-empty';
      empty.textContent = 'No stops added';
      target.appendChild(empty);
      return;
    }
    list.forEach(function(stop, idx){
      target.appendChild(makeStopItem(
        stop,
        labelPrefix + ' Stop ' + (idx + 1),
        function(){ removeCb(idx); },
        function(){ syncStopPayload(); clearStopsError(); }
      ));
    });
  }

  function syncStopPayload(){
    sameCityCountInput.value = String(sameStops.length);
    outCityCountInput.value = String(outStops.length);
    sameStopsJsonInput.value = JSON.stringify(sameStops);
    outStopsJsonInput.value = JSON.stringify(outStops);
  }

  function clearStopsError(){
    if(stopsError) stopsError.textContent = '';
  }

  function setStopsError(msg){
    if(stopsError) stopsError.textContent = msg || '';
  }

  function renderStops(){
    syncStopPayload();
    renderStopList(sameStopList, sameStops, 'Same', function(idx){
      sameStops.splice(idx, 1);
      renderStops();
    });
    renderStopList(outStopList, outStops, 'Out', function(idx){
      outStops.splice(idx, 1);
      renderStops();
    });
  }

  function validateStopRows(list, label){
    for(var i = 0; i < list.length; i += 1){
      var s = list[i] || {};
      if(String(s.delivery_note || '').trim() === ''){
        setStopsError(label + ' stop ' + (i + 1) + ': Delivery Note required.');
        return false;
      }
      if(String(s.party || '').trim() === ''){
        setStopsError(label + ' stop ' + (i + 1) + ': Party required.');
        return false;
      }
      if(String(s.location || '').trim() === ''){
        setStopsError(label + ' stop ' + (i + 1) + ': City required.');
        return false;
      }
    }
    return true;
  }

  function addStop(type){
    var details = {
      delivery_note: '',
      party: '',
      location: String(locationInput.value || '').trim()
    };
    clearStopsError();

    if(type === 'same') sameStops.push(details);
    else outStops.push(details);
    renderStops();
  }

  addSameStopBtn.addEventListener('click', function(){ addStop('same'); });
  addOutStopBtn.addEventListener('click', function(){ addStop('out'); });

  locationInput.addEventListener('change', tryAutoTender);
  locationInput.addEventListener('blur', tryAutoTender);
  vehicleTypeInput.addEventListener('change', tryAutoTender);
  vehicleTypeInput.addEventListener('blur', tryAutoTender);
  if(deliveryStatusInput){
    deliveryStatusInput.addEventListener('change', syncDeliveryStatusTag);
  }

  form.addEventListener('submit', function(e){
    clearStopsError();
    if(!validateStopRows(sameStops, 'Same City') || !validateStopRows(outStops, 'Out City')){
      e.preventDefault();
      if(stopsError){
        stopsError.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      return;
    }
    syncStopPayload();
  });

  renderStops();
  tryAutoTender();
  syncDeliveryStatusTag();
})();
</script>
<?php if($centerNoticeType !== '' && $centerNoticeMessage !== ''): ?>
<script>
window.addEventListener('DOMContentLoaded', function(){
  var notice = document.getElementById('status_notice');
  if(!notice) return;
  notice.classList.add('show');

  var hideTimer = setTimeout(function(){
    notice.classList.remove('show');
  }, 2200);

  notice.addEventListener('click', function(){
    clearTimeout(hideTimer);
    notice.classList.remove('show');
  });
});
</script>
<?php endif; ?>
</body>
</html>

