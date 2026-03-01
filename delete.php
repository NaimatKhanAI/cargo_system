<?php
session_start();
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('feed');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id > 0){
if(!auth_can_direct_modify()){
$requestId = create_change_request_local($conn, 'feed', 'bilty', $id, 'feed_delete', ['id' => $id], isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
if($requestId > 0){
header("location:feed.php?req=submitted");
exit();
}
header("location:feed.php?req=failed");
exit();
}
$conn->begin_transaction();
try{
$biltyStmt = $conn->prepare("SELECT bilty_no FROM bilty WHERE id=? LIMIT 1");
$biltyStmt->bind_param("i", $id);
$biltyStmt->execute();
$biltyRow = $biltyStmt->get_result()->fetch_assoc();
$biltyStmt->close();

$entriesStmt = $conn->prepare("SELECT entry_date, category, amount_mode, amount FROM account_entries WHERE bilty_id=? AND entry_type='debit'");
$entriesStmt->bind_param("i", $id);
$entriesStmt->execute();
$entriesRes = $entriesStmt->get_result();

$insReturn = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'credit', ?, NULL, NULL, ?, ?)");
$today = date('Y-m-d');
$biltyNo = $biltyRow && isset($biltyRow['bilty_no']) ? (string)$biltyRow['bilty_no'] : (string)$id;

while($entriesRes && $r = $entriesRes->fetch_assoc()){
$entryDate = $today;
$category = isset($r['category']) ? (string)$r['category'] : 'feed';
$amountMode = isset($r['amount_mode']) && $r['amount_mode'] !== '' ? (string)$r['amount_mode'] : 'cash';
$amount = isset($r['amount']) ? (float)$r['amount'] : 0;
if($amount <= 0){
continue;
}
$note = "Auto Return - Deleted Feed Bilty " . $biltyNo;
$insReturn->bind_param("sssds", $entryDate, $category, $amountMode, $amount, $note);
$insReturn->execute();
}
$insReturn->close();
$entriesStmt->close();

$delStmt = $conn->prepare("DELETE FROM bilty WHERE id=?");
$delStmt->bind_param("i", $id);
$delStmt->execute();
$deleted = $delStmt->affected_rows > 0;
$delStmt->close();

$conn->commit();
if($deleted){
activity_notify_local(
$conn,
'feed',
'bilty_deleted_direct',
'bilty',
$id,
'Feed bilty deleted directly.',
['bilty_no' => $biltyNo],
isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
);
}
} catch (Throwable $e){
$conn->rollback();
}
}
header("location:feed.php");
?>
