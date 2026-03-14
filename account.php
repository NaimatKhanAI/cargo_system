<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('account');
$canManageUsers = auth_can_manage_users();
$canReviewActivity = auth_can_review_activity();
$canManageLedger = auth_can_direct_modify('account');
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

function exec_prepared_result_local($conn, $sql, $types = '', $values = []){
    $stmt = $conn->prepare($sql);
    if(!$stmt) return [null, false];
    $count = count($values);
    if($count > 0){
        $bindTypes = (string)$types;
        if(strlen($bindTypes) !== $count){
            $stmt->close();
            return [null, false];
        }
        $bindArgs = [];
        $bindArgs[] = &$bindTypes;
        foreach($values as $idx => $val){
            $bindArgs[] = &$values[$idx];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    return [$stmt, $res];
}

function bilty_detail_link_local($moduleType, $entityId, $source = 'account'){
    $moduleType = strtolower(trim((string)$moduleType));
    $entityId = (int)$entityId;
    if($entityId <= 0) return '';
    if(!in_array($moduleType, ['feed', 'haleeb'], true)) return '';
    return 'bilty_detail.php?type=' . rawurlencode($moduleType) . '&id=' . $entityId . '&src=' . rawurlencode((string)$source);
}

function payment_request_remaining_local($conn, $actionType, $entityId, &$error = ''){
    static $cache = [];
    $error = '';
    $actionType = trim((string)$actionType);
    $entityId = (int)$entityId;
    $cacheKey = $actionType . '|' . $entityId;
    if(isset($cache[$cacheKey])){
        return (float)$cache[$cacheKey];
    }
    if($entityId <= 0){
        $error = 'Invalid request entity.';
        return 0.0;
    }

    if($actionType === 'feed_pay'){
        $freightStmt = $conn->prepare("SELECT COALESCE(original_freight, freight) AS freight_total FROM bilty WHERE id=? LIMIT 1");
        $freightStmt->bind_param("i", $entityId);
        $freightStmt->execute();
        $freightRow = $freightStmt->get_result()->fetch_assoc();
        $freightStmt->close();
        if(!$freightRow){
            $error = 'Linked feed bilty not found.';
            return 0.0;
        }

        $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE bilty_id=? AND entry_type='debit'");
        $paidStmt->bind_param("i", $entityId);
        $paidStmt->execute();
        $paidRow = $paidStmt->get_result()->fetch_assoc();
        $paidStmt->close();
        $remaining = max(0, (float)$freightRow['freight_total'] - (float)($paidRow['paid_total'] ?? 0));
        $cache[$cacheKey] = $remaining;
        return (float)$remaining;
    }

    if($actionType === 'haleeb_pay'){
        $freightStmt = $conn->prepare("SELECT freight AS freight_total FROM haleeb_bilty WHERE id=? LIMIT 1");
        $freightStmt->bind_param("i", $entityId);
        $freightStmt->execute();
        $freightRow = $freightStmt->get_result()->fetch_assoc();
        $freightStmt->close();
        if(!$freightRow){
            $error = 'Linked haleeb bilty not found.';
            return 0.0;
        }

        $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit'");
        $paidStmt->bind_param("i", $entityId);
        $paidStmt->execute();
        $paidRow = $paidStmt->get_result()->fetch_assoc();
        $paidStmt->close();
        $remaining = max(0, (float)$freightRow['freight_total'] - (float)($paidRow['paid_total'] ?? 0));
        $cache[$cacheKey] = $remaining;
        return (float)$remaining;
    }

    $error = 'Unsupported payment request type.';
    return 0.0;
}

function ledger_category_label_local($categoryKey, $labels){
    $categoryKey = strtolower(trim((string)$categoryKey));
    if(isset($labels[$categoryKey])) return (string)$labels[$categoryKey];
    if($categoryKey === '') return '';
    return ucwords(str_replace('_', ' ', $categoryKey));
}

$categoryLabels = [
    'feed' => 'Feed',
    'feed_ilyas' => 'Feed - Ilyas',
    'feed_hamid' => 'Feed - Hamid',
    'feed_amir' => 'Feed - Amir',
    'haleeb' => 'Haleeb',
    'prime_pump' => 'Prime Pump',
    'loan' => 'Loan',
];
$allowedCategories = array_keys($categoryLabels);
$feedCategoryGroup = ['feed', 'feed_ilyas', 'feed_hamid', 'feed_amir'];
$allowedTypes = ['debit', 'credit'];
$allowedModes = ['cash', 'account'];
$msg = ''; $err = '';
$formEntryDate = date('Y-m-d');
$formCategory = 'feed'; $formEntryType = 'debit'; $formAmountMode = 'cash';
$formAmount = ''; $formNote = '';
$primeFormDate = date('Y-m-d');
$primeCardAmount = '';
$primeRecoveryAmount = '';
$primeFormNote = '';
$editingId = 0;
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$feedSectionParam = isset($_GET['feed_section']) ? strtolower(trim((string)$_GET['feed_section'])) : '';
$feedSectionParam = $feedSectionParam === 'hameed' ? 'hamid' : $feedSectionParam;
$feedSectionKey = '';
if($feedSectionParam !== ''){
    $feedOptions = feed_portion_options_local();
    if(isset($feedOptions[$feedSectionParam])){
        $feedSectionKey = $feedSectionParam;
    } else {
        foreach($feedOptions as $portionKey => $portionLabel){
            if(strtolower((string)$portionLabel) === $feedSectionParam){
                $feedSectionKey = $portionKey;
                break;
            }
        }
    }
}
$feedSectionLabel = $feedSectionKey !== '' ? feed_portion_label_local($feedSectionKey) : '';

if(isset($_POST['approve_pay_request']) || isset($_POST['reject_pay_request'])){
    if(!$canManageLedger){
        $err = 'Account ledger is view-only for your account.';
    } else {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $reviewNote = isset($_POST['review_note']) ? trim((string)$_POST['review_note']) : '';
        $isApprove = isset($_POST['approve_pay_request']);
        $reviewError = '';

        if($isApprove){
            $requestRow = fetch_pending_change_request_by_id_local($conn, $requestId);
            if(!$requestRow){
                $err = 'Request not found or already reviewed.';
            } else {
                $actionType = isset($requestRow['action_type']) ? (string)$requestRow['action_type'] : '';
                if(!in_array($actionType, ['feed_pay', 'haleeb_pay'], true)){
                    $err = 'Invalid payment request type.';
                } else {
                    $payload = request_payload_decode_local(isset($requestRow['payload']) ? (string)$requestRow['payload'] : '');
                    $rawAmount = isset($_POST['request_amount']) ? trim((string)$_POST['request_amount']) : '';
                    $rawAmount = str_replace(',', '', $rawAmount);
                    $updatedAmount = $rawAmount !== '' ? (float)$rawAmount : (float)($payload['amount'] ?? 0);
                    if($updatedAmount <= 0){
                        $err = 'Amount must be greater than 0.';
                    } else {
                        $remainingError = '';
                        $remaining = payment_request_remaining_local(
                            $conn,
                            $actionType,
                            isset($requestRow['entity_id']) ? (int)$requestRow['entity_id'] : 0,
                            $remainingError
                        );
                        if($remainingError !== ''){
                            $err = $remainingError;
                        } elseif($updatedAmount > $remaining){
                            $err = 'Amount exceeds current remaining balance.';
                        } else {
                            $currentAmount = (float)($payload['amount'] ?? 0);
                            if(abs($updatedAmount - $currentAmount) > 0.0001){
                                $payload['amount'] = round($updatedAmount, 2);
                                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
                                if($payloadJson === false){
                                    $err = 'Could not update request amount.';
                                } else {
                                    $upd = $conn->prepare("UPDATE change_requests SET payload=? WHERE id=? AND status='pending'");
                                    $upd->bind_param("si", $payloadJson, $requestId);
                                    $updOk = $upd->execute();
                                    $updAffected = $upd->affected_rows;
                                    $upd->close();
                                    if(!$updOk || $updAffected <= 0){
                                        $err = 'Could not save updated amount.';
                                    } elseif($reviewNote === ''){
                                        $reviewNote = 'Amount updated by account admin before approval.';
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $ok = false;
        if($err === ''){
            $ok = review_change_request_local($conn, $requestId, $currentUserId, $isApprove, $reviewNote, $reviewError, ['feed_pay', 'haleeb_pay']);
        }
        if($ok){
            $msg = $isApprove ? 'Payment request approved.' : 'Payment request rejected.';
        } else {
            if($err === ''){
                $err = $reviewError !== '' ? $reviewError : 'Payment request review failed.';
            }
        }
    }
}

if(isset($_POST['add_prime_card']) || isset($_POST['add_prime_recovery'])){
    $isPrimeCard = isset($_POST['add_prime_card']);
    $primeFormDate = isset($_POST['prime_entry_date']) ? trim((string)$_POST['prime_entry_date']) : date('Y-m-d');
    $primeFormNote = isset($_POST['prime_note']) ? trim((string)$_POST['prime_note']) : '';
    $rawCardAmount = isset($_POST['prime_card_amount']) ? trim((string)$_POST['prime_card_amount']) : '';
    $rawRecoveryAmount = isset($_POST['prime_recovery_amount']) ? trim((string)$_POST['prime_recovery_amount']) : '';
    $primeCardAmount = $rawCardAmount;
    $primeRecoveryAmount = $rawRecoveryAmount;

    $amountRaw = $isPrimeCard ? $rawCardAmount : $rawRecoveryAmount;
    $amountRaw = str_replace(',', '', $amountRaw);
    $amount = $amountRaw !== '' ? (float)$amountRaw : 0.0;

    if(!$canManageLedger){
        $err = 'Account ledger is view-only for your account.';
    } elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $primeFormDate)){
        $err = 'Invalid Prime entry date.';
    } elseif($amount <= 0){
        $err = 'Prime amount must be greater than 0.';
    } else {
        $entryType = $isPrimeCard ? 'credit' : 'debit';
        $amountMode = $isPrimeCard ? 'account' : 'cash';
        $notePrefix = $isPrimeCard ? 'Prime Pump Card Issued' : 'Prime Pump Recovery';
        $entryNote = $primeFormNote !== '' ? ($notePrefix . ' - ' . $primeFormNote) : $notePrefix;

        $stmt = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, amount, note) VALUES(?, 'prime_pump', ?, ?, ?, ?)");
        $stmt->bind_param("sssds", $primeFormDate, $entryType, $amountMode, $amount, $entryNote);
        $ok = $stmt->execute();
        $entryId = (int)$stmt->insert_id;
        $stmt->close();

        if($ok){
            activity_notify_local(
                $conn,
                'account',
                $isPrimeCard ? 'prime_card_recorded' : 'prime_recovery_recorded',
                'account_entry',
                $entryId,
                $isPrimeCard ? 'Prime pump card entry recorded.' : 'Prime pump recovery entry recorded.',
                [
                    'entry_date' => $primeFormDate,
                    'category' => 'prime_pump',
                    'entry_type' => $entryType,
                    'amount_mode' => $amountMode,
                    'amount' => $amount,
                    'note' => $entryNote
                ],
                $currentUserId
            );
            $msg = $isPrimeCard ? 'Prime card entry added.' : 'Prime recovery entry added.';
            if($isPrimeCard){
                $primeCardAmount = '';
            } else {
                $primeRecoveryAmount = '';
            }
            $primeFormNote = '';
        } else {
            $err = 'Could not save Prime entry.';
        }
    }
}

if(isset($_GET['delete_id'])){
    $deleteId = (int)$_GET['delete_id'];
    if($deleteId > 0){
        if(!$canManageLedger){
            $err = 'Account ledger is view-only for your account.';
        } else {
            $rowStmt = $conn->prepare("SELECT entry_date, category, entry_type, amount_mode, amount, note FROM account_entries WHERE id=? LIMIT 1");
            $rowStmt->bind_param("i", $deleteId);
            $rowStmt->execute();
            $deleteRow = $rowStmt->get_result()->fetch_assoc();
            $rowStmt->close();

            $deleteStmt = $conn->prepare("DELETE FROM account_entries WHERE id=?");
            $deleteStmt->bind_param("i", $deleteId);
            $deleteStmt->execute();
            $ok = $deleteStmt->affected_rows > 0;
            $deleteStmt->close();
            if($ok){
                activity_notify_local(
                    $conn,
                    'account',
                    'ledger_entry_deleted',
                    'account_entry',
                    $deleteId,
                    'Account entry deleted by ledger admin.',
                    $deleteRow ?: ['id' => $deleteId],
                    $currentUserId
                );
                $msg = 'Entry deleted.';
            }
        }
    }
}

if(isset($_POST['update_entry'])){
    $editingId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
    $entryDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : date('Y-m-d');
    $category = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : '';
    $entryType = isset($_POST['entry_type']) ? strtolower(trim($_POST['entry_type'])) : '';
    $amountMode = isset($_POST['amount_mode']) ? strtolower(trim($_POST['amount_mode'])) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $formEntryDate = $entryDate; $formCategory = $category; $formEntryType = $entryType;
    $formAmountMode = $amountMode; $formAmount = $amount > 0 ? (string)$amount : ''; $formNote = $note;

    if(!$canManageLedger) $err = 'Account ledger is view-only for your account.';
    elseif($editingId <= 0) $err = 'Invalid entry id.';
    elseif(!in_array($category, $allowedCategories, true)) $err = 'Invalid category.';
    elseif(!in_array($entryType, $allowedTypes, true)) $err = 'Invalid entry type.';
    elseif(!in_array($amountMode, $allowedModes, true)) $err = 'Invalid amount mode.';
    elseif($amount <= 0) $err = 'Amount must be greater than 0.';
    else {
        $canUpdate = true;
        $oldStmt = $conn->prepare("SELECT entry_date, category, entry_type, amount_mode, amount, note FROM account_entries WHERE id=? LIMIT 1");
        $oldStmt->bind_param("i", $editingId);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();

        $linkStmt = $conn->prepare("SELECT bilty_id, haleeb_bilty_id, entry_type FROM account_entries WHERE id=? LIMIT 1");
        $linkStmt->bind_param("i", $editingId);
        $linkStmt->execute();
        $linkRes = $linkStmt->get_result()->fetch_assoc();
        $linkStmt->close();

        if($linkRes && isset($linkRes['bilty_id']) && (int)$linkRes['bilty_id'] > 0 && strtolower((string)$linkRes['entry_type']) === 'debit'){
            $biltyId = (int)$linkRes['bilty_id'];
            $freightStmt = $conn->prepare("SELECT COALESCE(original_freight, freight) AS freight_total FROM bilty WHERE id=? LIMIT 1");
            $freightStmt->bind_param("i", $biltyId); $freightStmt->execute();
            $freightRes = $freightStmt->get_result()->fetch_assoc(); $freightStmt->close();
            if(!$freightRes){ $err = 'Linked bilty not found.'; $canUpdate = false; }
            else {
                $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE bilty_id=? AND entry_type='debit' AND id<>?");
                $paidStmt->bind_param("ii", $biltyId, $editingId); $paidStmt->execute();
                $paidRes = $paidStmt->get_result()->fetch_assoc(); $paidStmt->close();
                $maxAllowed = max(0, (float)$freightRes['freight_total'] - ($paidRes['paid_total'] ?? 0));
                if((float)$amount > $maxAllowed){ $err = 'Amount exceeds bilty remaining balance.'; $canUpdate = false; }
            }
        } elseif($linkRes && isset($linkRes['haleeb_bilty_id']) && (int)$linkRes['haleeb_bilty_id'] > 0 && strtolower((string)$linkRes['entry_type']) === 'debit'){
            $haleebBiltyId = (int)$linkRes['haleeb_bilty_id'];
            $freightStmt = $conn->prepare("SELECT freight AS freight_total FROM haleeb_bilty WHERE id=? LIMIT 1");
            $freightStmt->bind_param("i", $haleebBiltyId); $freightStmt->execute();
            $freightRes = $freightStmt->get_result()->fetch_assoc(); $freightStmt->close();
            if(!$freightRes){ $err = 'Linked haleeb bilty not found.'; $canUpdate = false; }
            else {
                $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit' AND id<>?");
                $paidStmt->bind_param("ii", $haleebBiltyId, $editingId); $paidStmt->execute();
                $paidRes = $paidStmt->get_result()->fetch_assoc(); $paidStmt->close();
                $maxAllowed = max(0, (float)$freightRes['freight_total'] - ($paidRes['paid_total'] ?? 0));
                if((float)$amount > $maxAllowed){ $err = 'Amount exceeds haleeb bilty remaining balance.'; $canUpdate = false; }
            }
        }

        if($canUpdate){
            $stmt = $conn->prepare("UPDATE account_entries SET entry_date=?, category=?, entry_type=?, amount_mode=?, amount=?, note=? WHERE id=?");
            $stmt->bind_param("ssssdsi", $entryDate, $category, $entryType, $amountMode, $amount, $note, $editingId);
            $stmt->execute(); $stmt->close();
            activity_notify_local(
                $conn,
                'account',
                'ledger_entry_updated',
                'account_entry',
                $editingId,
                'Account entry updated by ledger admin.',
                [
                    'old' => $oldRow ?: [],
                    'new' => [
                        'entry_date' => $entryDate,
                        'category' => $category,
                        'entry_type' => $entryType,
                        'amount_mode' => $amountMode,
                        'amount' => $amount,
                        'note' => $note
                    ]
                ],
                $currentUserId
            );
            $msg = 'Entry updated.'; $editingId = 0;
            $formEntryDate = date('Y-m-d'); $formCategory = 'feed'; $formEntryType = 'debit';
            $formAmountMode = 'cash'; $formAmount = ''; $formNote = '';
        }
    }
}

if(isset($_POST['add_entry'])){
    $entryDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : date('Y-m-d');
    $category = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : '';
    $entryType = isset($_POST['entry_type']) ? strtolower(trim($_POST['entry_type'])) : '';
    $amountMode = isset($_POST['amount_mode']) ? strtolower(trim($_POST['amount_mode'])) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    if(!$canManageLedger) $err = 'Account ledger is view-only for your account.';
    elseif(!in_array($category, $allowedCategories, true)) $err = 'Invalid category.';
    elseif(!in_array($entryType, $allowedTypes, true)) $err = 'Invalid entry type.';
    elseif(!in_array($amountMode, $allowedModes, true)) $err = 'Invalid amount mode.';
    elseif($amount <= 0) $err = 'Amount must be greater than 0.';
    else {
        $stmt = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, amount, note) VALUES(?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssds", $entryDate, $category, $entryType, $amountMode, $amount, $note);
        $ok = $stmt->execute();
        $entryId = $stmt->insert_id;
        $stmt->close();
        if($ok){
            activity_notify_local(
                $conn,
                'account',
                'ledger_entry_added',
                'account_entry',
                (int)$entryId,
                'New account ledger entry created.',
                [
                    'entry_date' => $entryDate,
                    'category' => $category,
                    'entry_type' => $entryType,
                    'amount_mode' => $amountMode,
                    'amount' => $amount,
                    'note' => $note
                ],
                $currentUserId
            );
            $msg = 'Entry saved.';
        } else {
            $err = 'Could not save entry.';
        }
    }
}

$cat = isset($_GET['cat']) ? strtolower($_GET['cat']) : 'all';
if(!in_array($cat, array_merge(['all'], $allowedCategories), true)) $cat = 'all';

$where = []; $bindTypes = ''; $bindValues = [];
if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){ $where[] = "entry_date >= ?"; $bindTypes .= 's'; $bindValues[] = $dateFrom; }
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){ $where[] = "entry_date <= ?"; $bindTypes .= 's'; $bindValues[] = $dateTo; }
if($cat === 'feed'){
    $feedGroup = array_values(array_intersect($feedCategoryGroup, $allowedCategories));
    if(count($feedGroup) > 0){
        $where[] = "category IN (" . implode(',', array_fill(0, count($feedGroup), '?')) . ")";
        $bindTypes .= str_repeat('s', count($feedGroup));
        foreach($feedGroup as $feedCategory) $bindValues[] = $feedCategory;
    }
} elseif($cat !== 'all'){
    $where[] = "category = ?";
    $bindTypes .= 's';
    $bindValues[] = $cat;
}
if($feedSectionKey !== ''){
    $where[] = "account_entries.bilty_id IN (SELECT id FROM bilty WHERE feed_portion = ?)";
    $bindTypes .= 's';
    $bindValues[] = $feedSectionKey;
}
$whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';
$dateQueryTail = '';
if($dateFrom !== '') $dateQueryTail .= '&date_from=' . urlencode($dateFrom);
if($dateTo !== '') $dateQueryTail .= '&date_to=' . urlencode($dateTo);
$filterQueryTail = $dateQueryTail;
if($feedSectionKey !== '') $filterQueryTail .= '&feed_section=' . urlencode($feedSectionKey);

if($canManageLedger && isset($_GET['edit_id']) && !isset($_POST['update_entry'])){
    $requestedEditId = (int)$_GET['edit_id'];
    if($requestedEditId > 0){
        $editStmt = $conn->prepare("SELECT id, entry_date, category, entry_type, amount_mode, amount, note FROM account_entries WHERE id=? LIMIT 1");
        $editStmt->bind_param("i", $requestedEditId); $editStmt->execute();
        $editRes = $editStmt->get_result();
        if($editRes->num_rows > 0){
            $editRow = $editRes->fetch_assoc();
            $editingId = (int)$editRow['id']; $formEntryDate = $editRow['entry_date'];
            $formCategory = $editRow['category']; $formEntryType = $editRow['entry_type'];
            $formAmountMode = isset($editRow['amount_mode']) && $editRow['amount_mode'] !== '' ? $editRow['amount_mode'] : 'cash';
            $formAmount = (string)$editRow['amount']; $formNote = $editRow['note'];
        }
        $editStmt->close();
    }
}

$totalSql = "SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS total_debit, SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS total_credit FROM account_entries" . $whereSql;
list($totalStmt, $totalsRes) = exec_prepared_result_local($conn, $totalSql, $bindTypes, $bindValues);
$totals = $totalsRes ? $totalsRes->fetch_assoc() : ['total_debit' => 0, 'total_credit' => 0];
if($totalStmt) $totalStmt->close();
$totalDebit = $totals['total_debit'] ?? 0;
$totalCredit = $totals['total_credit'] ?? 0;
$netBalance = (float)$totalCredit - (float)$totalDebit;

$modeTotalsSql = "SELECT SUM(CASE WHEN entry_type='debit' AND amount_mode='cash' THEN amount ELSE 0 END) AS debit_cash, SUM(CASE WHEN entry_type='debit' AND amount_mode='account' THEN amount ELSE 0 END) AS debit_account, SUM(CASE WHEN entry_type='credit' AND amount_mode='cash' THEN amount ELSE 0 END) AS credit_cash, SUM(CASE WHEN entry_type='credit' AND amount_mode='account' THEN amount ELSE 0 END) AS credit_account FROM account_entries" . $whereSql;
list($modeStmt, $modeRes) = exec_prepared_result_local($conn, $modeTotalsSql, $bindTypes, $bindValues);
$modeTotals = $modeRes ? $modeRes->fetch_assoc() : ['debit_cash'=>0,'debit_account'=>0,'credit_cash'=>0,'credit_account'=>0];
if($modeStmt) $modeStmt->close();

$categoryTotals = [];
$catTotalsSql = "SELECT category, SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS debit_total, SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS credit_total, SUM(CASE WHEN entry_type='debit' AND amount_mode='cash' THEN amount ELSE 0 END) AS debit_cash, SUM(CASE WHEN entry_type='debit' AND amount_mode='account' THEN amount ELSE 0 END) AS debit_account, SUM(CASE WHEN entry_type='credit' AND amount_mode='cash' THEN amount ELSE 0 END) AS credit_cash, SUM(CASE WHEN entry_type='credit' AND amount_mode='account' THEN amount ELSE 0 END) AS credit_account FROM account_entries" . $whereSql . " GROUP BY category";
list($catStmt, $catResult) = exec_prepared_result_local($conn, $catTotalsSql, $bindTypes, $bindValues);
while($r = $catResult->fetch_assoc()) $categoryTotals[$r['category']] = $r;
if($catStmt) $catStmt->close();

$businessByMonth = [];
$businessSql = "SELECT ym, SUM(total_amount) AS business_total
FROM (
    SELECT DATE_FORMAT(date, '%Y-%m') AS ym,
           SUM(COALESCE(original_freight, GREATEST(COALESCE(freight,0) - COALESCE(commission,0), 0))) AS total_amount
    FROM bilty
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    UNION ALL
    SELECT DATE_FORMAT(date, '%Y-%m') AS ym,
           SUM(GREATEST(COALESCE(freight,0) - COALESCE(commission,0), 0)) AS total_amount
    FROM haleeb_bilty
    GROUP BY DATE_FORMAT(date, '%Y-%m')
) t
GROUP BY ym";
$businessRes = $conn->query($businessSql);
while($businessRes && $bRow = $businessRes->fetch_assoc()){
    $monthKey = isset($bRow['ym']) ? (string)$bRow['ym'] : '';
    if($monthKey === '') continue;
    $businessByMonth[$monthKey] = (float)($bRow['business_total'] ?? 0);
}
if($businessRes) $businessRes->free();

$primeByMonth = [];
$primeMonthlySql = "SELECT DATE_FORMAT(entry_date, '%Y-%m') AS ym,
                           SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS card_total,
                           SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS recovery_total
                    FROM account_entries
                    WHERE category='prime_pump'
                    GROUP BY DATE_FORMAT(entry_date, '%Y-%m')";
$primeMonthlyRes = $conn->query($primeMonthlySql);
while($primeMonthlyRes && $pRow = $primeMonthlyRes->fetch_assoc()){
    $monthKey = isset($pRow['ym']) ? (string)$pRow['ym'] : '';
    if($monthKey === '') continue;
    $primeByMonth[$monthKey] = [
        'card_total' => (float)($pRow['card_total'] ?? 0),
        'recovery_total' => (float)($pRow['recovery_total'] ?? 0),
    ];
}
if($primeMonthlyRes) $primeMonthlyRes->free();

$primeTotals = ['card_total' => 0.0, 'recovery_total' => 0.0];
$primeTotalsRes = $conn->query("SELECT SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS card_total, SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS recovery_total FROM account_entries WHERE category='prime_pump'");
if($primeTotalsRes){
    $primeTotalsRow = $primeTotalsRes->fetch_assoc();
    if($primeTotalsRow){
        $primeTotals['card_total'] = (float)($primeTotalsRow['card_total'] ?? 0);
        $primeTotals['recovery_total'] = (float)($primeTotalsRow['recovery_total'] ?? 0);
    }
    $primeTotalsRes->free();
}
$primeRunningBalance = (float)$primeTotals['card_total'] - (float)$primeTotals['recovery_total'];

$allReportMonthsMap = [];
foreach($businessByMonth as $monthKey => $_) $allReportMonthsMap[$monthKey] = true;
foreach($primeByMonth as $monthKey => $_) $allReportMonthsMap[$monthKey] = true;
$allReportMonthsAsc = array_keys($allReportMonthsMap);
sort($allReportMonthsAsc);

$companyFuelRows = [];
$pumpRecoveryRowsMap = [];
$runningCarry = 0.0;
foreach($allReportMonthsAsc as $monthKey){
    $businessTotal = isset($businessByMonth[$monthKey]) ? (float)$businessByMonth[$monthKey] : 0.0;
    $expectedFuel = $businessTotal * 0.5;
    $monthCardGiven = isset($primeByMonth[$monthKey]['card_total']) ? (float)$primeByMonth[$monthKey]['card_total'] : 0.0;
    $monthRecovery = isset($primeByMonth[$monthKey]['recovery_total']) ? (float)$primeByMonth[$monthKey]['recovery_total'] : 0.0;
    $difference = $monthCardGiven - $expectedFuel;

    $companyFuelRows[$monthKey] = [
        'month' => $monthKey,
        'business_total' => $businessTotal,
        'expected_fuel' => $expectedFuel,
        'actual_card' => $monthCardGiven,
        'difference' => $difference,
    ];

    $runningCarry += ($monthCardGiven - $monthRecovery);
    $pumpRecoveryRowsMap[$monthKey] = [
        'month' => $monthKey,
        'card_given' => $monthCardGiven,
        'cash_received' => $monthRecovery,
        'running_balance' => $runningCarry,
    ];
}

$reportMonthsDesc = array_reverse($allReportMonthsAsc);
$reportMonthsDesc = array_slice($reportMonthsDesc, 0, 18);

$companyFuelReportRows = [];
$pumpRecoveryReportRows = [];
foreach($reportMonthsDesc as $monthKey){
    if(isset($companyFuelRows[$monthKey])) $companyFuelReportRows[] = $companyFuelRows[$monthKey];
    if(isset($pumpRecoveryRowsMap[$monthKey])) $pumpRecoveryReportRows[] = $pumpRecoveryRowsMap[$monthKey];
}

$currentMonthKey = date('Y-m');
$currentMonthBusiness = isset($companyFuelRows[$currentMonthKey]['business_total']) ? (float)$companyFuelRows[$currentMonthKey]['business_total'] : 0.0;
$currentMonthExpectedFuel = $currentMonthBusiness * 0.5;
$currentMonthActualCard = isset($companyFuelRows[$currentMonthKey]['actual_card']) ? (float)$companyFuelRows[$currentMonthKey]['actual_card'] : 0.0;
$currentMonthFuelDiff = $currentMonthActualCard - $currentMonthExpectedFuel;

$entriesSql = "SELECT
    account_entries.*,
    COALESCE(NULLIF(b.party, ''), NULLIF(h.party, ''), '') AS linked_party,
    COALESCE(NULLIF(b.vehicle, ''), NULLIF(h.vehicle, ''), '') AS linked_vehicle,
    CASE
        WHEN account_entries.bilty_id IS NOT NULL AND account_entries.bilty_id > 0 THEN COALESCE(b.feed_portion, '')
        ELSE ''
    END AS linked_feed_portion,
    COALESCE(
        NULLIF(ub.username, ''),
        NULLIF(uh.username, ''),
        CASE WHEN b.added_by_user_id IS NULL THEN '' ELSE CONCAT('User#', b.added_by_user_id) END,
        CASE WHEN h.added_by_user_id IS NULL THEN '' ELSE CONCAT('User#', h.added_by_user_id) END,
        ''
    ) AS linked_added_by
FROM account_entries
LEFT JOIN bilty b ON b.id = account_entries.bilty_id
LEFT JOIN users ub ON ub.id = b.added_by_user_id
LEFT JOIN haleeb_bilty h ON h.id = account_entries.haleeb_bilty_id
LEFT JOIN users uh ON uh.id = h.added_by_user_id"
 . $whereSql . " ORDER BY account_entries.entry_date DESC, account_entries.id DESC";
list($entryStmt, $entries) = exec_prepared_result_local($conn, $entriesSql, $bindTypes, $bindValues);
$pendingPayRequests = $canManageLedger ? fetch_pending_change_requests_local($conn, [], ['feed_pay', 'haleeb_pay']) : [];
$flaggedActivityCount = activity_count_flagged_for_admin_local($conn);
$openEntryPanel = $canManageLedger && ($editingId > 0 || isset($_POST['add_entry']) || isset($_POST['update_entry']));
$openPayReqPanel = $canManageLedger && (
    isset($_POST['approve_pay_request']) ||
    isset($_POST['reject_pay_request']) ||
    (isset($_GET['show_requests']) && (string)$_GET['show_requests'] === '1')
);
$openFuelPumpPanel = $canManageLedger && (
    isset($_POST['add_prime_card']) ||
    isset($_POST['add_prime_recovery']) ||
    (isset($_GET['show_fuel_pump']) && (string)$_GET['show_fuel_pump'] === '1')
);
$openAnalyticsPanel = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11;
    --surface: #16181c;
    --surface2: #1e2128;
    --border: #2a2d35;
    --accent: #f0c040;
    --green: #22c55e;
    --red: #ef4444;
    --blue: #60a5fa;
    --purple: #c084fc;
    --text: #e8eaf0;
    --muted: #7c8091;
    --font: 'Syne', sans-serif;
    --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  /* TOPBAR */
  .topbar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 28px; border-bottom: 1px solid var(--border);
    background: var(--surface); position: sticky; top: 0; z-index: 100;
  }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge-pill {
    background: var(--accent); color: #0e0f11; font-size: 10px;
    font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase;
  }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .nav-btn {
    padding: 8px 14px; background: transparent; color: var(--muted);
    border: 1px solid var(--border); cursor: pointer; text-decoration: none;
    font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s;
  }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .nav-btn.danger { background: rgba(239,68,68,0.12); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .nav-btn.danger:hover { background: rgba(239,68,68,0.22); }

  /* MAIN */
  .main { padding: 24px 28px; max-width: 1400px; margin: 0 auto; }

  .alert { padding: 12px 16px; margin-bottom: 16px; font-size: 13px; border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green); }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }
  .section-toggle-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 13px 16px;
    background: transparent;
    border: none;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    cursor: pointer;
    text-align: left;
    font-family: var(--font);
  }
  .section-toggle-btn:hover { background: var(--surface2); }
  .section-toggle-text {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
  }
  .toggle-icon {
    width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    font-size: 14px;
    font-weight: 700;
    line-height: 1;
    flex-shrink: 0;
  }
  .toggle-body { display: none; }
  .toggle-body.open { display: block; }

  .ledger-toolbar { margin-bottom: 12px; }
  .ledger-toolbar-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 11px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    cursor: pointer;
    font-family: var(--font);
  }
  .ledger-toolbar-btn:hover { background: var(--surface2); }
  .ledger-toolbar-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: var(--muted);
  }
  .ledger-toolbar-meta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .ledger-toolbar-count {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--accent);
  }
  .fp-balance-due { color: #fb7185; }
  .fp-balance-clear { color: var(--green); }

  .fuel-pump-panel {
    background: var(--surface);
    border: 1px solid var(--border);
    margin-bottom: 20px;
  }
  .fuel-pump-body { padding: 16px; }
  .fuel-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 12px;
  }
  .fuel-kpi-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    padding: 10px 12px;
  }
  .fuel-kpi-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 4px;
  }
  .fuel-kpi-value {
    font-family: var(--mono);
    font-size: 16px;
    font-weight: 500;
  }
  .fuel-kpi-value.red { color: var(--red); }
  .fuel-kpi-value.green { color: var(--green); }
  .fuel-kpi-value.yellow { color: var(--accent); }

  .prime-entry-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 14px;
  }
  .prime-entry-card {
    border: 1px solid var(--border);
    background: var(--surface2);
    padding: 12px;
  }
  .prime-entry-title {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
  }
  .prime-entry-form {
    display: grid;
    grid-template-columns: 120px 1fr 1fr auto;
    gap: 8px;
    align-items: end;
  }
  .prime-entry-field label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.1px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 4px;
  }
  .prime-entry-field input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 9px;
    font-family: var(--font);
    font-size: 12px;
  }
  .btn-prime {
    padding: 9px 12px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font-family: var(--font);
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .btn-prime.issue {
    color: var(--green);
    border-color: rgba(34,197,94,0.3);
    background: rgba(34,197,94,0.1);
  }
  .btn-prime.recovery {
    color: var(--blue);
    border-color: rgba(96,165,250,0.35);
    background: rgba(96,165,250,0.1);
  }
  .btn-prime:hover { filter: brightness(1.1); }

  .fuel-report-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }
  .fuel-report-box {
    border: 1px solid var(--border);
    background: var(--surface2);
    overflow: hidden;
  }
  .fuel-report-head {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--muted);
  }
  .fuel-report-wrap {
    max-height: 320px;
    overflow: auto;
  }
  .fuel-report-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 620px;
  }
  .fuel-report-table th {
    padding: 9px 10px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.1px;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    background: rgba(30,33,40,0.8);
    white-space: nowrap;
  }
  .fuel-report-table td {
    padding: 8px 10px;
    border-bottom: 1px solid rgba(42,45,53,0.7);
    font-size: 12px;
    font-family: var(--mono);
  }
  .fuel-report-table td.status-short { color: var(--red); }
  .fuel-report-table td.status-excess { color: var(--green); }
  .fuel-report-table td.status-even { color: var(--muted); }
  .fuel-report-table td .status-short { color: var(--red); }
  .fuel-report-table td .status-excess { color: var(--green); }
  .fuel-report-table td .status-even { color: var(--muted); }

  .pay-req-panel {
    background: var(--surface); border: 1px solid var(--border); margin-bottom: 20px;
  }
  .pay-req-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 16px; border-bottom: 1px solid var(--border);
  }
  .pay-req-title {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted);
  }
  .pay-req-count { font-family: var(--mono); font-size: 11px; color: var(--accent); }
  .pay-req-table { width: 100%; border-collapse: collapse; }
  .pay-req-table thead tr { background: var(--surface2); }
  .pay-req-table th {
    padding: 10px 12px; text-align: left; font-size: 10px; font-weight: 700;
    letter-spacing: 1.3px; text-transform: uppercase; color: var(--muted);
    border-bottom: 1px solid var(--border); white-space: nowrap;
  }
  .pay-req-table td {
    padding: 10px 12px; font-size: 12px; border-bottom: 1px solid rgba(42,45,53,0.6); vertical-align: top;
  }
  .pay-req-table tbody tr:hover { background: var(--surface2); }
  .pay-req-note { color: var(--muted); font-size: 11px; font-family: var(--font); }
  .pay-req-act { display: flex; flex-direction: column; gap: 6px; min-width: 180px; }
  .pay-req-field-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.1px;
    text-transform: uppercase;
    color: var(--muted);
  }
  .pay-req-amount-input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 7px 9px;
    font-family: var(--font);
    font-size: 12px;
  }
  .pay-req-note-input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 7px 9px; font-family: var(--font); font-size: 12px;
  }
  .pay-req-btns { display: flex; gap: 6px; }
  .btn-approve {
    flex: 1; padding: 8px 10px; background: rgba(34,197,94,0.12); color: var(--green);
    border: 1px solid rgba(34,197,94,0.3); cursor: pointer; font-family: var(--font); font-size: 12px; font-weight: 700;
  }
  .btn-approve:hover { background: rgba(34,197,94,0.22); }
  .btn-reject {
    flex: 1; padding: 8px 10px; background: rgba(239,68,68,0.08); color: var(--red);
    border: 1px solid rgba(239,68,68,0.24); cursor: pointer; font-family: var(--font); font-size: 12px; font-weight: 700;
  }
  .btn-reject:hover { background: rgba(239,68,68,0.18); }
  /* FORM */
  .form-panel {
    background: var(--surface); border: 1px solid var(--border); margin-bottom: 20px;
  }
  .form-panel-title {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
  }
  .form-panel-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .form-panel-body { padding: 20px 24px; }
  .form-row {
    display: grid; grid-template-columns: 140px 120px 110px 110px 150px 1fr auto; gap: 10px; align-items: end;
  }
  .form-field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 5px; }
  .form-field input, .form-field select {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 9px 10px; font-family: var(--font); font-size: 13px; transition: border-color 0.15s;
    appearance: none;
  }
  .form-field select { cursor: pointer; }
  .form-field input:focus, .form-field select:focus { outline: none; border-color: var(--accent); }
  .form-field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .form-actions { display: flex; gap: 6px; padding-bottom: 1px; }
  .btn-submit {
    padding: 9px 18px; background: var(--accent); color: #0e0f11; border: none;
    cursor: pointer; font-family: var(--font); font-size: 13px; font-weight: 700;
    transition: background 0.15s; white-space: nowrap;
  }
  .btn-submit:hover { background: #e0b030; }
  .btn-cancel {
    padding: 9px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border);
    cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600;
    white-space: nowrap; transition: all 0.15s;
  }
  .btn-cancel:hover { color: var(--text); border-color: var(--muted); }
  .btn-del {
    width: 36px; height: 36px; background: rgba(239,68,68,0.12); color: var(--red);
    border: 1px solid rgba(239,68,68,0.25); cursor: pointer; display: flex; align-items: center;
    justify-content: center; font-size: 14px; text-decoration: none; flex-shrink: 0;
    transition: all 0.15s;
  }
  .btn-del:hover { background: rgba(239,68,68,0.25); }

  /* CATEGORY FILTER */
  .cat-panel { background: var(--surface); border: 1px solid var(--border); padding: 16px 20px; margin-bottom: 20px; }
  .cat-filter-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
  .cat-btn {
    padding: 7px 16px; background: transparent; color: var(--muted);
    border: 1px solid var(--border); cursor: pointer; text-decoration: none;
    font-family: var(--font); font-size: 12px; font-weight: 700; letter-spacing: 0.5px;
    transition: all 0.15s;
  }
  .cat-btn:hover, .cat-btn.active { background: var(--accent); color: #0e0f11; border-color: var(--accent); }
  .date-filter { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
  .date-filter .form-field input { width: 150px; }
  .btn-apply {
    padding: 9px 16px; background: var(--surface2); color: var(--text); border: 1px solid var(--border);
    cursor: pointer; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s;
  }
  .btn-apply:hover { border-color: var(--muted); }

  /* STATS */
  .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
  .stat-card {
    background: var(--surface); border: 1px solid var(--border); padding: 16px 18px;
    position: relative; overflow: hidden;
  }
  .stat-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
  .stat-card.debit::before { background: var(--red); }
  .stat-card.credit::before { background: var(--green); }
  .stat-card.net-pos::before { background: var(--green); }
  .stat-card.net-neg::before { background: var(--red); }
  .stat-card.neutral::before { background: var(--muted); }
  .stat-label { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .stat-val { font-family: var(--mono); font-size: 20px; font-weight: 500; }
  .stat-val.red { color: var(--red); }
  .stat-val.green { color: var(--green); }
  .stat-val.yellow { color: var(--accent); }
  .stat-sub { font-size: 11px; color: var(--muted); margin-top: 6px; font-family: var(--mono); }

  /* CAT CARDS */
  .cat-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
  .cat-card {
    background: var(--surface); border: 1px solid var(--border); padding: 16px 18px;
  }
  .cat-card-title {
    font-size: 11px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;
    color: var(--accent); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
  }
  .cat-card-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .cat-line { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid rgba(42,45,53,0.5); font-size: 12px; }
  .cat-line:last-child { border-bottom: none; }
  .cat-line-label { color: var(--muted); font-weight: 600; }
  .cat-line-val { font-family: var(--mono); font-size: 13px; }
  .val-debit { color: var(--red); }
  .val-credit { color: var(--green); }
  .val-net-pos { color: var(--green); font-weight: 700; }
  .val-net-neg { color: var(--red); font-weight: 700; }
  .val-cash { color: var(--blue); }
  .val-account { color: var(--purple); }

  /* TABLE */
  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .tbl-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: var(--surface2); }
  th { padding: 11px 14px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid rgba(42,45,53,0.7); font-family: var(--mono); }
  tbody tr { transition: background 0.1s; }
  tbody tr:hover { background: var(--surface2); }

  .type-badge {
    display: inline-block; padding: 3px 10px; font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
  }
  .type-debit { background: rgba(239,68,68,0.12); color: var(--red); border: 1px solid rgba(239,68,68,0.25); }
  .type-credit { background: rgba(34,197,94,0.12); color: var(--green); border: 1px solid rgba(34,197,94,0.25); }
  .mode-badge {
    display: inline-block; padding: 3px 8px; font-size: 10px; font-weight: 600;
    letter-spacing: 0.8px; text-transform: uppercase;
  }
  .mode-cash { background: rgba(96,165,250,0.1); color: var(--blue); border: 1px solid rgba(96,165,250,0.2); }
  .mode-account { background: rgba(192,132,252,0.1); color: var(--purple); border: 1px solid rgba(192,132,252,0.2); }
  .cat-badge {
    display: inline-block; padding: 3px 8px; font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    background: rgba(240,192,64,0.1); color: var(--accent); border: 1px solid rgba(240,192,64,0.2);
  }
  .td-note { font-family: var(--font); font-size: 12px; color: var(--muted); }
  .act-edit {
    width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(240,192,64,0.12); color: var(--accent); border: 1px solid rgba(240,192,64,0.25);
    text-decoration: none; font-size: 13px; transition: all 0.15s;
  }
  .act-edit:hover { background: rgba(240,192,64,0.25); }
  .col-action { text-align: center; width: 60px; }
  .ref-link {
    color: var(--blue);
    text-decoration: none;
    border-bottom: 1px solid rgba(96,165,250,0.35);
    font-family: var(--font);
    font-size: 12px;
  }
  .ref-link:hover { color: #93c5fd; border-bottom-color: rgba(147,197,253,0.8); }
  .analytics-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    margin-bottom: 20px;
  }
  .analytics-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
  }
  .analytics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding: 14px 18px;
  }
  .analytics-actions {
    display: flex;
    align-items: end;
    justify-content: flex-end;
  }
  .analytics-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    padding: 0 18px 16px;
  }
  .a-stat {
    background: var(--surface2);
    border: 1px solid var(--border);
    padding: 10px 12px;
  }
  .a-stat .k {
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin-bottom: 4px;
    font-weight: 700;
  }
  .a-stat .v {
    font-family: var(--mono);
    font-size: 16px;
    font-weight: 500;
  }
  .tiny-meta {
    font-size: 11px;
    color: var(--muted);
    font-family: var(--mono);
  }

  @media(max-width: 1100px) {
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .cat-cards { grid-template-columns: 1fr 1fr; }
    .form-row { grid-template-columns: 1fr 1fr 1fr; }
    .analytics-grid { grid-template-columns: 1fr 1fr; }
    .analytics-stats { grid-template-columns: 1fr 1fr; }
    .fuel-kpi-grid { grid-template-columns: 1fr 1fr; }
    .prime-entry-form { grid-template-columns: 1fr 1fr; }
    .fuel-report-grid { grid-template-columns: 1fr; }
  }
  @media(max-width: 700px) {
    .topbar { padding: 14px 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .main { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .cat-cards { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr 1fr; }
    .fuel-kpi-grid { grid-template-columns: 1fr; }
    .prime-entry-grid { grid-template-columns: 1fr; }
    .prime-entry-form { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge-pill">Ledger</span>
    <h1>Account<?php echo $feedSectionLabel !== '' ? (' - Feed - ' . htmlspecialchars($feedSectionLabel)) : ''; ?></h1>
  </div>
  <div class="nav-links">
    <?php if($canReviewActivity): ?>
      <a class="nav-btn" href="activity_review.php">Activity Review<?php echo $flaggedActivityCount > 0 ? ' (' . $flaggedActivityCount . ')' : ''; ?></a>
    <?php endif; ?>
    <a class="nav-btn" href="all_bilties.php">All Bilties</a>
    <?php if($canManageUsers): ?><a class="nav-btn" href="super_admin.php">Super Admin</a><?php endif; ?>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <a class="nav-btn danger" href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <?php if($msg !== ""): ?>
    <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>
  <?php if($err !== ""): ?>
    <div class="alert error"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <?php if($canManageLedger): ?>
    <div class="ledger-toolbar">
      <button
        class="ledger-toolbar-btn"
        type="button"
        id="ledger_pay_request_toggle"
        data-toggle-target="ledger_pay_request_body"
        aria-expanded="<?php echo $openPayReqPanel ? 'true' : 'false'; ?>"
      >
        <span class="ledger-toolbar-label">Payment Requests</span>
        <span class="ledger-toolbar-meta">
          <span class="ledger-toolbar-count"><?php echo count($pendingPayRequests); ?> pending</span>
          <span class="toggle-icon"><?php echo $openPayReqPanel ? '-' : '+'; ?></span>
        </span>
      </button>
    </div>
    <div id="ledger_pay_request_body" class="toggle-body<?php echo $openPayReqPanel ? ' open' : ''; ?>">
      <div class="pay-req-panel">
        <div class="pay-req-head">
          <span class="pay-req-title">Feed/Haleeb Payment Requests</span>
          <span class="pay-req-count"><?php echo count($pendingPayRequests); ?> pending</span>
        </div>
        <?php if(count($pendingPayRequests) === 0): ?>
          <div style="padding:14px 16px;" class="pay-req-note">No pending payment requests.</div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="pay-req-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Module</th>
                  <th>Requested By</th>
                  <th>Details</th>
                  <th>Requested At</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($pendingPayRequests as $r): ?>
                  <?php
                    $p = json_decode((string)$r['payload'], true);
                    if(!is_array($p)) $p = [];
                    $amt = isset($p['amount']) ? (float)$p['amount'] : 0;
                    $mode = isset($p['amount_mode']) ? (string)$p['amount_mode'] : '';
                    $category = isset($p['category']) ? (string)$p['category'] : '';
                    $entryDate = isset($p['entry_date']) ? (string)$p['entry_date'] : '';
                    $note = isset($p['note']) ? (string)$p['note'] : '';
                    $entityId = isset($r['entity_id']) ? (int)$r['entity_id'] : 0;
                    $reqModule = strtolower((string)($r['module_key'] ?? 'feed'));
                    $detailHref = bilty_detail_link_local($reqModule === 'haleeb' ? 'haleeb' : 'feed', $entityId, 'account');
                    $remainingErr = '';
                    $remainingAmt = payment_request_remaining_local(
                      $conn,
                      isset($r['action_type']) ? (string)$r['action_type'] : '',
                      $entityId,
                      $remainingErr
                    );
                  ?>
                  <tr>
                    <td>#<?php echo (int)$r['id']; ?></td>
                    <td><?php echo htmlspecialchars(strtoupper((string)$r['module_key'])); ?></td>
                    <td><?php echo htmlspecialchars((string)($r['requested_by_name'] ?: ('User#' . (int)$r['requested_by']))); ?></td>
                    <td>
                      <div>Amount: <strong>Rs <?php echo format_amount_local($amt, 1); ?></strong></div>
                      <div class="pay-req-note">Mode: <?php echo htmlspecialchars($mode); ?> | Category: <?php echo htmlspecialchars($category); ?> | Date: <?php echo htmlspecialchars($entryDate); ?></div>
                      <?php if($note !== ''): ?><div class="pay-req-note">Note: <?php echo htmlspecialchars($note); ?></div><?php endif; ?>
                      <?php if($remainingErr !== ''): ?>
                        <div class="pay-req-note">Remaining: <?php echo htmlspecialchars($remainingErr); ?></div>
                      <?php else: ?>
                        <div class="pay-req-note">Remaining now: Rs <?php echo format_amount_local($remainingAmt, 1); ?></div>
                      <?php endif; ?>
                      <?php if($detailHref !== ''): ?>
                        <div class="pay-req-note"><a class="ref-link" href="<?php echo htmlspecialchars($detailHref); ?>">Open Bilty Detail</a></div>
                      <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
                    <td>
                      <form method="post" class="pay-req-act">
                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                        <label class="pay-req-field-label" for="req_amt_<?php echo (int)$r['id']; ?>">Approve Amount (Rs)</label>
                        <input
                          id="req_amt_<?php echo (int)$r['id']; ?>"
                          class="pay-req-amount-input"
                          type="number"
                          name="request_amount"
                          step="any"
                          value="<?php echo htmlspecialchars(format_amount_local($amt, 1, false)); ?>"
                          placeholder="0.00"
                        >
                        <input class="pay-req-note-input" type="text" name="review_note" placeholder="Optional note">
                        <div class="pay-req-btns">
                          <button class="btn-approve" type="submit" name="approve_pay_request">Approve</button>
                          <button class="btn-reject" type="submit" name="reject_pay_request">Reject</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if($canManageLedger): ?>
    <div class="ledger-toolbar">
      <button
        class="ledger-toolbar-btn"
        type="button"
        id="ledger_fuel_pump_toggle"
        data-toggle-target="ledger_fuel_pump_body"
        aria-expanded="<?php echo $openFuelPumpPanel ? 'true' : 'false'; ?>"
      >
        <span class="ledger-toolbar-label">Company Fuel + Pump Recovery</span>
        <span class="ledger-toolbar-meta">
          <span class="ledger-toolbar-count <?php echo $primeRunningBalance > 0.0001 ? 'fp-balance-due' : 'fp-balance-clear'; ?>">
            Prime Balance: Rs <?php echo format_amount_local(abs($primeRunningBalance), 1); ?><?php echo $primeRunningBalance > 0.0001 ? ' Due' : ' Clear'; ?>
          </span>
          <span class="toggle-icon"><?php echo $openFuelPumpPanel ? '-' : '+'; ?></span>
        </span>
      </button>
    </div>
    <div id="ledger_fuel_pump_body" class="toggle-body<?php echo $openFuelPumpPanel ? ' open' : ''; ?>">
      <div class="fuel-pump-panel">
        <div class="fuel-pump-body">
          <div class="fuel-kpi-grid">
            <div class="fuel-kpi-card">
              <div class="fuel-kpi-label">This Month Business</div>
              <div class="fuel-kpi-value">Rs <?php echo format_amount_local($currentMonthBusiness, 1); ?></div>
            </div>
            <div class="fuel-kpi-card">
              <div class="fuel-kpi-label">Expected Fuel (50%)</div>
              <div class="fuel-kpi-value yellow">Rs <?php echo format_amount_local($currentMonthExpectedFuel, 1); ?></div>
            </div>
            <div class="fuel-kpi-card">
              <div class="fuel-kpi-label">Actual Card (This Month)</div>
              <div class="fuel-kpi-value">Rs <?php echo format_amount_local($currentMonthActualCard, 1); ?></div>
              <div class="pay-req-note">
                <?php if(abs($currentMonthFuelDiff) < 0.0001): ?>
                  Difference: Even
                <?php elseif($currentMonthFuelDiff < 0): ?>
                  Difference: Short Rs <?php echo format_amount_local(abs($currentMonthFuelDiff), 1); ?>
                <?php else: ?>
                  Difference: Excess Rs <?php echo format_amount_local($currentMonthFuelDiff, 1); ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="fuel-kpi-card">
              <div class="fuel-kpi-label">Prime Running Balance</div>
              <div class="fuel-kpi-value <?php echo $primeRunningBalance > 0.0001 ? 'red' : 'green'; ?>">
                Rs <?php echo format_amount_local(abs($primeRunningBalance), 1); ?><?php echo $primeRunningBalance > 0.0001 ? ' Due' : ' Settled'; ?>
              </div>
            </div>
          </div>

          <div class="prime-entry-grid">
            <div class="prime-entry-card">
              <div class="prime-entry-title">Card To Prime (Auto Pump Credit)</div>
              <form method="post" class="prime-entry-form">
                <div class="prime-entry-field">
                  <label for="prime_entry_date_card">Date</label>
                  <input id="prime_entry_date_card" type="date" name="prime_entry_date" value="<?php echo htmlspecialchars($primeFormDate); ?>" required>
                </div>
                <div class="prime-entry-field">
                  <label for="prime_card_amount">Card Amount (Rs)</label>
                  <input id="prime_card_amount" type="number" step="any" min="0.001" name="prime_card_amount" value="<?php echo htmlspecialchars($primeCardAmount); ?>" placeholder="0.00" required>
                </div>
                <div class="prime-entry-field">
                  <label for="prime_note_card">Note</label>
                  <input id="prime_note_card" type="text" name="prime_note" value="<?php echo htmlspecialchars($primeFormNote); ?>" placeholder="Optional note">
                </div>
                <button class="btn-prime issue" type="submit" name="add_prime_card">Add Card</button>
              </form>
            </div>

            <div class="prime-entry-card">
              <div class="prime-entry-title">Prime Partial Payment (Recovery Debit)</div>
              <form method="post" class="prime-entry-form">
                <div class="prime-entry-field">
                  <label for="prime_entry_date_recovery">Date</label>
                  <input id="prime_entry_date_recovery" type="date" name="prime_entry_date" value="<?php echo htmlspecialchars($primeFormDate); ?>" required>
                </div>
                <div class="prime-entry-field">
                  <label for="prime_recovery_amount">Recovery Amount (Rs)</label>
                  <input id="prime_recovery_amount" type="number" step="any" min="0.001" name="prime_recovery_amount" value="<?php echo htmlspecialchars($primeRecoveryAmount); ?>" placeholder="0.00" required>
                </div>
                <div class="prime-entry-field">
                  <label for="prime_note_recovery">Note</label>
                  <input id="prime_note_recovery" type="text" name="prime_note" value="<?php echo htmlspecialchars($primeFormNote); ?>" placeholder="Optional note">
                </div>
                <button class="btn-prime recovery" type="submit" name="add_prime_recovery">Add Recovery</button>
              </form>
            </div>
          </div>

          <div class="fuel-report-grid">
            <div class="fuel-report-box">
              <div class="fuel-report-head">Company Fuel Report (Actual Card vs 50% Requirement)</div>
              <div class="fuel-report-wrap">
                <table class="fuel-report-table">
                  <thead>
                    <tr>
                      <th>Month</th>
                      <th>Business</th>
                      <th>Expected 50%</th>
                      <th>Actual Card</th>
                      <th>Short/Excess</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if(count($companyFuelReportRows) === 0): ?>
                      <tr><td colspan="5">No data found.</td></tr>
                    <?php else: ?>
                      <?php foreach($companyFuelReportRows as $row): ?>
                        <?php
                          $monthLabelTs = strtotime((string)$row['month'] . '-01');
                          $monthLabel = $monthLabelTs ? date('M Y', $monthLabelTs) : (string)$row['month'];
                          $diffVal = (float)$row['difference'];
                          if(abs($diffVal) < 0.0001){
                              $diffText = 'Even';
                              $diffClass = 'status-even';
                          } elseif($diffVal < 0){
                              $diffText = 'Short Rs ' . format_amount_local(abs($diffVal), 1);
                              $diffClass = 'status-short';
                          } else {
                              $diffText = 'Excess Rs ' . format_amount_local($diffVal, 1);
                              $diffClass = 'status-excess';
                          }
                        ?>
                        <tr>
                          <td><?php echo htmlspecialchars($monthLabel); ?></td>
                          <td>Rs <?php echo format_amount_local((float)$row['business_total'], 1); ?></td>
                          <td>Rs <?php echo format_amount_local((float)$row['expected_fuel'], 1); ?></td>
                          <td>Rs <?php echo format_amount_local((float)$row['actual_card'], 1); ?></td>
                          <td><span class="<?php echo htmlspecialchars($diffClass); ?>"><?php echo htmlspecialchars($diffText); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="fuel-report-box">
              <div class="fuel-report-head">Pump Recovery Report (Card Given vs Cash Received)</div>
              <div class="fuel-report-wrap">
                <table class="fuel-report-table">
                  <thead>
                    <tr>
                      <th>Month</th>
                      <th>Card Given (Credit)</th>
                      <th>Cash Received (Debit)</th>
                      <th>Running Balance</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if(count($pumpRecoveryReportRows) === 0): ?>
                      <tr><td colspan="4">No data found.</td></tr>
                    <?php else: ?>
                      <?php foreach($pumpRecoveryReportRows as $row): ?>
                        <?php
                          $monthLabelTs = strtotime((string)$row['month'] . '-01');
                          $monthLabel = $monthLabelTs ? date('M Y', $monthLabelTs) : (string)$row['month'];
                          $runningVal = (float)$row['running_balance'];
                        ?>
                        <tr>
                          <td><?php echo htmlspecialchars($monthLabel); ?></td>
                          <td>Rs <?php echo format_amount_local((float)$row['card_given'], 1); ?></td>
                          <td>Rs <?php echo format_amount_local((float)$row['cash_received'], 1); ?></td>
                          <td class="<?php echo $runningVal > 0.0001 ? 'status-short' : 'status-excess'; ?>">
                            Rs <?php echo format_amount_local(abs($runningVal), 1); ?><?php echo $runningVal > 0.0001 ? ' Due' : ' Settled'; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ENTRY FORM -->
  <?php if($canManageLedger): ?>
    <div class="form-panel">
      <button
        class="section-toggle-btn"
        type="button"
        id="ledger_entry_toggle"
        data-toggle-target="ledger_entry_body"
        aria-expanded="<?php echo $openEntryPanel ? 'true' : 'false'; ?>"
      >
        <span class="section-toggle-text"><?php echo $editingId > 0 ? 'Edit Entry' : 'New Entry'; ?></span>
        <span class="toggle-icon"><?php echo $openEntryPanel ? '-' : '+'; ?></span>
      </button>
      <div id="ledger_entry_body" class="toggle-body<?php echo $openEntryPanel ? ' open' : ''; ?>">
      <div class="form-panel-body">
      <form method="post">
        <div class="form-row">
          <div class="form-field">
            <label>Date</label>
            <input type="date" name="entry_date" value="<?php echo htmlspecialchars($formEntryDate); ?>" required>
          </div>
          <div class="form-field">
            <label>Category</label>
            <select name="category" required>
              <?php foreach($allowedCategories as $categoryKey): ?>
                <option value="<?php echo htmlspecialchars($categoryKey); ?>" <?php echo $formCategory === $categoryKey ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars(ledger_category_label_local($categoryKey, $categoryLabels)); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Type</label>
            <select name="entry_type" required>
              <option value="debit" <?php echo $formEntryType==='debit'?'selected':''; ?>>Debit</option>
              <option value="credit" <?php echo $formEntryType==='credit'?'selected':''; ?>>Credit</option>
            </select>
          </div>
          <div class="form-field">
            <label>Mode</label>
            <select name="amount_mode" required>
              <option value="cash" <?php echo $formAmountMode==='cash'?'selected':''; ?>>Cash</option>
              <option value="account" <?php echo $formAmountMode==='account'?'selected':''; ?>>Account</option>
            </select>
          </div>
          <div class="form-field">
            <label>Amount (Rs)</label>
            <input type="number" step="any" min="0.001" name="amount" placeholder="0.00" value="<?php echo htmlspecialchars($formAmount); ?>" required>
          </div>
          <div class="form-field">
            <label>Note (optional)</label>
            <input type="text" name="note" placeholder="Note" value="<?php echo htmlspecialchars($formNote); ?>">
          </div>
          <div class="form-actions">
            <?php if($editingId > 0): ?>
              <input type="hidden" name="edit_id" value="<?php echo (int)$editingId; ?>">
              <button class="btn-submit" type="submit" name="update_entry">Update</button>
              <a class="btn-cancel" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?><?php echo $feedSectionKey !== '' ? ('&feed_section=' . urlencode($feedSectionKey)) : ''; ?>">Cancel</a>
              <a class="btn-del" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?><?php echo $feedSectionKey !== '' ? ('&feed_section=' . urlencode($feedSectionKey)) : ''; ?>&delete_id=<?php echo (int)$editingId; ?>" onclick="return confirm('Delete this entry?')" title="Delete">&#128465;</a>
            <?php else: ?>
              <button class="btn-submit" type="submit" name="add_entry">Save</button>
            <?php endif; ?>
          </div>
        </div>
      </form>
      </div>
      </div>
    </div>
  <?php else: ?>
    <div class="form-panel" style="padding:14px 16px;">
      <div class="pay-req-note">View-only mode: you can only see ledger entries.</div>
    </div>
  <?php endif; ?>

  <!-- FILTER -->
  <div class="cat-panel">
    <div class="cat-filter-row">
      <a class="cat-btn <?php echo $cat==='all'?'active':''; ?>" href="account.php?cat=all<?php echo $filterQueryTail; ?>">All</a>
      <?php foreach($allowedCategories as $categoryKey): ?>
        <a class="cat-btn <?php echo $cat === $categoryKey ? 'active' : ''; ?>" href="account.php?cat=<?php echo urlencode($categoryKey); ?><?php echo $filterQueryTail; ?>">
          <?php echo htmlspecialchars(ledger_category_label_local($categoryKey, $categoryLabels)); ?>
        </a>
      <?php endforeach; ?>
    </div>
    <form class="date-filter" method="get">
      <input type="hidden" name="cat" value="<?php echo htmlspecialchars($cat); ?>">
      <?php if($feedSectionKey !== ''): ?>
        <input type="hidden" name="feed_section" value="<?php echo htmlspecialchars($feedSectionKey); ?>">
      <?php endif; ?>
      <div class="form-field">
        <label>From</label>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
      </div>
      <div class="form-field">
        <label>To</label>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
      </div>
      <button class="btn-apply" type="submit">Apply</button>
      <a class="btn-cancel" href="account.php?cat=<?php echo urlencode($cat); ?><?php echo $feedSectionKey !== '' ? ('&feed_section=' . urlencode($feedSectionKey)) : ''; ?>">Reset</a>
    </form>
  </div>

  <div class="analytics-wrap">
    <button
      class="section-toggle-btn"
      type="button"
      id="ledger_analytics_toggle"
      data-toggle-target="ledger_analytics_body"
      aria-expanded="<?php echo $openAnalyticsPanel ? 'true' : 'false'; ?>"
    >
      <span class="section-toggle-text">Ledger Analytics Filters</span>
      <span class="toggle-icon"><?php echo $openAnalyticsPanel ? '-' : '+'; ?></span>
    </button>
    <div id="ledger_analytics_body" class="toggle-body<?php echo $openAnalyticsPanel ? ' open' : ''; ?>">
    <div class="analytics-head">
      <span class="tbl-title">Ledger Analytics Filters</span>
      <button class="btn-apply" type="button" id="ledger_analytics_reset">Reset Analytics</button>
    </div>
    <div class="analytics-grid">
      <div class="form-field">
        <label for="a_led_text">Note / Search</label>
        <input id="a_led_text" type="text" placeholder="Search note text">
      </div>
      <div class="form-field">
        <label for="a_led_category">Category</label>
        <select id="a_led_category">
          <option value="">All</option>
          <?php foreach($allowedCategories as $categoryKey): ?>
            <option value="<?php echo htmlspecialchars($categoryKey); ?>"><?php echo htmlspecialchars(ledger_category_label_local($categoryKey, $categoryLabels)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_type">Entry Type</label>
        <select id="a_led_type">
          <option value="">All</option>
          <option value="debit">Debit</option>
          <option value="credit">Credit</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_mode">Mode</label>
        <select id="a_led_mode">
          <option value="">All</option>
          <option value="cash">Cash</option>
          <option value="account">Account</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_linked">Linked Bilty</label>
        <select id="a_led_linked">
          <option value="">All</option>
          <option value="feed">Feed</option>
          <option value="haleeb">Haleeb</option>
          <option value="unlinked">Unlinked</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_feed_section">Feed Section</label>
        <select id="a_led_feed_section">
          <option value="">All</option>
          <option value="ilyas">Ilyas</option>
          <option value="hamid">Hamid</option>
          <option value="amir">Amir</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_party">Party</label>
        <select id="a_led_party">
          <option value="">All</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_vehicle">Vehicle</label>
        <select id="a_led_vehicle">
          <option value="">All</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_added_by">Added By User</label>
        <select id="a_led_added_by">
          <option value="">All</option>
        </select>
      </div>
      <div class="form-field">
        <label for="a_led_amt_min">Min Amount</label>
        <input id="a_led_amt_min" type="number" step="any" min="0" placeholder="0">
      </div>
      <div class="form-field">
        <label for="a_led_amt_max">Max Amount</label>
        <input id="a_led_amt_max" type="number" step="any" min="0" placeholder="Any">
      </div>
      <div class="analytics-actions tiny-meta" id="ledger_analytics_count">Rows: 0</div>
    </div>
    <div class="analytics-stats" id="ledger_analytics_stats"></div>
    </div>
  </div>

  <!-- OVERALL STATS -->
  <?php
  $netPos = $netBalance >= 0;
  $dCash = $modeTotals['debit_cash'] ?? 0;
  $dAccount = $modeTotals['debit_account'] ?? 0;
  $cCash = $modeTotals['credit_cash'] ?? 0;
  $cAccount = $modeTotals['credit_account'] ?? 0;
  ?>
  <div class="stats-grid">
    <div class="stat-card debit">
      <div class="stat-label">Total Debit</div>
      <div class="stat-val red">Rs <?php echo format_amount_local((float)$totalDebit, 1); ?></div>
      <div class="stat-sub">Cash: <?php echo format_amount_local((float)$dCash, 1); ?> · Acc: <?php echo format_amount_local((float)$dAccount, 1); ?></div>
    </div>
    <div class="stat-card credit">
      <div class="stat-label">Total Credit</div>
      <div class="stat-val green">Rs <?php echo format_amount_local((float)$totalCredit, 1); ?></div>
      <div class="stat-sub">Cash: <?php echo format_amount_local((float)$cCash, 1); ?> · Acc: <?php echo format_amount_local((float)$cAccount, 1); ?></div>
    </div>
    <div class="stat-card neutral">
      <div class="stat-label">Cash Balance</div>
      <div class="stat-val <?php echo ((float)$cCash-(float)$dCash)>=0?'green':'red'; ?>">
        Rs <?php echo format_amount_local((float)$cCash-(float)$dCash, 1); ?>
      </div>
      <div class="stat-sub">Cash Credit - Debit</div>
    </div>
    <div class="stat-card <?php echo $netPos ? 'net-pos' : 'net-neg'; ?>">
      <div class="stat-label">Net Balance</div>
      <div class="stat-val <?php echo $netPos ? 'green' : 'red'; ?>">Rs <?php echo format_amount_local((float)$netBalance, 1); ?></div>
      <div class="stat-sub">Credit − Debit (All)</div>
    </div>
  </div>

  <!-- CATEGORY CARDS -->
  <?php
  if($cat === 'all') $categoriesToShow = $allowedCategories;
  elseif($cat === 'feed') $categoriesToShow = array_values(array_intersect($feedCategoryGroup, $allowedCategories));
  else $categoriesToShow = [$cat];
  ?>
  <div class="cat-cards">
    <?php foreach($categoriesToShow as $c):
      $d  = (float)($categoryTotals[$c]['debit_total'] ?? 0);
      $cr = (float)($categoryTotals[$c]['credit_total'] ?? 0);
      $dC = (float)($categoryTotals[$c]['debit_cash'] ?? 0);
      $dA = (float)($categoryTotals[$c]['debit_account'] ?? 0);
      $cC = (float)($categoryTotals[$c]['credit_cash'] ?? 0);
      $cA = (float)($categoryTotals[$c]['credit_account'] ?? 0);
      $n  = $cr - $d;
      $nC = $cC - $dC;
      $nA = $cA - $dA;
    ?>
    <div class="cat-card">
      <div class="cat-card-title"><?php echo htmlspecialchars(ledger_category_label_local($c, $categoryLabels)); ?></div>
      <div class="cat-line"><span class="cat-line-label">Debit</span><span class="cat-line-val val-debit">Rs <?php echo format_amount_local($d, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Credit</span><span class="cat-line-val val-credit">Rs <?php echo format_amount_local($cr, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Debit — Cash</span><span class="cat-line-val val-debit">Rs <?php echo format_amount_local($dC, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Debit — Account</span><span class="cat-line-val val-debit">Rs <?php echo format_amount_local($dA, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Credit — Cash</span><span class="cat-line-val val-credit">Rs <?php echo format_amount_local($cC, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Credit — Account</span><span class="cat-line-val val-credit">Rs <?php echo format_amount_local($cA, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Net Cash</span><span class="cat-line-val <?php echo $nC>=0?'val-net-pos':'val-net-neg'; ?>">Rs <?php echo format_amount_local($nC, 1); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Net Account</span><span class="cat-line-val <?php echo $nA>=0?'val-net-pos':'val-net-neg'; ?>">Rs <?php echo format_amount_local($nA, 1); ?></span></div>
      <div class="cat-line" style="border-top: 1px solid var(--border); margin-top:4px; padding-top:8px;"><span class="cat-line-label">Net Total</span><span class="cat-line-val <?php echo $n>=0?'val-net-pos':'val-net-neg'; ?>">Rs <?php echo format_amount_local($n, 1); ?></span></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ENTRIES TABLE -->
  <div class="table-wrap">
    <div class="tbl-header">
      <span class="tbl-title">Entries</span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Category</th>
          <th>Type</th>
          <th>Mode</th>
          <th>Amount</th>
          <th>Reference</th>
          <th>Note</th>
          <?php if($canManageLedger): ?><th class="col-action">Edit</th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="ledger_entries_tbody">
        <?php while($row = $entries->fetch_assoc()):
          $rType = strtolower($row['entry_type'] ?? '');
          $rMode = strtolower($row['amount_mode'] ?? 'cash');
          $refType = '';
          $refId = 0;
          if(isset($row['bilty_id']) && (int)$row['bilty_id'] > 0){
              $refType = 'feed';
              $refId = (int)$row['bilty_id'];
          } elseif(isset($row['haleeb_bilty_id']) && (int)$row['haleeb_bilty_id'] > 0){
              $refType = 'haleeb';
              $refId = (int)$row['haleeb_bilty_id'];
          } else {
              $refType = 'unlinked';
          }
          $linkedParty = trim((string)($row['linked_party'] ?? ''));
          $linkedVehicle = trim((string)($row['linked_vehicle'] ?? ''));
          $linkedAddedBy = trim((string)($row['linked_added_by'] ?? ''));
          $linkedFeedSectionKey = trim((string)($row['linked_feed_portion'] ?? ''));
          $linkedFeedSectionLabel = $linkedFeedSectionKey !== '' ? feed_portion_label_local($linkedFeedSectionKey) : '';
          $refHref = ($refId > 0 && in_array($refType, ['feed', 'haleeb'], true)) ? bilty_detail_link_local($refType, $refId, 'account') : '';
        ?>
        <tr
          data-ledger-row="1"
          data-date="<?php echo htmlspecialchars((string)($row['entry_date'] ?? '')); ?>"
          data-category="<?php echo htmlspecialchars(strtolower((string)($row['category'] ?? ''))); ?>"
          data-type="<?php echo htmlspecialchars($rType); ?>"
          data-mode="<?php echo htmlspecialchars($rMode); ?>"
          data-amount="<?php echo (float)($row['amount'] ?? 0); ?>"
          data-note="<?php echo htmlspecialchars(strtolower((string)($row['note'] ?? ''))); ?>"
          data-link-type="<?php echo htmlspecialchars($refType); ?>"
          data-feed-section="<?php echo htmlspecialchars(strtolower($linkedFeedSectionLabel)); ?>"
          data-party="<?php echo htmlspecialchars(strtolower($linkedParty)); ?>"
          data-party-label="<?php echo htmlspecialchars($linkedParty); ?>"
          data-vehicle="<?php echo htmlspecialchars(strtolower($linkedVehicle)); ?>"
          data-vehicle-label="<?php echo htmlspecialchars($linkedVehicle); ?>"
          data-added-by="<?php echo htmlspecialchars(strtolower($linkedAddedBy)); ?>"
          data-added-by-label="<?php echo htmlspecialchars($linkedAddedBy); ?>"
        >
          <td><?php echo htmlspecialchars($row['entry_date']); ?></td>
          <td><span class="cat-badge"><?php echo htmlspecialchars(ledger_category_label_local((string)$row['category'], $categoryLabels)); ?></span></td>
          <td><span class="type-badge type-<?php echo $rType; ?>"><?php echo ucfirst($rType); ?></span></td>
          <td><span class="mode-badge mode-<?php echo $rMode; ?>"><?php echo ucfirst($rMode); ?></span></td>
          <td class="<?php echo $rType==='debit'?'val-debit':'val-credit'; ?>">Rs <?php echo format_amount_local((float)$row['amount'], 1); ?></td>
          <td>
            <?php if($refHref !== ''): ?>
              <a class="ref-link" href="<?php echo htmlspecialchars($refHref); ?>"><?php echo htmlspecialchars(strtoupper($refType) . ' #' . $refId); ?></a>
            <?php else: ?>
              <span class="tiny-meta">-</span>
            <?php endif; ?>
          </td>
          <td class="td-note"><?php echo htmlspecialchars($row['note']); ?></td>
          <?php if($canManageLedger): ?>
            <td class="col-action">
              <a class="act-edit" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?><?php echo $feedSectionKey !== '' ? ('&feed_section=' . urlencode($feedSectionKey)) : ''; ?>&edit_id=<?php echo (int)$row['id']; ?>" title="Edit">&#9998;</a>
            </td>
          <?php endif; ?>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

</div>
<?php if($entryStmt) $entryStmt->close(); ?>
<script>
(function(){
  var toggleButtons = Array.prototype.slice.call(document.querySelectorAll('[data-toggle-target]'));
  if(toggleButtons.length === 0) return;

  toggleButtons.forEach(function(btn){
    var targetId = btn.getAttribute('data-toggle-target');
    if(!targetId) return;
    var body = document.getElementById(targetId);
    if(!body) return;
    var icon = btn.querySelector('.toggle-icon');

    function syncToggle(){
      var isOpen = body.classList.contains('open');
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if(icon) icon.textContent = isOpen ? '-' : '+';
    }

    btn.addEventListener('click', function(){
      body.classList.toggle('open');
      syncToggle();
    });

    syncToggle();
  });
})();

(function(){
  var rows = Array.prototype.slice.call(document.querySelectorAll('#ledger_entries_tbody tr[data-ledger-row="1"]'));
  if(rows.length === 0) return;
  var feedCategoryGroup = <?php echo json_encode(array_values(array_intersect($feedCategoryGroup, $allowedCategories)), JSON_UNESCAPED_UNICODE); ?>;
  var f = {
    text: document.getElementById('a_led_text'),
    category: document.getElementById('a_led_category'),
    type: document.getElementById('a_led_type'),
    mode: document.getElementById('a_led_mode'),
    linked: document.getElementById('a_led_linked'),
    feedSection: document.getElementById('a_led_feed_section'),
    party: document.getElementById('a_led_party'),
    vehicle: document.getElementById('a_led_vehicle'),
    addedBy: document.getElementById('a_led_added_by'),
    min: document.getElementById('a_led_amt_min'),
    max: document.getElementById('a_led_amt_max')
  };
  var resetBtn = document.getElementById('ledger_analytics_reset');
  var statsBox = document.getElementById('ledger_analytics_stats');
  var countBox = document.getElementById('ledger_analytics_count');

  function val(el){ return el ? String(el.value || '').trim().toLowerCase() : ''; }
  function num(el){
    if(!el) return null;
    var t = String(el.value || '').trim();
    if(t === '') return null;
    var n = Number(t);
    return Number.isFinite(n) ? n : null;
  }
  function money(v){ return Number(v || 0).toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:1}); }
  function inRange(v, min, max){
    if(min !== null && v < min) return false;
    if(max !== null && v > max) return false;
    return true;
  }
  function setSelectOptions(selectEl, items){
    if(!selectEl) return;
    while(selectEl.options.length > 1) selectEl.remove(1);
    items.forEach(function(item){
      var o = document.createElement('option');
      o.value = item.value;
      o.textContent = item.label;
      selectEl.appendChild(o);
    });
  }
  function collectOptions(valueKey, labelKey){
    var bucket = {};
    rows.forEach(function(r){
      var d = r.dataset || {};
      var value = String(d[valueKey] || '').trim();
      if(value === '' || bucket[value]) return;
      var label = String(d[labelKey] || '').trim();
      bucket[value] = label !== '' ? label : value;
    });
    return Object.keys(bucket).map(function(value){
      return { value: value, label: bucket[value] };
    }).sort(function(a, b){
      return a.label.localeCompare(b.label);
    });
  }

  setSelectOptions(f.party, collectOptions('party', 'partyLabel'));
  setSelectOptions(f.vehicle, collectOptions('vehicle', 'vehicleLabel'));
  setSelectOptions(f.addedBy, collectOptions('addedBy', 'addedByLabel'));
  var defaultFeedSection = <?php echo json_encode($feedSectionLabel !== '' ? strtolower($feedSectionLabel) : ''); ?>;
  if(defaultFeedSection && f.feedSection){ f.feedSection.value = defaultFeedSection; }

  function apply(){
    var x = {
      text: val(f.text),
      category: val(f.category),
      type: val(f.type),
      mode: val(f.mode),
      linked: val(f.linked),
      feedSection: val(f.feedSection),
      party: val(f.party),
      vehicle: val(f.vehicle),
      addedBy: val(f.addedBy),
      min: num(f.min),
      max: num(f.max)
    };
    var shown = 0, debit = 0, credit = 0, cash = 0, account = 0;
    rows.forEach(function(r){
      var d = r.dataset || {};
      var amount = Number(d.amount || 0);
      var ok = true;
      if(x.text && String(d.note || '').indexOf(x.text) === -1) ok = false;
      if(ok && x.category){
        var rowCategory = String(d.category || '');
        if(x.category === 'feed'){
          if(feedCategoryGroup.indexOf(rowCategory) === -1) ok = false;
        } else if(rowCategory !== x.category){
          ok = false;
        }
      }
      if(ok && x.type && String(d.type || '') !== x.type) ok = false;
      if(ok && x.mode && String(d.mode || '') !== x.mode) ok = false;
      if(ok && x.linked && String(d.linkType || '') !== x.linked) ok = false;
      if(ok && x.feedSection && String(d.feedSection || '') !== x.feedSection) ok = false;
      if(ok && x.party && String(d.party || '') !== x.party) ok = false;
      if(ok && x.vehicle && String(d.vehicle || '') !== x.vehicle) ok = false;
      if(ok && x.addedBy && String(d.addedBy || '') !== x.addedBy) ok = false;
      if(ok && !inRange(amount, x.min, x.max)) ok = false;
      r.style.display = ok ? '' : 'none';
      if(ok){
        shown += 1;
        if(String(d.type || '') === 'debit') debit += amount;
        else credit += amount;
        if(String(d.mode || '') === 'cash') cash += amount;
        if(String(d.mode || '') === 'account') account += amount;
      }
    });
    if(countBox) countBox.textContent = 'Rows: ' + shown;
    if(statsBox){
      statsBox.innerHTML = ''
        + '<div class="a-stat"><div class="k">Shown Entries</div><div class="v">' + shown + '</div></div>'
        + '<div class="a-stat"><div class="k">Shown Debit</div><div class="v" style="color:#ef4444;">Rs ' + money(debit) + '</div></div>'
        + '<div class="a-stat"><div class="k">Shown Credit</div><div class="v" style="color:#22c55e;">Rs ' + money(credit) + '</div></div>'
        + '<div class="a-stat"><div class="k">Shown Net</div><div class="v" style="color:' + (credit - debit >= 0 ? '#22c55e' : '#ef4444') + ';">Rs ' + money(credit - debit) + '</div></div>'
        + '<div class="a-stat"><div class="k">Cash Total</div><div class="v">Rs ' + money(cash) + '</div></div>'
        + '<div class="a-stat"><div class="k">Account Total</div><div class="v">Rs ' + money(account) + '</div></div>';
    }
  }

  Object.keys(f).forEach(function(k){
    if(!f[k]) return;
    f[k].addEventListener('input', apply);
    f[k].addEventListener('change', apply);
  });
  if(resetBtn){
    resetBtn.addEventListener('click', function(){
      Object.keys(f).forEach(function(k){
        if(!f[k]) return;
        if(f[k].tagName === 'SELECT') f[k].selectedIndex = 0;
        else f[k].value = '';
      });
      apply();
    });
  }
  apply();
})();

</script>
</body>
</html>

