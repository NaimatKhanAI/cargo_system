<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('haleeb');

$d = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
$v = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
$vt = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : '';
$dn = isset($_POST['delivery_note']) ? trim($_POST['delivery_note']) : '';
$tn = isset($_POST['token_no']) ? trim($_POST['token_no']) : '';
$party = isset($_POST['party']) ? trim($_POST['party']) : '';
$l = isset($_POST['location']) ? trim($_POST['location']) : '';
$sameCityCount = isset($_POST['same_city_count']) ? (int)$_POST['same_city_count'] : 0;
$outCityCount = isset($_POST['out_city_count']) ? (int)$_POST['out_city_count'] : 0;
$stops = 'SC:' . max(0, $sameCityCount) . '|OC:' . max(0, $outCityCount);
$t = isset($_POST['tender']) ? (int)$_POST['tender'] : 0;
$f = isset($_POST['freight']) ? (int)$_POST['freight'] : 0;
$commission = isset($_POST['commission']) ? max(0, (int)$_POST['commission']) : 0;
$freightPaymentType = isset($_POST['freight_payment_type']) ? strtolower(trim((string)$_POST['freight_payment_type'])) : 'to_pay';
if(!in_array($freightPaymentType, ['to_pay', 'paid'], true)){
    $freightPaymentType = 'to_pay';
}
if(!auth_can_direct_modify()){
    $freightPaymentType = 'to_pay';
}
$totalFreight = max(0, $f - $commission);

$p = $t - $totalFreight;

$stmt = $conn->prepare("INSERT INTO haleeb_bilty(date, vehicle, vehicle_type, delivery_note, token_no, party, location, stops, freight, commission, freight_payment_type, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssssiisii", $d, $v, $vt, $dn, $tn, $party, $l, $stops, $f, $commission, $freightPaymentType, $t, $p);
$ok = $stmt->execute();
$newId = (int)$stmt->insert_id;
$stmt->close();

if($ok && $freightPaymentType === 'paid' && auth_can_direct_modify() && $totalFreight > 0){
    $entryDate = $d !== '' ? $d : date('Y-m-d');
    $entryCategory = 'haleeb';
    $entryMode = 'account';
    $entryNote = 'Auto Driver Payment - Haleeb Token ' . ($tn !== '' ? $tn : ('#' . $newId));
    $autoPay = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'debit', ?, NULL, ?, ?, ?)");
    $autoPay->bind_param("sssids", $entryDate, $entryCategory, $entryMode, $newId, $totalFreight, $entryNote);
    $autoPay->execute();
    $autoPay->close();
}

if($ok){
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
            'freight' => $f,
            'commission' => $commission,
            'freight_payment_type' => $freightPaymentType,
            'tender' => $t
        ],
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
    );
}

header("location:haleeb.php");
?>
