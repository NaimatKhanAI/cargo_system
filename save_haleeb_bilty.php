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

function insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $dn, $tn, $party, $addedByUserId, $l, $stops, $f, $commission, $freightPaymentType, $t, $p){
    $stmt = $conn->prepare("INSERT INTO haleeb_bilty(date, vehicle, vehicle_type, delivery_note, token_no, party, added_by_user_id, location, stops, freight, commission, freight_payment_type, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if(!$stmt) return [false, 0];
    $stmt->bind_param("ssssssissddsdd", $d, $v, $vt, $dn, $tn, $party, $addedByUserId, $l, $stops, $f, $commission, $freightPaymentType, $t, $p);
    $ok = $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();
    return [$ok, $newId];
}

$d = isset($_POST['date']) ? trim((string)$_POST['date']) : date('Y-m-d');
$v = isset($_POST['vehicle']) ? trim((string)$_POST['vehicle']) : '';
$vt = isset($_POST['vehicle_type']) ? trim((string)$_POST['vehicle_type']) : '';
$dn = isset($_POST['delivery_note']) ? trim((string)$_POST['delivery_note']) : '';
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
$t = isset($_POST['tender']) ? max(0, round((float)$_POST['tender'], 3)) : 0.0;
$f = isset($_POST['freight']) ? max(0, round((float)$_POST['freight'], 3)) : 0.0;
$commission = isset($_POST['commission']) ? max(0, round((float)$_POST['commission'], 3)) : 0.0;
$freightPaymentType = 'paid';
$totalFreight = max(0, $f - $commission);

$p = $t - $totalFreight;
$addedByUserId = $currentUserId > 0 ? $currentUserId : null;
$ok = false;
$newId = 0;
$stopRowsCreated = 0;

$conn->begin_transaction();
try{
    $insertMain = insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $dn, $tn, $party, $addedByUserId, $l, $stops, $f, $commission, $freightPaymentType, $t, $p);
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

        $insertStop = insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $stopDn, $tn, $stopParty, $addedByUserId, $stopLocation, $stopStops, $stopFreight, $stopCommission, $freightPaymentType, $stopTender, $stopProfit);
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

        $insertStop = insert_haleeb_bilty_row_local($conn, $d, $v, $vt, $stopDn, $tn, $stopParty, $addedByUserId, $stopLocation, $stopStops, $stopFreight, $stopCommission, $freightPaymentType, $stopTender, $stopProfit);
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
            'party' => $party,
            'added_by_user_id' => $addedByUserId,
            'freight' => $f,
            'commission' => $commission,
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

header("location:haleeb.php");
?>
