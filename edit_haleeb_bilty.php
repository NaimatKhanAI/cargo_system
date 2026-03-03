<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){ header("location:haleeb.php"); exit(); }
$isSuperAdmin = auth_is_super_admin();
$linkedRequestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$linkedRequest = null;

function normalize_lookup_token($v){
    $v = strtolower(trim((string)$v));
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function parse_stops_counts($raw){
    $same = 0;
    $out = 0;
    $raw = trim((string)$raw);
    if($raw === ''){
        return ['same_city' => 0, 'out_city' => 0];
    }

    if(preg_match('/sc\s*:\s*(\d+)/i', $raw, $m)){ $same = (int)$m[1]; }
    if(preg_match('/oc\s*:\s*(\d+)/i', $raw, $m)){ $out = (int)$m[1]; }

    if($same === 0 && $out === 0){
        $decoded = json_decode($raw, true);
        if(is_array($decoded)){
            if(isset($decoded['same_city'])) $same = (int)$decoded['same_city'];
            if(isset($decoded['out_city'])) $out = (int)$decoded['out_city'];
        }
    }

    if($same === 0 && $out === 0){
        $legacy = preg_replace('/[^a-z0-9]/', '', strtolower($raw));
        if($legacy === 'samecity'){
            $same = 1;
        } elseif($legacy !== ''){
            $out = 1;
        }
    }

    return ['same_city' => max(0, $same), 'out_city' => max(0, $out)];
}

function encode_stops_counts($same, $out){
    return 'SC:' . max(0, (int)$same) . '|OC:' . max(0, (int)$out);
}

if(isset($_POST['update'])){
    $d  = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
    $v  = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
    $vt = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : '';
    $dn = isset($_POST['delivery_note']) ? trim($_POST['delivery_note']) : '';
    $tn = isset($_POST['token_no']) ? trim($_POST['token_no']) : '';
    $party = isset($_POST['party']) ? trim($_POST['party']) : '';
    $l  = isset($_POST['location']) ? trim($_POST['location']) : '';
    $sameCityCount = isset($_POST['same_city_count']) ? (int)$_POST['same_city_count'] : 0;
    $outCityCount = isset($_POST['out_city_count']) ? (int)$_POST['out_city_count'] : 0;
    $stops = encode_stops_counts($sameCityCount, $outCityCount);
    $t  = isset($_POST['tender']) ? max(0, round((float)$_POST['tender'], 3)) : 0.0;
    $f  = isset($_POST['freight']) ? max(0, round((float)$_POST['freight'], 3)) : 0.0;
    $commission = isset($_POST['commission']) ? max(0, round((float)$_POST['commission'], 3)) : 0.0;
    $freightPaymentType = isset($_POST['freight_payment_type']) ? strtolower(trim((string)$_POST['freight_payment_type'])) : 'to_pay';
    if(!in_array($freightPaymentType, ['to_pay', 'paid'], true)){
        $freightPaymentType = 'to_pay';
    }
    if(!auth_can_direct_modify()){
        $currentTypeStmt = $conn->prepare("SELECT freight_payment_type FROM haleeb_bilty WHERE id=? LIMIT 1");
        $currentTypeStmt->bind_param("i", $id);
        $currentTypeStmt->execute();
        $currentTypeRow = $currentTypeStmt->get_result()->fetch_assoc();
        $currentTypeStmt->close();
        $existingType = $currentTypeRow && isset($currentTypeRow['freight_payment_type']) ? strtolower(trim((string)$currentTypeRow['freight_payment_type'])) : 'to_pay';
        if(!in_array($existingType, ['to_pay', 'paid'], true)){
            $existingType = 'to_pay';
        }
        $freightPaymentType = $existingType;
    }
    $totalFreight = max(0, $f - $commission);
    if(!auth_can_direct_modify()){
        $payload = [
            'date' => $d,
            'vehicle' => $v,
            'vehicle_type' => $vt,
            'delivery_note' => $dn,
            'token_no' => $tn,
            'party' => $party,
            'location' => $l,
            'stops' => $stops,
            'freight' => $f,
            'commission' => $commission,
            'freight_payment_type' => $freightPaymentType,
            'tender' => $t
        ];
        $requestId = create_change_request_local($conn, 'haleeb', 'haleeb_bilty', $id, 'haleeb_update', $payload, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
        if($requestId > 0){
            header("location:haleeb.php?req=submitted");
            exit();
        }
    } else {
        $p  = $t - $totalFreight;
        $oldData = $conn->query("SELECT date, vehicle, vehicle_type, delivery_note, token_no, party, location, stops, freight, commission, freight_payment_type, tender FROM haleeb_bilty WHERE id=" . (int)$id . " LIMIT 1")->fetch_assoc();
        $stmt = $conn->prepare("UPDATE haleeb_bilty SET date=?, vehicle=?, vehicle_type=?, delivery_note=?, token_no=?, party=?, location=?, stops=?, freight=?, commission=?, freight_payment_type=?, tender=?, profit=? WHERE id=?");
        $stmt->bind_param("ssssssssddsddi", $d, $v, $vt, $dn, $tn, $party, $l, $stops, $f, $commission, $freightPaymentType, $t, $p, $id);
        $stmt->execute(); $stmt->close();
        activity_notify_local(
            $conn,
            'haleeb',
            'bilty_updated_direct',
            'haleeb_bilty',
            $id,
            'Haleeb bilty updated directly.',
            [
                'old' => $oldData ?: [],
                'new' => [
                    'date' => $d,
                    'vehicle' => $v,
                    'vehicle_type' => $vt,
                    'delivery_note' => $dn,
                    'token_no' => $tn,
                    'party' => $party,
                    'location' => $l,
                    'stops' => $stops,
                    'freight' => $f,
                    'commission' => $commission,
                    'freight_payment_type' => $freightPaymentType,
                    'tender' => $t
                ]
            ],
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
        );
        if($linkedRequestId > 0){
            $closeError = '';
            mark_change_request_handled_local(
                $conn,
                $linkedRequestId,
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
                'Approved after admin manual edit in full view.',
                $closeError,
                ['haleeb_update'],
                $id,
                'haleeb_bilty'
            );
        }
        header("location:haleeb.php"); exit();
    }
}

$stmt = $conn->prepare("SELECT * FROM haleeb_bilty WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$row){ header("location:haleeb.php"); exit(); }
if($linkedRequestId > 0 && auth_can_direct_modify()){
    $candidate = fetch_pending_change_request_by_id_local($conn, $linkedRequestId);
    if(
        $candidate &&
        isset($candidate['action_type']) && (string)$candidate['action_type'] === 'haleeb_update' &&
        isset($candidate['entity_id']) && (int)$candidate['entity_id'] === $id &&
        isset($candidate['entity_table']) && strcasecmp((string)$candidate['entity_table'], 'haleeb_bilty') === 0
    ){
        $linkedRequest = $candidate;
        $prefill = request_payload_decode_local(isset($candidate['payload']) ? (string)$candidate['payload'] : '');
        if(count($prefill) > 0){
            $map = ['date', 'vehicle', 'vehicle_type', 'delivery_note', 'token_no', 'party', 'location', 'stops', 'freight', 'commission', 'freight_payment_type', 'tender'];
            foreach($map as $key){
                if(array_key_exists($key, $prefill)){
                    $row[$key] = $prefill[$key];
                }
            }
        }
    }
}
$parsedStops = parse_stops_counts(isset($row['stops']) ? $row['stops'] : '');
$initialSameCityStops = $parsedStops['same_city'];
$initialOutCityStops = $parsedStops['out_city'];
$jsonStopsInitial = json_encode(['same_city' => $initialSameCityStops, 'out_city' => $initialOutCityStops], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if($jsonStopsInitial === false) $jsonStopsInitial = '{"same_city":0,"out_city":0}';

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
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
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
  .field.span-2 { grid-column: 1 / -1; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  .field input, .field select {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 11px 14px; font-family: var(--font); font-size: 14px; transition: border-color 0.15s;
  }
  .field input:focus, .field select:focus { outline: none; border-color: var(--accent); }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .field input::placeholder { color: var(--muted); }
  .stops-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .stop-box { border: 1px solid var(--border); background: var(--bg); padding: 10px; min-height: 92px; }
  .stop-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
  .stop-title { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); }
  .stop-add {
    border: 1px solid var(--border); background: var(--surface2); color: var(--text);
    padding: 4px 8px; font-size: 11px; font-family: var(--font); cursor: pointer;
  }
  .stop-add:hover { border-color: var(--muted); }
  .stop-list { display: flex; flex-wrap: wrap; gap: 6px; min-height: 28px; }
  .stop-chip {
    display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border);
    background: var(--surface2); padding: 3px 8px; font-size: 11px; font-family: var(--mono);
  }
  .stop-remove { border: none; background: transparent; color: var(--muted); cursor: pointer; font-size: 12px; line-height: 1; }
  .stop-remove:hover { color: var(--red); }
  .stop-empty { font-size: 11px; color: var(--muted); }

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
    <?php if($linkedRequest): ?>
      <div class="form-sub">Pending Request: #<?php echo (int)$linkedRequest['id']; ?> (values prefilled)</div>
    <?php endif; ?>

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
        <div class="field span-2">
          <label>Stops</label>
          <div class="stops-grid">
            <div class="stop-box">
              <div class="stop-head">
                <span class="stop-title">Same City</span>
                <button class="stop-add" type="button" id="add_same_stop">+ Add</button>
              </div>
              <div class="stop-list" id="same_stop_list"></div>
              <input type="hidden" id="same_city_count" name="same_city_count" value="<?php echo (int)$initialSameCityStops; ?>">
            </div>
            <div class="stop-box">
              <div class="stop-head">
                <span class="stop-title">Out City</span>
                <button class="stop-add" type="button" id="add_out_stop">+ Add</button>
              </div>
              <div class="stop-list" id="out_stop_list"></div>
              <input type="hidden" id="out_city_count" name="out_city_count" value="<?php echo (int)$initialOutCityStops; ?>">
            </div>
          </div>
        </div>
        <?php if($isSuperAdmin): ?>
          <div class="field">
            <label for="tender">Tender</label>
            <input id="tender" type="number" name="tender" value="<?php echo htmlspecialchars($row['tender']); ?>" min="0" step="any" required>
          </div>
        <?php else: ?>
          <input id="tender" type="hidden" name="tender" value="<?php echo htmlspecialchars($row['tender']); ?>">
        <?php endif; ?>
        <div class="field">
          <label for="freight">Freight</label>
          <input id="freight" type="number" name="freight" value="<?php echo htmlspecialchars($row['freight']); ?>" min="0" step="any" required>
        </div>
        <div class="field">
          <label for="commission">Commission</label>
          <input id="commission" type="number" name="commission" value="<?php echo htmlspecialchars(isset($row['commission']) ? $row['commission'] : 0); ?>" min="0" step="any" required>
        </div>
        <?php if($isSuperAdmin): ?>
          <div class="field">
            <label for="freight_payment_type">Driver Payment</label>
            <?php $currentPaymentType = isset($row['freight_payment_type']) ? strtolower((string)$row['freight_payment_type']) : 'to_pay'; if(!in_array($currentPaymentType, ['to_pay','paid'], true)) $currentPaymentType = 'to_pay'; ?>
            <select id="freight_payment_type" name="freight_payment_type" required>
              <option value="to_pay" <?php echo $currentPaymentType === 'to_pay' ? 'selected' : ''; ?>>To Pay</option>
              <option value="paid" <?php echo $currentPaymentType === 'paid' ? 'selected' : ''; ?>>Paid</option>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="freight_payment_type" value="<?php echo htmlspecialchars(isset($row['freight_payment_type']) ? $row['freight_payment_type'] : 'to_pay'); ?>">
        <?php endif; ?>
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
  </div>
</div>
<script>
(function(){
  var locationInput = document.getElementById('location');
  var vehicleTypeInput = document.getElementById('vehicle_type');
  var tenderInput = document.getElementById('tender');
  var addSameStopBtn = document.getElementById('add_same_stop');
  var addOutStopBtn = document.getElementById('add_out_stop');
  var sameStopList = document.getElementById('same_stop_list');
  var outStopList = document.getElementById('out_stop_list');
  var sameCityCountInput = document.getElementById('same_city_count');
  var outCityCountInput = document.getElementById('out_city_count');
  if(!locationInput || !vehicleTypeInput || !tenderInput || !addSameStopBtn || !addOutStopBtn || !sameStopList || !outStopList || !sameCityCountInput || !outCityCountInput) return;

  var vehicleTypeLookup = <?php echo $jsonVehicleTypeLookup; ?>;
  var rateLookup = <?php echo $jsonRateLookup; ?>;
  var stopsInitial = <?php echo $jsonStopsInitial; ?>;
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

  function getStopsAddon(vehicleTypeRaw){
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

    return (sameStops.length * (sameCity[bucket] || 0)) + (outStops.length * (outCity[bucket] || 0));
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
    var addon = getStopsAddon(vehicleTypeInput.value);
    if(baseTender === null && addon === 0) return;
    tenderInput.value = String(roundMoney((baseTender === null ? 0 : baseTender) + addon));
  }

  function makeStopChip(label, onRemove){
    var chip = document.createElement('span');
    chip.className = 'stop-chip';
    chip.appendChild(document.createTextNode(label));
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'stop-remove';
    removeBtn.textContent = 'x';
    removeBtn.addEventListener('click', onRemove);
    chip.appendChild(removeBtn);
    return chip;
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
    list.forEach(function(_, idx){
      target.appendChild(makeStopChip(labelPrefix + ' ' + (idx + 1), function(){ removeCb(idx); }));
    });
  }

  function renderStops(){
    sameCityCountInput.value = String(sameStops.length);
    outCityCountInput.value = String(outStops.length);
    renderStopList(sameStopList, sameStops, 'Same', function(idx){
      sameStops.splice(idx, 1);
      renderStops();
      tryAutoTender();
    });
    renderStopList(outStopList, outStops, 'Out', function(idx){
      outStops.splice(idx, 1);
      renderStops();
      tryAutoTender();
    });
  }

  function addStop(type){
    if(type === 'same') sameStops.push(1);
    else outStops.push(1);
    renderStops();
    tryAutoTender();
  }

  addSameStopBtn.addEventListener('click', function(){ addStop('same'); });
  addOutStopBtn.addEventListener('click', function(){ addStop('out'); });

  var initialSame = Math.max(0, parseInt(stopsInitial && stopsInitial.same_city ? stopsInitial.same_city : 0, 10) || 0);
  var initialOut = Math.max(0, parseInt(stopsInitial && stopsInitial.out_city ? stopsInitial.out_city : 0, 10) || 0);
  for(var i = 0; i < initialSame; i++) sameStops.push(1);
  for(var j = 0; j < initialOut; j++) outStops.push(1);

  locationInput.addEventListener('change', tryAutoTender);
  locationInput.addEventListener('blur', tryAutoTender);
  vehicleTypeInput.addEventListener('change', tryAutoTender);
  vehicleTypeInput.addEventListener('blur', tryAutoTender);
  renderStops();
  tryAutoTender();
})();
</script>
</body>
</html>

