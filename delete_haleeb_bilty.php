<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id > 0){
if(!auth_can_direct_modify('haleeb')){
$requestId = create_change_request_local($conn, 'haleeb', 'haleeb_bilty', $id, 'haleeb_delete', ['id' => $id], isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
if($requestId > 0){
header("location:haleeb.php?req=submitted");
exit();
}
header("location:haleeb.php?req=failed");
exit();
}
$conn->begin_transaction();
try{
$biltyStmt = $conn->prepare("SELECT token_no FROM haleeb_bilty WHERE id=? LIMIT 1");
$biltyStmt->bind_param("i", $id);
$biltyStmt->execute();
$biltyRow = $biltyStmt->get_result()->fetch_assoc();
$biltyStmt->close();

$entriesStmt = $conn->prepare("SELECT id, note, amount FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit'");
$entriesStmt->bind_param("i", $id);
$entriesStmt->execute();
$entriesRes = $entriesStmt->get_result();

$tokenNo = $biltyRow && isset($biltyRow['token_no']) ? (string)$biltyRow['token_no'] : (string)$id;
$updEntry = $conn->prepare("UPDATE account_entries SET amount=0, note=? WHERE id=? AND entry_type='debit'");

while($entriesRes && $r = $entriesRes->fetch_assoc()){
$amount = isset($r['amount']) ? (float)$r['amount'] : 0;
if($amount <= 0){
continue;
}
$entryId = isset($r['id']) ? (int)$r['id'] : 0;
if($entryId <= 0){
continue;
}
$oldNote = isset($r['note']) ? trim((string)$r['note']) : '';
$baseNote = $oldNote !== '' ? $oldNote : ("Auto Driver Payment Request - Haleeb Token " . $tokenNo);
$note = $baseNote . " | Reversed on Delete - Haleeb Token " . $tokenNo;
$updEntry->bind_param("si", $note, $entryId);
$updEntry->execute();
}
$updEntry->close();
$entriesStmt->close();

$delStmt = $conn->prepare("DELETE FROM haleeb_bilty WHERE id=?");
$delStmt->bind_param("i", $id);
$delStmt->execute();
$deleted = $delStmt->affected_rows > 0;
$delStmt->close();

$conn->commit();
if($deleted){
activity_notify_local(
$conn,
'haleeb',
'bilty_deleted_direct',
'haleeb_bilty',
$id,
'Haleeb bilty deleted directly.',
['token_no' => $tokenNo],
isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
);
}
} catch (Throwable $e){
$conn->rollback();
}
}
header("location:haleeb.php");
?>
