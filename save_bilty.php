<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';
require_once 'config/activity_notifications.php';
require_once 'config/change_requests.php';
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

if($d !== '' && $b !== ''){
    $dupStmt = $conn->prepare("SELECT id FROM bilty WHERE date=? AND bilty_no=? LIMIT 1");
    $dupStmt->bind_param("ss", $d, $b);
    $dupStmt->execute();
    $dupRow = $dupStmt->get_result()->fetch_assoc();
    $dupStmt->close();

    if($dupRow){
        $_SESSION['add_bilty_error'] = 'duplicate_bilty_same_date';
        $_SESSION['add_bilty_old'] = [
            'sr_no' => $sr,
            'date' => $d,
            'vehicle' => $v,
            'bilty' => $b,
            'party' => $party,
            'location' => $l,
            'bags' => isset($_POST['bags']) ? (string)$_POST['bags'] : '0',
            'freight' => isset($_POST['freight']) ? (string)$_POST['freight'] : '0',
            'commission' => isset($_POST['commission']) ? (string)$_POST['commission'] : '0',
            'freight_payment_type' => $freightPaymentType,
            'tender' => isset($_POST['tender']) ? (string)$_POST['tender'] : '0',
            'tender_raw' => isset($_POST['tender_raw']) ? (string)$_POST['tender_raw'] : '',
            'feed_portion' => $feedPortion
        ];
        header("location:add_bilty.php");
        exit();
    }
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

if($ok && $freightPaymentType === 'to_pay' && auth_can_direct_modify('feed') && $totalFreight > 0){
    $entryDate = $d !== '' ? $d : date('Y-m-d');
    $entryCategory = 'feed';
    $entryMode = 'account';
    $entryNote = 'Auto Driver Payment - Feed Bilty ' . ($b !== '' ? $b : ('#' . $newId));
    $autoPay = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'debit', ?, ?, NULL, ?, ?)");
    $autoPay->bind_param("sssids", $entryDate, $entryCategory, $entryMode, $newId, $totalFreight, $entryNote);
    $autoPay->execute();
    $autoPay->close();
}
if($ok && $freightPaymentType === 'to_pay' && !auth_can_direct_modify('feed') && $totalFreight > 0){
    $entryDate = $d !== '' ? $d : date('Y-m-d');
    $entryNote = 'Auto Driver Payment Request - Feed Bilty ' . ($b !== '' ? $b : ('#' . $newId));
    $payload = [
        'entry_date' => $entryDate,
        'category' => 'feed',
        'amount_mode' => 'account',
        'amount' => round($totalFreight, 3),
        'note' => $entryNote
    ];
    create_change_request_local($conn, 'feed', 'bilty', $newId, 'feed_pay', $payload, $currentUserId);
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

if($ok){
    $savedRef = $b !== '' ? $b : ('#' . $newId);
    $_SESSION['add_bilty_success'] = 'Bilty ' . $savedRef . ' save ho gai.';
    $redirect = "add_bilty.php";
    if(auth_is_super_admin() && $feedPortion !== ''){
        $redirect .= "?portion=" . rawurlencode($feedPortion);
    }
    header("location:" . $redirect);
    exit();
}

$_SESSION['add_bilty_error'] = 'save_failed';
$_SESSION['add_bilty_old'] = [
    'sr_no' => $sr,
    'date' => $d,
    'vehicle' => $v,
    'bilty' => $b,
    'party' => $party,
    'location' => $l,
    'bags' => isset($_POST['bags']) ? (string)$_POST['bags'] : '0',
    'freight' => isset($_POST['freight']) ? (string)$_POST['freight'] : '0',
    'commission' => isset($_POST['commission']) ? (string)$_POST['commission'] : '0',
    'freight_payment_type' => $freightPaymentType,
    'tender' => isset($_POST['tender']) ? (string)$_POST['tender'] : '0',
    'tender_raw' => isset($_POST['tender_raw']) ? (string)$_POST['tender_raw'] : '',
    'feed_portion' => $feedPortion
];
$redirect = "add_bilty.php";
if(auth_is_super_admin() && $feedPortion !== ''){
    $redirect .= "?portion=" . rawurlencode($feedPortion);
}
header("location:" . $redirect);
exit();
?>

