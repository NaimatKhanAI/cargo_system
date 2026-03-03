<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('feed');
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$sr = isset($_POST['sr_no']) ? trim((string)$_POST['sr_no']) : '';
$d = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
$v = isset($_POST['vehicle']) ? trim((string)$_POST['vehicle']) : '';
$b = isset($_POST['bilty']) ? trim((string)$_POST['bilty']) : '';
$party = isset($_POST['party']) ? trim((string)$_POST['party']) : '';
$l = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$bags = isset($_POST['bags']) ? max(0, (int)$_POST['bags']) : 0;
$f = isset($_POST['freight']) ? max(0, round((float)$_POST['freight'], 3)) : 0.0;
$commission = isset($_POST['commission']) ? max(0, round((float)$_POST['commission'], 3)) : 0.0;
$freightPaymentType = isset($_POST['freight_payment_type']) ? strtolower(trim((string)$_POST['freight_payment_type'])) : 'to_pay';
if(!in_array($freightPaymentType, ['to_pay', 'paid'], true)){
    $freightPaymentType = 'to_pay';
}
$feedPortion = normalize_feed_portion_local(isset($_POST['feed_portion']) ? (string)$_POST['feed_portion'] : '');
if(!auth_is_super_admin()){
    $feedPortion = auth_get_feed_portion();
}

$submittedTender = isset($_POST['tender']) ? (float)$_POST['tender'] : 0.0;
$baseTender = $submittedTender;
if(isset($_POST['tender_raw']) && trim((string)$_POST['tender_raw']) !== ''){
    $baseTender = (float)$_POST['tender_raw'];
}
if($baseTender < 0){
    $baseTender = 0.0;
}

$baseBags = 200;
$bags = max(0, $bags);
$scaledTender = ($bags > 0) ? (($baseTender / $baseBags) * $bags) : 0.0;
$t = ($bags > 300) ? round($scaledTender * 0.90, 3) : round($scaledTender, 3);
$totalFreight = max(0, $f - $commission);

$p = $t - $totalFreight;
$addedByUserId = $currentUserId > 0 ? $currentUserId : null;

$stmt = $conn->prepare("INSERT INTO bilty(sr_no, date, vehicle, bilty_no, party, feed_portion, added_by_user_id, location, bags, freight, commission, freight_payment_type, original_freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssisiddsddd", $sr, $d, $v, $b, $party, $feedPortion, $addedByUserId, $l, $bags, $f, $commission, $freightPaymentType, $totalFreight, $t, $p);
$ok = $stmt->execute();
$newId = (int)$stmt->insert_id;
$stmt->close();

if($ok && $freightPaymentType === 'to_pay' && auth_can_direct_modify() && $totalFreight > 0){
    $entryDate = $d !== '' ? $d : date('Y-m-d');
    $entryCategory = 'feed';
    $entryMode = 'account';
    $entryNote = 'Auto Driver Payment - Feed Bilty ' . ($b !== '' ? $b : ('#' . $newId));
    $autoPay = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'debit', ?, ?, NULL, ?, ?)");
    $autoPay->bind_param("sssids", $entryDate, $entryCategory, $entryMode, $newId, $totalFreight, $entryNote);
    $autoPayOk = $autoPay->execute();
    $autoPay->close();
    if($autoPayOk){
        $paidType = 'paid';
        $markPaid = $conn->prepare("UPDATE bilty SET freight_payment_type=? WHERE id=?");
        $markPaid->bind_param("si", $paidType, $newId);
        $markPaid->execute();
        $markPaid->close();
        $freightPaymentType = 'paid';
    }
}

if($ok){
    activity_notify_local(
        $conn,
        'feed',
        'bilty_added',
        'bilty',
        $newId,
        'Feed bilty added.',
        [
            'sr_no' => $sr,
            'date' => $d,
            'bilty_no' => $b,
            'vehicle' => $v,
            'party' => $party,
            'feed_portion' => $feedPortion,
            'added_by_user_id' => $addedByUserId,
            'location' => $l,
            'bags' => $bags,
            'freight' => $f,
            'commission' => $commission,
            'freight_payment_type' => $freightPaymentType,
            'tender' => $t
        ],
        $currentUserId
    );
}

header("location:feed.php");
?>

