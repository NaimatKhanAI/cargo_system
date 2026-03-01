<?php
session_start();
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('account');
$isSuperAdmin = auth_is_super_admin();
$canReviewActivity = auth_can_review_activity();
$canManageLedger = $isSuperAdmin;
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

function exec_prepared_result_local($conn, $sql, $types = '', $values = []){
    $stmt = $conn->prepare($sql);
    if(!$stmt) return [null, false];
    $count = count($values);
    if($count === 1) $stmt->bind_param($types, $values[0]);
    elseif($count === 2) $stmt->bind_param($types, $values[0], $values[1]);
    elseif($count === 3) $stmt->bind_param($types, $values[0], $values[1], $values[2]);
    $stmt->execute();
    $res = $stmt->get_result();
    return [$stmt, $res];
}

$allowedCategories = ['feed', 'haleeb', 'loan'];
$allowedTypes = ['debit', 'credit'];
$allowedModes = ['cash', 'account'];
$msg = ''; $err = '';
$formEntryDate = date('Y-m-d');
$formCategory = 'feed'; $formEntryType = 'debit'; $formAmountMode = 'cash';
$formAmount = ''; $formNote = '';
$editingId = 0;
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

if(isset($_POST['approve_pay_request']) || isset($_POST['reject_pay_request'])){
    if(!$canManageLedger){
        $err = 'Account ledger is view-only for your account.';
    } else {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $reviewNote = isset($_POST['review_note']) ? trim((string)$_POST['review_note']) : '';
        $reviewError = '';
        $ok = review_change_request_local($conn, $requestId, $currentUserId, isset($_POST['approve_pay_request']), $reviewNote, $reviewError, ['feed_pay', 'haleeb_pay']);
        if($ok){
            $msg = isset($_POST['approve_pay_request']) ? 'Payment request approved.' : 'Payment request rejected.';
        } else {
            $err = $reviewError !== '' ? $reviewError : 'Payment request review failed.';
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
if($cat !== 'all'){ $where[] = "category = ?"; $bindTypes .= 's'; $bindValues[] = $cat; }
$whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';
$dateQueryTail = '';
if($dateFrom !== '') $dateQueryTail .= '&date_from=' . urlencode($dateFrom);
if($dateTo !== '') $dateQueryTail .= '&date_to=' . urlencode($dateTo);

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

$entriesSql = "SELECT * FROM account_entries" . $whereSql . " ORDER BY entry_date DESC, id DESC";
list($entryStmt, $entries) = exec_prepared_result_local($conn, $entriesSql, $bindTypes, $bindValues);
$pendingPayRequests = $canManageLedger ? fetch_pending_change_requests_local($conn, [], ['feed_pay', 'haleeb_pay']) : [];
$flaggedActivityCount = activity_count_flagged_for_admin_local($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
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
    background: var(--surface); border: 1px solid var(--border); padding: 20px 24px; margin-bottom: 20px;
  }
  .form-panel-title {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
  }
  .form-panel-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }
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

  @media(max-width: 1100px) {
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .cat-cards { grid-template-columns: 1fr 1fr; }
    .form-row { grid-template-columns: 1fr 1fr 1fr; }
  }
  @media(max-width: 700px) {
    .topbar { padding: 14px 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .main { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .cat-cards { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge-pill">Ledger</span>
    <h1>Account</h1>
  </div>
  <div class="nav-links">
    <?php if($canReviewActivity): ?>
      <a class="nav-btn" href="activity_review.php">Activity Review<?php echo $flaggedActivityCount > 0 ? ' (' . $flaggedActivityCount . ')' : ''; ?></a>
    <?php endif; ?>
    <?php if($isSuperAdmin): ?><a class="nav-btn" href="super_admin.php">Super Admin</a><?php endif; ?>
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
                ?>
                <tr>
                  <td>#<?php echo (int)$r['id']; ?></td>
                  <td><?php echo htmlspecialchars(strtoupper((string)$r['module_key'])); ?></td>
                  <td><?php echo htmlspecialchars((string)($r['requested_by_name'] ?: ('User#' . (int)$r['requested_by']))); ?></td>
                  <td>
                    <div>Amount: <strong>Rs <?php echo number_format($amt, 2); ?></strong></div>
                    <div class="pay-req-note">Mode: <?php echo htmlspecialchars($mode); ?> | Category: <?php echo htmlspecialchars($category); ?> | Date: <?php echo htmlspecialchars($entryDate); ?></div>
                    <?php if($note !== ''): ?><div class="pay-req-note">Note: <?php echo htmlspecialchars($note); ?></div><?php endif; ?>
                  </td>
                  <td><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
                  <td>
                    <form method="post" class="pay-req-act">
                      <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
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
  <?php endif; ?>

  <!-- ENTRY FORM -->
  <?php if($canManageLedger): ?>
    <div class="form-panel">
      <div class="form-panel-title"><?php echo $editingId > 0 ? 'Edit Entry' : 'New Entry'; ?></div>
      <form method="post">
        <div class="form-row">
          <div class="form-field">
            <label>Date</label>
            <input type="date" name="entry_date" value="<?php echo htmlspecialchars($formEntryDate); ?>" required>
          </div>
          <div class="form-field">
            <label>Category</label>
            <select name="category" required>
              <option value="feed" <?php echo $formCategory==='feed'?'selected':''; ?>>Feed</option>
              <option value="haleeb" <?php echo $formCategory==='haleeb'?'selected':''; ?>>Haleeb</option>
              <option value="loan" <?php echo $formCategory==='loan'?'selected':''; ?>>Loan</option>
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
            <input type="number" step="0.01" min="0.01" name="amount" placeholder="0.00" value="<?php echo htmlspecialchars($formAmount); ?>" required>
          </div>
          <div class="form-field">
            <label>Note (optional)</label>
            <input type="text" name="note" placeholder="Note" value="<?php echo htmlspecialchars($formNote); ?>">
          </div>
          <div class="form-actions">
            <?php if($editingId > 0): ?>
              <input type="hidden" name="edit_id" value="<?php echo (int)$editingId; ?>">
              <button class="btn-submit" type="submit" name="update_entry">Update</button>
              <a class="btn-cancel" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">Cancel</a>
              <a class="btn-del" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&delete_id=<?php echo (int)$editingId; ?>" onclick="return confirm('Delete this entry?')" title="Delete">&#128465;</a>
            <?php else: ?>
              <button class="btn-submit" type="submit" name="add_entry">Save</button>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  <?php else: ?>
    <div class="form-panel" style="padding:14px 16px;">
      <div class="pay-req-note">View-only mode: you can only see ledger entries.</div>
    </div>
  <?php endif; ?>

  <!-- FILTER -->
  <div class="cat-panel">
    <div class="cat-filter-row">
      <a class="cat-btn <?php echo $cat==='all'?'active':''; ?>" href="account.php?cat=all<?php echo $dateQueryTail; ?>">All</a>
      <a class="cat-btn <?php echo $cat==='feed'?'active':''; ?>" href="account.php?cat=feed<?php echo $dateQueryTail; ?>">Feed</a>
      <a class="cat-btn <?php echo $cat==='haleeb'?'active':''; ?>" href="account.php?cat=haleeb<?php echo $dateQueryTail; ?>">Haleeb</a>
      <a class="cat-btn <?php echo $cat==='loan'?'active':''; ?>" href="account.php?cat=loan<?php echo $dateQueryTail; ?>">Loan</a>
    </div>
    <form class="date-filter" method="get">
      <input type="hidden" name="cat" value="<?php echo htmlspecialchars($cat); ?>">
      <div class="form-field">
        <label>From</label>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
      </div>
      <div class="form-field">
        <label>To</label>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
      </div>
      <button class="btn-apply" type="submit">Apply</button>
      <a class="btn-cancel" href="account.php?cat=<?php echo urlencode($cat); ?>">Reset</a>
    </form>
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
      <div class="stat-val red">Rs <?php echo number_format((float)$totalDebit, 2); ?></div>
      <div class="stat-sub">Cash: <?php echo number_format((float)$dCash,2); ?> · Acc: <?php echo number_format((float)$dAccount,2); ?></div>
    </div>
    <div class="stat-card credit">
      <div class="stat-label">Total Credit</div>
      <div class="stat-val green">Rs <?php echo number_format((float)$totalCredit, 2); ?></div>
      <div class="stat-sub">Cash: <?php echo number_format((float)$cCash,2); ?> · Acc: <?php echo number_format((float)$cAccount,2); ?></div>
    </div>
    <div class="stat-card neutral">
      <div class="stat-label">Cash Balance</div>
      <div class="stat-val <?php echo ((float)$cCash-(float)$dCash)>=0?'green':'red'; ?>">
        Rs <?php echo number_format((float)$cCash-(float)$dCash, 2); ?>
      </div>
      <div class="stat-sub">Cash Credit - Debit</div>
    </div>
    <div class="stat-card <?php echo $netPos ? 'net-pos' : 'net-neg'; ?>">
      <div class="stat-label">Net Balance</div>
      <div class="stat-val <?php echo $netPos ? 'green' : 'red'; ?>">Rs <?php echo number_format((float)$netBalance, 2); ?></div>
      <div class="stat-sub">Credit − Debit (All)</div>
    </div>
  </div>

  <!-- CATEGORY CARDS -->
  <?php $categoriesToShow = $cat === 'all' ? $allowedCategories : [$cat]; ?>
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
      <div class="cat-card-title"><?php echo ucfirst($c); ?></div>
      <div class="cat-line"><span class="cat-line-label">Debit</span><span class="cat-line-val val-debit">Rs <?php echo number_format($d,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Credit</span><span class="cat-line-val val-credit">Rs <?php echo number_format($cr,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Debit — Cash</span><span class="cat-line-val val-debit">Rs <?php echo number_format($dC,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Debit — Account</span><span class="cat-line-val val-debit">Rs <?php echo number_format($dA,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Credit — Cash</span><span class="cat-line-val val-credit">Rs <?php echo number_format($cC,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Credit — Account</span><span class="cat-line-val val-credit">Rs <?php echo number_format($cA,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Net Cash</span><span class="cat-line-val <?php echo $nC>=0?'val-net-pos':'val-net-neg'; ?>">Rs <?php echo number_format($nC,2); ?></span></div>
      <div class="cat-line"><span class="cat-line-label">Net Account</span><span class="cat-line-val <?php echo $nA>=0?'val-net-pos':'val-net-neg'; ?>">Rs <?php echo number_format($nA,2); ?></span></div>
      <div class="cat-line" style="border-top: 1px solid var(--border); margin-top:4px; padding-top:8px;"><span class="cat-line-label">Net Total</span><span class="cat-line-val <?php echo $n>=0?'val-net-pos':'val-net-neg'; ?>">Rs <?php echo number_format($n,2); ?></span></div>
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
          <th>Note</th>
          <?php if($canManageLedger): ?><th class="col-action">Edit</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php while($row = $entries->fetch_assoc()):
          $rType = strtolower($row['entry_type'] ?? '');
          $rMode = strtolower($row['amount_mode'] ?? 'cash');
        ?>
        <tr>
          <td><?php echo htmlspecialchars($row['entry_date']); ?></td>
          <td><span class="cat-badge"><?php echo htmlspecialchars(ucfirst($row['category'])); ?></span></td>
          <td><span class="type-badge type-<?php echo $rType; ?>"><?php echo ucfirst($rType); ?></span></td>
          <td><span class="mode-badge mode-<?php echo $rMode; ?>"><?php echo ucfirst($rMode); ?></span></td>
          <td class="<?php echo $rType==='debit'?'val-debit':'val-credit'; ?>">Rs <?php echo number_format((float)$row['amount'],2); ?></td>
          <td class="td-note"><?php echo htmlspecialchars($row['note']); ?></td>
          <?php if($canManageLedger): ?>
            <td class="col-action">
              <a class="act-edit" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&edit_id=<?php echo (int)$row['id']; ?>" title="Edit">&#9998;</a>
            </td>
          <?php endif; ?>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if($entryStmt) $entryStmt->close(); ?>
</body>
</html>

