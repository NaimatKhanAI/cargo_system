<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/activity_notifications.php';
require_once 'config/change_requests.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

function decode_stop_rows_local($raw){
    $decoded = json_decode((string)$raw, true);
    if(!is_array($decoded)) return [];
    $out = [];
    foreach($decoded as $item){
        if(!is_array($item)) continue;
        $dn = trim((string)($item['delivery_note'] ?? ''));
        $party = trim((string)($item['party'] ?? ''));
        $location = trim((string)($item['location'] ?? ''));
        if($dn === '' && $party === '' && $location === '') continue;
        $out[] = [
            'delivery_note' => $dn,
            'party' => $party,
            'location' => $location
        ];
    }
    return $out;
}

function vehicle_bucket_local($vehicleType){
    $k = strtolower((string)$vehicleType);
    $k = preg_replace('/[^a-z0-9]/', '', $k);
    if($k === 'mazda') return 'mazda';
    if($k === '14ft') return '14ft';
    if($k === '20ft') return '20ft';
    if(strpos($k, '40ft') === 0) return '40ft';
    return '';
}

function stop_tender_amount_local($vehicleType, $stopType){
    $bucket = vehicle_bucket_local($vehicleType);
    if($bucket === '') return 0.0;

    $sameCity = [
        'mazda' => 3000.0,
        '14ft' => 3000.0,
        '20ft' => 5000.0,
        '40ft' => 7000.0
    ];
    $outCity = [
        'mazda' => 4000.0,
        '14ft' => 4000.0,
        '20ft' => 8000.0,
        '40ft' => 8000.0
    ];

    if($stopType === 'same') return isset($sameCity[$bucket]) ? (float)$sameCity[$bucket] : 0.0;
    return isset($outCity[$bucket]) ? (float)$outCity[$bucket] : 0.0;
}

function normalize_delivery_status_local($raw){
    $status = strtolower(trim((string)$raw));
    $status = str_replace(['-', ' '], '_', $status);
    if($status === 'received') return 'received';
    if($status === 'not_received') return 'not_received';
    return 'not_received';
}

function normalize_lookup_token_local($v){
    $v = strtolower(trim((string)$v));
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function parse_rate_number_local($raw){
    $cleaned = str_replace(',', '', (string)$raw);
    $cleaned = preg_replace('/\s+/', '', $cleaned);
    $cleaned = trim((string)$cleaned);
    if($cleaned === '') return null;
    if(!is_numeric($cleaned)) return null;
    $num = (float)$cleaned;
    if(!is_finite($num)) return null;
    return $num;
}

function resolve_haleeb_tender_rate_local($conn, $location, $vehicleType){
    $location = trim((string)$location);
    if($location === '') return null;
    $vehicleTypeKey = normalize_lookup_token_local($vehicleType);
    if($vehicleTypeKey === '') return null;

    $vehicleTypeLookup = [];
    $vtRes = $conn->query("SELECT column_key, column_label FROM haleeb_rate_list_columns WHERE is_deleted=0 AND column_key LIKE 'custom_%' ORDER BY display_order ASC, id ASC");
    while($vtRes && $row = $vtRes->fetch_assoc()){
        $columnKey = (string)$row['column_key'];
        if($columnKey === '') continue;
        $columnLabel = trim((string)$row['column_label']);
        if($columnLabel === '') $columnLabel = $columnKey;
        $vehicleTypeLookup[normalize_lookup_token_local($columnLabel)] = $columnKey;
        $vehicleTypeLookup[normalize_lookup_token_local($columnKey)] = $columnKey;
    }
    $targetColumn = isset($vehicleTypeLookup[$vehicleTypeKey]) ? (string)$vehicleTypeLookup[$vehicleTypeKey] : '';
    if($targetColumn === '') return null;

    $rateStmt = $conn->prepare("SELECT custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data FROM haleeb_image_processed_rates WHERE custom_to=? ORDER BY id DESC LIMIT 1");
    if(!$rateStmt) return null;
    $rateStmt->bind_param("s", $location);
    $rateStmt->execute();
    $rateRow = $rateStmt->get_result()->fetch_assoc();
    $rateStmt->close();
    if(!$rateRow) return null;

    $rateValue = '';
    if(array_key_exists($targetColumn, $rateRow)){
        $rateValue = (string)$rateRow[$targetColumn];
    }
    if(trim($rateValue) === '' && isset($rateRow['extra_data']) && $rateRow['extra_data'] !== ''){
        $extra = json_decode((string)$rateRow['extra_data'], true);
        if(is_array($extra) && array_key_exists($targetColumn, $extra)){
            $rateValue = (string)$extra[$targetColumn];
        }
    }
    $parsedRate = parse_rate_number_local($rateValue);
    if($parsedRate === null || $parsedRate <= 0){
        return null;
    }
    return round($parsedRate, 3);
}

function insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $driverPhoneNo, $deliveryStatus, $dn, $tn, $party, $addedByUserId, $l, $stops, $f, $commission, $freightPaymentType, $t, $p){
    $stmt = $conn->prepare("INSERT INTO haleeb_bilty(date, vehicle, vehicle_type, driver_phone_no, delivery_status, delivery_note, token_no, party, added_by_user_id, location, stops, freight, commission, freight_payment_type, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if(!$stmt) return [false, 0];
    $stmt->bind_param("ssssssssissddsdd", $d, $v, $vt, $driverPhoneNo, $deliveryStatus, $dn, $tn, $party, $addedByUserId, $l, $stops, $f, $commission, $freightPaymentType, $t, $p);
    $ok = $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();
    return [$ok, $newId];
}

$d = isset($_POST['date']) ? trim((string)$_POST['date']) : date('Y-m-d');
$v = isset($_POST['vehicle']) ? trim((string)$_POST['vehicle']) : '';
$vt = isset($_POST['vehicle_type']) ? trim((string)$_POST['vehicle_type']) : '';
$driverPhoneNo = isset($_POST['driver_phone_no']) ? trim((string)$_POST['driver_phone_no']) : '';
$dn = isset($_POST['delivery_note']) ? trim((string)$_POST['delivery_note']) : '';
$deliveryStatus = normalize_delivery_status_local(isset($_POST['delivery_status']) ? $_POST['delivery_status'] : 'not_received');
$tn = isset($_POST['token_no']) ? trim((string)$_POST['token_no']) : '';
$party = isset($_POST['party']) ? trim((string)$_POST['party']) : '';
$l = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$postedSameCityCount = isset($_POST['same_city_count']) ? (int)$_POST['same_city_count'] : 0;
$postedOutCityCount = isset($_POST['out_city_count']) ? (int)$_POST['out_city_count'] : 0;

$sameStops = decode_stop_rows_local(isset($_POST['same_stops_json']) ? $_POST['same_stops_json'] : '[]');
$outStops = decode_stop_rows_local(isset($_POST['out_stops_json']) ? $_POST['out_stops_json'] : '[]');

if(count($sameStops) === 0 && $postedSameCityCount > 0){
    for($i = 0; $i < $postedSameCityCount; $i++){
        $sameStops[] = [
            'delivery_note' => ($dn !== '' ? $dn : 'DN') . '-SC' . ($i + 1),
            'party' => $party,
            'location' => $l
        ];
    }
}
if(count($outStops) === 0 && $postedOutCityCount > 0){
    for($i = 0; $i < $postedOutCityCount; $i++){
        $outStops[] = [
            'delivery_note' => ($dn !== '' ? $dn : 'DN') . '-OC' . ($i + 1),
            'party' => $party,
            'location' => $l
        ];
    }
}

$sameCityCount = count($sameStops);
$outCityCount = count($outStops);
$stops = 'SC:' . max(0, $sameCityCount) . '|OC:' . max(0, $outCityCount);
$postedTenderManualMode = (isset($_POST['tender_manual_mode']) && trim((string)$_POST['tender_manual_mode']) === '1') ? '1' : '0';
$isManualTender = auth_is_super_admin() && $postedTenderManualMode === '1';
$submittedTender = isset($_POST['tender']) ? max(0, round((float)$_POST['tender'], 3)) : 0.0;
$t = $submittedTender;
if(!$isManualTender && $t <= 0){
    $resolvedTender = resolve_haleeb_tender_rate_local($conn, $l, $vt);
    if($resolvedTender !== null && $resolvedTender > 0){
        $t = $resolvedTender;
    }
}
$f = isset($_POST['freight']) ? max(0, round((float)$_POST['freight'], 3)) : 0.0;
$commission = 0.0;
$freightPaymentType = 'paid';
$totalFreight = max(0, $f);

if($f <= 0 || $t <= 0){
    $_SESSION['add_haleeb_error'] = ($t <= 0 && !$isManualTender) ? 'tender_fetch_failed' : 'invalid_amounts';
    $_SESSION['add_haleeb_old'] = [
        'date' => isset($_POST['date']) ? (string)$_POST['date'] : $d,
        'vehicle' => isset($_POST['vehicle']) ? (string)$_POST['vehicle'] : $v,
        'vehicle_type' => isset($_POST['vehicle_type']) ? (string)$_POST['vehicle_type'] : $vt,
        'driver_phone_no' => isset($_POST['driver_phone_no']) ? (string)$_POST['driver_phone_no'] : $driverPhoneNo,
        'party' => isset($_POST['party']) ? (string)$_POST['party'] : $party,
        'location' => isset($_POST['location']) ? (string)$_POST['location'] : $l,
        'delivery_status' => isset($_POST['delivery_status']) ? (string)$_POST['delivery_status'] : $deliveryStatus,
        'token_no' => isset($_POST['token_no']) ? (string)$_POST['token_no'] : $tn,
        'delivery_note' => isset($_POST['delivery_note']) ? (string)$_POST['delivery_note'] : $dn,
        'tender' => isset($_POST['tender']) ? (string)$_POST['tender'] : (string)$submittedTender,
        'tender_manual_mode' => $postedTenderManualMode,
        'freight' => isset($_POST['freight']) ? (string)$_POST['freight'] : (string)$f
    ];
    header("location:add_haleeb_bilty.php");
    exit();
}

$p = $t - $totalFreight;
$addedByUserId = $currentUserId > 0 ? $currentUserId : null;
$ok = false;
$newId = 0;
$stopRowsCreated = 0;

$conn->begin_transaction();
try{
    $insertMain = insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $driverPhoneNo, $deliveryStatus, $dn, $tn, $party, $addedByUserId, $l, $stops, $f, $commission, $freightPaymentType, $t, $p);
    $ok = (bool)$insertMain[0];
    $newId = (int)$insertMain[1];
    if(!$ok || $newId <= 0){
        throw new RuntimeException('Could not insert main haleeb bilty row.');
    }

    foreach($sameStops as $idx => $stop){
        $stopDn = trim((string)($stop['delivery_note'] ?? ''));
        $stopParty = trim((string)($stop['party'] ?? ''));
        $stopLocation = trim((string)($stop['location'] ?? ''));
        if($stopDn === '') $stopDn = ($dn !== '' ? $dn : 'DN') . '-SC' . ($idx + 1);
        if($stopLocation === '') $stopLocation = $l;
        if($stopParty === '') $stopParty = $party;

        $stopTender = max(0, round(stop_tender_amount_local($vt, 'same'), 3));
        $stopFreight = 0.0;
        $stopCommission = 0.0;
        $stopProfit = $stopTender;
        $stopStops = 'SC:1|OC:0';

        $insertStop = insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $driverPhoneNo, $deliveryStatus, $stopDn, $tn, $stopParty, $addedByUserId, $stopLocation, $stopStops, $stopFreight, $stopCommission, $freightPaymentType, $stopTender, $stopProfit);
        $okStop = (bool)$insertStop[0];
        $stopId = (int)$insertStop[1];
        if(!$okStop || $stopId <= 0){
            throw new RuntimeException('Could not insert same-city stop row.');
        }
        $stopRowsCreated++;
    }

    foreach($outStops as $idx => $stop){
        $stopDn = trim((string)($stop['delivery_note'] ?? ''));
        $stopParty = trim((string)($stop['party'] ?? ''));
        $stopLocation = trim((string)($stop['location'] ?? ''));
        if($stopDn === '') $stopDn = ($dn !== '' ? $dn : 'DN') . '-OC' . ($idx + 1);
        if($stopLocation === '') $stopLocation = $l;
        if($stopParty === '') $stopParty = $party;

        $stopTender = max(0, round(stop_tender_amount_local($vt, 'out'), 3));
        $stopFreight = 0.0;
        $stopCommission = 0.0;
        $stopProfit = $stopTender;
        $stopStops = 'SC:0|OC:1';

        $insertStop = insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $driverPhoneNo, $deliveryStatus, $stopDn, $tn, $stopParty, $addedByUserId, $stopLocation, $stopStops, $stopFreight, $stopCommission, $freightPaymentType, $stopTender, $stopProfit);
        $okStop = (bool)$insertStop[0];
        $stopId = (int)$insertStop[1];
        if(!$okStop || $stopId <= 0){
            throw new RuntimeException('Could not insert out-city stop row.');
        }
        $stopRowsCreated++;
    }

    activity_notify_local(
        $conn,
        'haleeb',
        'bilty_added',
        'haleeb_bilty',
        $newId,
        'Haleeb bilty added.',
        [
            'token_no' => $tn,
            'vehicle' => $v,
            'driver_phone_no' => $driverPhoneNo,
            'delivery_status' => $deliveryStatus,
            'party' => $party,
            'added_by_user_id' => $addedByUserId,
            'freight' => $f,
            'freight_payment_type' => $freightPaymentType,
            'tender' => $t,
            'stop_rows_created' => $stopRowsCreated
        ],
        $currentUserId
    );
    $conn->commit();
} catch (Throwable $e){
    $conn->rollback();
    $ok = false;
    $newId = 0;
}

if($ok){
    $savedRef = $tn !== '' ? $tn : ('#' . $newId);
    $_SESSION['add_haleeb_success'] = 'Haleeb bilty ' . $savedRef . ' save ho gai.';
    header("location:add_haleeb_bilty.php");
    exit();
}

$_SESSION['add_haleeb_error'] = 'save_failed';
$_SESSION['add_haleeb_old'] = [
    'date' => isset($_POST['date']) ? (string)$_POST['date'] : $d,
    'vehicle' => isset($_POST['vehicle']) ? (string)$_POST['vehicle'] : $v,
    'vehicle_type' => isset($_POST['vehicle_type']) ? (string)$_POST['vehicle_type'] : $vt,
    'driver_phone_no' => isset($_POST['driver_phone_no']) ? (string)$_POST['driver_phone_no'] : $driverPhoneNo,
    'party' => isset($_POST['party']) ? (string)$_POST['party'] : $party,
    'location' => isset($_POST['location']) ? (string)$_POST['location'] : $l,
    'delivery_status' => isset($_POST['delivery_status']) ? (string)$_POST['delivery_status'] : $deliveryStatus,
    'token_no' => isset($_POST['token_no']) ? (string)$_POST['token_no'] : $tn,
    'delivery_note' => isset($_POST['delivery_note']) ? (string)$_POST['delivery_note'] : $dn,
    'tender' => isset($_POST['tender']) ? (string)$_POST['tender'] : (string)$submittedTender,
    'tender_manual_mode' => $postedTenderManualMode,
    'freight' => isset($_POST['freight']) ? (string)$_POST['freight'] : (string)$f
];
header("location:add_haleeb_bilty.php");
exit();
?>
