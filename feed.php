<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';
require_once 'config/change_requests.php';
auth_require_login($conn);
auth_require_module_access('feed');
$canDirectModify = auth_can_direct_modify();
$isSuperAdmin = auth_is_super_admin();
$userFeedPortion = auth_get_feed_portion();
$userFeedPortionLabel = feed_portion_label_local($userFeedPortion);

if(isset($_GET['confirm_driver_pay'])){
    if(!$canDirectModify){
        header("location:feed.php?driver_pay=denied");
        exit();
    }
    $confirmId = isset($_GET['confirm_driver_pay']) ? (int)$_GET['confirm_driver_pay'] : 0;
    if($confirmId <= 0){
        header("location:feed.php?driver_pay=error");
        exit();
    }

    $rowStmt = $conn->prepare("SELECT id, date, bilty_no, freight_payment_type, COALESCE(original_freight, GREATEST((COALESCE(freight,0) - COALESCE(commission,0)), 0)) AS base_freight FROM bilty WHERE id=? LIMIT 1");
    $rowStmt->bind_param("i", $confirmId);
    $rowStmt->execute();
    $driverRow = $rowStmt->get_result()->fetch_assoc();
    $rowStmt->close();
    if(!$driverRow){
        header("location:feed.php?driver_pay=error");
        exit();
    }

    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE bilty_id=? AND entry_type='debit'");
    $paidStmt->bind_param("i", $confirmId);
    $paidStmt->execute();
    $paidRow = $paidStmt->get_result()->fetch_assoc();
    $paidStmt->close();

    $baseFreight = isset($driverRow['base_freight']) ? (float)$driverRow['base_freight'] : 0.0;
    $paidTotal = $paidRow && isset($paidRow['paid_total']) ? (float)$paidRow['paid_total'] : 0.0;
    $remaining = max(0, $baseFreight - $paidTotal);
    if($remaining <= 0.0001){
        header("location:feed.php?driver_pay=already");
        exit();
    }

    $pendingStmt = $conn->prepare("SELECT id FROM change_requests WHERE status='pending' AND action_type='feed_pay' AND entity_table='bilty' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $pendingStmt->bind_param("i", $confirmId);
    $pendingStmt->execute();
    $pendingRow = $pendingStmt->get_result()->fetch_assoc();
    $pendingStmt->close();
    if($pendingRow){
        header("location:feed.php?driver_pay=requested");
        exit();
    }

    $entryDate = isset($driverRow['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$driverRow['date']) ? (string)$driverRow['date'] : date('Y-m-d');
    $entryRef = isset($driverRow['bilty_no']) ? trim((string)$driverRow['bilty_no']) : '';
    $entryNote = 'Full Driver Payment Request - Feed Bilty ' . ($entryRef !== '' ? $entryRef : ('#' . $confirmId));
    $payload = [
        'entry_date' => $entryDate,
        'category' => 'feed',
        'amount_mode' => 'account',
        'amount' => round($remaining, 3),
        'note' => $entryNote
    ];
    $requestId = create_change_request_local($conn, 'feed', 'bilty', $confirmId, 'feed_pay', $payload, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

    if($requestId > 0){
        header("location:feed.php?driver_pay=requested");
    } else {
        header("location:feed.php?driver_pay=error");
    }
    exit();
}

if(isset($_GET['delete_all']) && $_GET['delete_all'] === '1'){
    if(!auth_can_direct_modify()){
        header("location:feed.php?clear=denied");
        exit();
    }
    $conn->begin_transaction();
    try{
        $countRow = $conn->query("SELECT COUNT(*) AS c FROM bilty")->fetch_assoc();
        $deletedCount = $countRow ? (int)$countRow['c'] : 0;

        if($deletedCount > 0){
            $conn->query("UPDATE account_entries e
                          JOIN bilty b ON e.bilty_id = b.id
                          SET e.amount = 0,
                              e.note = CONCAT(
                                  CASE
                                      WHEN COALESCE(NULLIF(e.note,''), '') = '' THEN CONCAT('Auto Driver Payment Request - Feed Bilty ', COALESCE(NULLIF(b.bilty_no,''), CONCAT('#', b.id)))
                                      ELSE e.note
                                  END,
                                  ' | Reversed on Bulk Delete - Feed Bilty ',
                                  COALESCE(NULLIF(b.bilty_no,''), CONCAT('#', b.id))
                              )
                          WHERE e.entry_type='debit' AND e.amount > 0");
            $conn->query("DELETE FROM bilty");
        }

        $conn->commit();
        header("location:feed.php?clear=success&deleted=" . $deletedCount);
        exit();
    } catch (Throwable $e){
        $conn->rollback();
        header("location:feed.php?clear=error");
        exit();
    }
}

$total_profit = 0;
if($isSuperAdmin){
    $total = $conn->query("SELECT SUM(COALESCE(tender,0) - GREATEST((COALESCE(freight,0) - COALESCE(commission,0)),0)) as t FROM bilty")->fetch_assoc();
    $total_profit = ($total && isset($total['t']) && $total['t'] !== null) ? (float)$total['t'] : 0;
}
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$vehicleSearch = isset($_GET['vehicle']) ? trim((string)$_GET['vehicle']) : '';

$import_message = "";
$import_report_url = "";
if (isset($_GET['import'])) {
    if ($_GET['import'] === 'success') {
        $ins = isset($_GET['ins']) ? (int)$_GET['ins'] : 0;
        $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;
        $import_message = "Import completed. Inserted: $ins, Skipped: $skip";
        if(isset($_GET['report'])){
            $report = basename((string)$_GET['report']);
            if($report !== ''){
                $import_report_url = "output/import_logs/" . rawurlencode($report);
            }
        }
    } elseif ($_GET['import'] === 'error') {
        $import_message = "Import failed. Please upload a valid CSV file.";
    }
}

$pay_message = "";
if (isset($_GET['pay'])) {
    if ($_GET['pay'] === 'success') $pay_message = "Payment posted successfully.";
    elseif ($_GET['pay'] === 'error') $pay_message = "Payment failed. Please try again.";
    elseif ($_GET['pay'] === 'requested') $pay_message = "Payment request sent to account ledger admin for approval.";
}
$driver_pay_message = "";
if(isset($_GET['driver_pay'])){
    if($_GET['driver_pay'] === 'success') $driver_pay_message = "Driver payment confirmed and debit posted.";
    elseif($_GET['driver_pay'] === 'requested') $driver_pay_message = "Full driver payment request sent for approval.";
    elseif($_GET['driver_pay'] === 'already') $driver_pay_message = "Driver payment already confirmed.";
    elseif($_GET['driver_pay'] === 'denied') $driver_pay_message = "Only super admin can confirm driver payment.";
    elseif($_GET['driver_pay'] === 'error') $driver_pay_message = "Driver payment request failed. Please try again.";
}

$clear_message = "";
if(isset($_GET['clear'])){
    if($_GET['clear'] === 'success'){
        $deletedCount = isset($_GET['deleted']) ? (int)$_GET['deleted'] : 0;
        $clear_message = "All Feed bilties deleted. Removed: " . $deletedCount;
    } elseif($_GET['clear'] === 'error'){
        $clear_message = "Delete all failed. Please try again.";
    } elseif($_GET['clear'] === 'denied'){
        $clear_message = "Only super admin can delete all bilties.";
    }
}

$request_message = "";
if(isset($_GET['req']) && $_GET['req'] === 'submitted'){
    $request_message = "Change request sent to super admin for approval.";
} elseif(isset($_GET['req']) && $_GET['req'] === 'failed'){
    $request_message = "Could not create request. Please try again.";
}

$vehicleOptions = [];
if($isSuperAdmin){
    $vehicleRes = $conn->query("SELECT DISTINCT vehicle FROM bilty WHERE vehicle IS NOT NULL AND vehicle <> '' ORDER BY vehicle ASC");
    while($vehicleRes && $vrow = $vehicleRes->fetch_assoc()){
        $vehicleOptions[] = (string)$vrow['vehicle'];
    }
} else {
    $vehicleStmt = $conn->prepare("SELECT DISTINCT vehicle FROM bilty WHERE feed_portion=? AND vehicle IS NOT NULL AND vehicle <> '' ORDER BY vehicle ASC");
    $vehicleStmt->bind_param("s", $userFeedPortion);
    $vehicleStmt->execute();
    $vehicleRes = $vehicleStmt->get_result();
    while($vehicleRes && $vrow = $vehicleRes->fetch_assoc()){
        $vehicleOptions[] = (string)$vrow['vehicle'];
    }
    $vehicleStmt->close();
}

$where = []; $bindValues = []; $bindTypes = "";
if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){ $where[] = "date >= ?"; $bindTypes .= "s"; $bindValues[] = $dateFrom; }
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){ $where[] = "date <= ?"; $bindTypes .= "s"; $bindValues[] = $dateTo; }
if($vehicleSearch !== ''){ $where[] = "vehicle LIKE ?"; $bindTypes .= "s"; $bindValues[] = "%" . $vehicleSearch . "%"; }
if(!$isSuperAdmin){ $where[] = "feed_portion = ?"; $bindTypes .= "s"; $bindValues[] = $userFeedPortion; }

$sql = "SELECT b.*,
        COALESCE(NULLIF(u.username, ''), CASE WHEN b.added_by_user_id IS NULL THEN '-' ELSE CONCAT('User#', b.added_by_user_id) END) AS added_by_name,
        GREATEST((COALESCE(b.freight,0) - COALESCE(b.commission,0)), 0) AS total_cost,
        (COALESCE(b.tender,0) - GREATEST((COALESCE(b.freight,0) - COALESCE(b.commission,0)), 0)) AS calc_profit,
        GREATEST(COALESCE(b.original_freight, GREATEST((COALESCE(b.freight,0) - COALESCE(b.commission,0)), 0)) - COALESCE(p.paid_total, 0), 0) AS remaining_balance
        FROM bilty b
        LEFT JOIN users u ON u.id = b.added_by_user_id
        LEFT JOIN (
          SELECT bilty_id, SUM(amount) AS paid_total
          FROM account_entries
          WHERE bilty_id IS NOT NULL AND entry_type='debit'
          GROUP BY bilty_id
        ) p ON p.bilty_id = b.id";
if(count($where) > 0){
    $whereSql = [];
    foreach($where as $w){
        $whereSql[] = str_replace(["date", "vehicle", "feed_portion"], ["b.date", "b.vehicle", "b.feed_portion"], $w);
    }
    $sql .= " WHERE " . implode(" AND ", $whereSql);
}
$sql .= " ORDER BY id DESC";

if(count($bindValues) > 0){
    $stmt = $conn->prepare($sql);
    $params = [$bindTypes];
    foreach($bindValues as $k => $v){ $params[] = &$bindValues[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Feed</title>
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
    --accent2: #3b82f6;
    --green: #22c55e;
    --red: #ef4444;
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
  .topbar-logo .badge {
    background: var(--accent); color: #0e0f11; font-size: 10px;
    font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase;
  }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 6px; }
  .nav-btn {
    padding: 8px 16px; background: transparent; color: var(--muted);
    border: 1px solid var(--border); cursor: pointer; text-decoration: none;
    font-family: var(--font); font-size: 13px; font-weight: 600;
    transition: all 0.15s; letter-spacing: 0.3px;
  }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .nav-btn.primary { background: var(--accent); color: #0e0f11; border-color: var(--accent); }
  .nav-btn.primary:hover { background: #e0b030; }
  .nav-btn.danger { background: rgba(239,68,68,0.12); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .nav-btn.danger:hover { background: rgba(239,68,68,0.22); color: var(--red); border-color: rgba(239,68,68,0.35); }

  /* MENU */
  .menu-wrap { position: relative; }
  .menu-trigger {
    width: 36px; height: 36px; background: var(--surface2); border: 1px solid var(--border);
    color: var(--text); cursor: pointer; font-size: 16px; display: flex; align-items: center;
    justify-content: center;
  }
  .menu-trigger:hover { border-color: var(--muted); }
  .menu-pop {
    display: none; position: absolute; right: 0; top: calc(100% + 6px);
    background: var(--surface); border: 1px solid var(--border);
    padding: 8px; min-width: 220px; z-index: 200;
    box-shadow: 0 20px 40px rgba(0,0,0,0.6);
  }
  .menu-pop.open { display: block; }
  .menu-pop .nav-btn { display: block; margin: 3px 0; text-align: left; width: 100%; }
  .menu-sep { height: 1px; background: var(--border); margin: 8px 0; }
  .import-row { padding: 4px 0; }
  .import-row input[type="file"] { font-size: 11px; color: var(--muted); width: 100%; margin-bottom: 6px; }
  .import-row input[type="file"]::file-selector-button {
    background: var(--surface2); color: var(--text); border: 1px solid var(--border);
    padding: 4px 10px; font-family: var(--font); font-size: 11px; cursor: pointer;
  }

  /* MAIN */
  .main { padding: 24px 28px; max-width: 1400px; margin: 0 auto; }

  /* ALERTS */
  .alert {
    padding: 12px 16px; margin-bottom: 16px; font-size: 13px;
    border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green);
  }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

  /* PROFIT BANNER */
  .profit-banner {
    background: var(--surface); border: 1px solid var(--border);
    padding: 20px 24px; margin-bottom: 20px;
    display: flex; align-items: center; justify-content: space-between;
    position: relative; overflow: hidden;
  }
  .profit-banner::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--accent);
  }
  .profit-label { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 4px; }
  .profit-value { font-family: var(--mono); font-size: 28px; font-weight: 500; color: var(--accent); }

  /* SEARCH */
  .search-panel {
    background: var(--surface); border: 1px solid var(--border); padding: 16px 20px; margin-bottom: 20px;
  }
  .search-form {
    display: grid; grid-template-columns: 1fr 1fr 1.5fr auto; gap: 12px; align-items: end;
  }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .field input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 9px 12px; font-family: var(--font); font-size: 13px;
    transition: border-color 0.15s;
  }
  .field input:focus { outline: none; border-color: var(--accent); }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .search-actions { display: flex; gap: 8px; }
  .btn-ghost {
    padding: 9px 16px; background: transparent; color: var(--muted); border: 1px solid var(--border);
    cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600;
    transition: all 0.15s;
  }
  .btn-ghost:hover { color: var(--text); border-color: var(--muted); }

  /* TABLE */
  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; border-bottom: 1px solid var(--border);
  }
  .tbl-header-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: var(--surface2); }
  th {
    padding: 11px 14px; text-align: left; font-size: 10px; font-weight: 700;
    letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted);
    border-bottom: 1px solid var(--border); white-space: nowrap;
  }
  td {
    padding: 11px 14px; font-size: 13px; border-bottom: 1px solid rgba(42,45,53,0.7);
    font-family: var(--mono); color: var(--text);
  }
  tbody tr { transition: background 0.1s; }
  tbody tr:hover { background: var(--surface2); }
  .td-profit { color: var(--green); font-weight: 500; }
  .td-profit.neg { color: var(--red); }

  /* ACTION BTNS */
  .action-cell { display: flex; gap: 4px; justify-content: center; }
  .act-btn {
    width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
    text-decoration: none; font-size: 13px; border: 1px solid transparent;
    transition: all 0.15s; cursor: pointer;
  }
  .act-pay { background: rgba(34,197,94,0.15); color: var(--green); border-color: rgba(34,197,94,0.25); }
  .act-pay:hover { background: rgba(34,197,94,0.25); }
  .act-confirm { background: rgba(16,185,129,0.15); color: #10b981; border-color: rgba(16,185,129,0.25); }
  .act-confirm:hover { background: rgba(16,185,129,0.25); }
  .act-edit { background: rgba(240,192,64,0.15); color: var(--accent); border-color: rgba(240,192,64,0.25); }
  .act-edit:hover { background: rgba(240,192,64,0.25); }
  .act-pdf { background: rgba(59,130,246,0.15); color: var(--accent2); border-color: rgba(59,130,246,0.25); }
  .act-pdf:hover { background: rgba(59,130,246,0.25); }

  .th-action { text-align: center; width: 140px; }
  .paytype-badge {
    display: inline-block; padding: 3px 9px; font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase; border: 1px solid transparent;
  }
  .type-to-pay { background: rgba(245,158,11,0.14); color: #f59e0b; border-color: rgba(245,158,11,0.24); }
  .type-paid { background: rgba(59,130,246,0.14); color: #60a5fa; border-color: rgba(59,130,246,0.24); }
  .rem-badge {
    display: inline-block; padding: 3px 9px; font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase; border: 1px solid transparent;
  }
  .rem-zero { background: rgba(34,197,94,0.15); color: var(--green); border-color: rgba(34,197,94,0.25); }
  .rem-pending { background: rgba(239,68,68,0.15); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .analytics-wrap {
    background: var(--surface); border: 1px solid var(--border); margin-bottom: 20px;
    display: none;
  }
  .analytics-wrap.open { display: block; }
  .analytics-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; border-bottom: 1px solid var(--border);
  }
  .analytics-grid {
    padding: 14px 16px; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px;
  }
  .analytics-grid .field input, .analytics-grid .field select {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 8px 10px; font-family: var(--font); font-size: 12px;
  }
  .analytics-grid .field select:focus, .analytics-grid .field input:focus { outline: none; border-color: var(--accent); }
  .analytics-stats { padding: 0 16px 14px; display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
  .a-stat { background: var(--surface2); border: 1px solid var(--border); padding: 10px; }
  .a-stat .k { font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 4px; }
  .a-stat .v { font-size: 17px; font-family: var(--mono); }
  .analytics-charts { padding: 0 16px 16px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
  .chart-box { background: var(--surface2); border: 1px solid var(--border); padding: 10px; min-height: 180px; }
  .chart-title { font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
  .bar-list { display: grid; gap: 6px; }
  .bar-row { display: grid; grid-template-columns: 120px 1fr auto; gap: 8px; align-items: center; }
  .bar-label { font-size: 11px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .bar-track { background: #121317; border: 1px solid var(--border); height: 10px; position: relative; }
  .bar-fill { background: linear-gradient(90deg, var(--accent), var(--accent2)); height: 100%; width: 0; }
  .bar-val { font-size: 11px; color: var(--muted); font-family: var(--mono); }
  .split-wrap { display: grid; gap: 8px; }
  .split-track { background: #121317; border: 1px solid var(--border); height: 14px; display: flex; overflow: hidden; }
  .split-paid { background: rgba(34,197,94,0.85); height: 100%; }
  .split-rem { background: rgba(239,68,68,0.85); height: 100%; }
  .split-meta { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); font-family: var(--mono); }

  @media(max-width: 900px) {
    .search-form { grid-template-columns: 1fr 1fr; }
    .search-form .field:nth-child(3) { grid-column: 1 / -1; }
    .search-actions { grid-column: 1 / -1; justify-content: flex-end; }
    .analytics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .analytics-charts { grid-template-columns: 1fr; }
  }
  @media(max-width: 640px) {
    .topbar { padding: 14px 16px; }
    .main { padding: 16px; }
    .topbar h1 { font-size: 15px; }
    .search-form { grid-template-columns: 1fr; }
    .nav-btn { padding: 7px 10px; font-size: 12px; }
    .analytics-grid { grid-template-columns: 1fr; }
    .analytics-stats { grid-template-columns: 1fr; }
    .bar-row { grid-template-columns: 90px 1fr auto; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Feed</span>
    <h1>Feed<?php echo $isSuperAdmin ? '' : (' - ' . htmlspecialchars($userFeedPortionLabel)); ?></h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn primary" href="add_bilty.php<?php echo $isSuperAdmin ? '' : ('?portion=' . rawurlencode($userFeedPortion)); ?>">Add Bilty</a>
    <?php if($isSuperAdmin): ?>
      <button class="nav-btn" type="button" id="feed_analytics_toggle">Analytics</button>
    <?php endif; ?>
    <?php if($isSuperAdmin): ?>
      <a class="nav-btn" href="haleeb.php">Haleeb</a>
      <a class="nav-btn" href="super_admin.php">Super Admin</a>
      <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <?php endif; ?>
    <div class="menu-wrap">
      <button class="menu-trigger" id="feed_menu_btn" type="button" aria-label="Menu">&#9776;</button>
      <div class="menu-pop" id="feed_menu_pop">
        <a class="nav-btn" href="dashboard.php">Dashboard</a>
        <a class="nav-btn" href="request_status.php">View Request Status</a>
        <?php if($isSuperAdmin): ?>
          <button class="nav-btn" type="button" id="feed_analytics_toggle_menu">Analytics</button>
        <?php endif; ?>
        <?php if($isSuperAdmin): ?>
          <div class="menu-sep"></div>
          <a class="nav-btn" href="feed_ratelist.php">Rate List</a>
          <a class="nav-btn" href="export_bilty.php">Export CSV</a>
          <?php if($canDirectModify): ?>
            <a class="nav-btn danger" href="feed.php?delete_all=1" onclick="return confirm('Delete all Feed bilties?')">Delete All Bilties</a>
          <?php endif; ?>
          <div class="menu-sep"></div>
          <div class="import-row">
            <form action="import_bilty.php" method="post" enctype="multipart/form-data">
              <input type="file" name="csv_file" accept=".csv" required>
              <button class="nav-btn" type="submit" style="width:100%">Import CSV</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="main">
  <?php if($import_message !== ""): ?>
    <div class="alert <?php echo strpos($import_message,'failed') !== false ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($import_message); ?>
      <?php if($import_report_url !== ""): ?>
        <br><a href="<?php echo htmlspecialchars($import_report_url); ?>" target="_blank" style="color:inherit;text-decoration:underline;">View import report</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if($pay_message !== ""): ?>
    <div class="alert <?php echo $pay_message === 'Payment failed. Please try again.' ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($pay_message); ?>
    </div>
  <?php endif; ?>
  <?php if($driver_pay_message !== ""): ?>
    <div class="alert <?php echo (strpos($driver_pay_message, 'failed') !== false || strpos($driver_pay_message, 'Only super admin') !== false) ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($driver_pay_message); ?>
    </div>
  <?php endif; ?>
  <?php if($clear_message !== ""): ?>
    <div class="alert <?php echo strpos($clear_message,'failed') !== false ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($clear_message); ?>
    </div>
  <?php endif; ?>
  <?php if($request_message !== ""): ?>
    <div class="alert"><?php echo htmlspecialchars($request_message); ?></div>
  <?php endif; ?>

  <?php if($isSuperAdmin): ?>
    <div class="profit-banner">
      <div>
        <div class="profit-label">Total Profit</div>
        <div class="profit-value"><?php echo number_format((float)$total_profit, 2); ?></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="search-panel">
    <form class="search-form" method="get">
      <div class="field">
        <label for="date_from">From</label>
        <input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
      </div>
      <div class="field">
        <label for="date_to">To</label>
        <input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
      </div>
      <div class="field">
        <label for="vehicle">Vehicle</label>
        <input id="vehicle" name="vehicle" list="vehicle_list" placeholder="Vehicle" value="<?php echo htmlspecialchars($vehicleSearch); ?>">
        <datalist id="vehicle_list">
          <?php foreach($vehicleOptions as $opt): ?>
            <option value="<?php echo htmlspecialchars($opt); ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="search-actions">
        <button class="nav-btn primary" type="submit">Search</button>
        <a class="btn-ghost" href="feed.php">Reset</a>
      </div>
    </form>
  </div>

  <?php if($isSuperAdmin): ?>
  <div class="analytics-wrap" id="feed_analytics_wrap">
    <div class="analytics-head">
      <span class="tbl-header-title">Analytics</span>
      <button class="nav-btn" type="button" id="feed_analytics_reset">Reset Analytics</button>
    </div>
    <div class="analytics-grid">
      <div class="field">
        <label for="a_feed_text">Bilty / SR / Vehicle</label>
        <input id="a_feed_text" list="a_feed_text_list" placeholder="Search">
      </div>
      <div class="field">
        <label for="a_feed_party">Party</label>
        <input id="a_feed_party" list="a_feed_party_list" placeholder="Party">
      </div>
      <div class="field">
        <label for="a_feed_location">Location</label>
        <input id="a_feed_location" list="a_feed_location_list" placeholder="Location">
      </div>
      <div class="field">
        <label for="a_feed_status">Payment Status</label>
        <select id="a_feed_status">
          <option value="">All</option>
          <option value="confirmed">Confirmed</option>
          <option value="pending">Pending</option>
        </select>
      </div>
      <?php if($isSuperAdmin): ?>
      <div class="field">
        <label for="a_feed_section">Section</label>
        <select id="a_feed_section">
          <option value="">All</option>
          <?php foreach(feed_portion_options_local() as $portionLabel): ?>
            <option value="<?php echo htmlspecialchars(strtolower((string)$portionLabel)); ?>"><?php echo htmlspecialchars($portionLabel); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field">
        <label for="a_feed_user">Added By</label>
        <input id="a_feed_user" list="a_feed_user_list" placeholder="User">
      </div>
      <div class="field">
        <label for="a_feed_bags_min">Min Bags</label>
        <input id="a_feed_bags_min" type="number" min="0" placeholder="0">
      </div>
      <div class="field">
        <label for="a_feed_bags_max">Max Bags</label>
        <input id="a_feed_bags_max" type="number" min="0" placeholder="Any">
      </div>
      <div class="field">
        <label for="a_feed_freight_min">Min Total Cost</label>
        <input id="a_feed_freight_min" type="number" min="0" placeholder="0">
      </div>
      <div class="field">
        <label for="a_feed_freight_max">Max Total Cost</label>
        <input id="a_feed_freight_max" type="number" min="0" placeholder="Any">
      </div>
      <?php if($isSuperAdmin): ?>
      <div class="field">
        <label for="a_feed_profit_min">Min Profit</label>
        <input id="a_feed_profit_min" type="number" placeholder="Any">
      </div>
      <?php endif; ?>

      <datalist id="a_feed_text_list"></datalist>
      <datalist id="a_feed_party_list">
        <?php
        $partyOptionsAnalytics = [];
        $partyResAnalytics = $conn->query("SELECT DISTINCT party FROM bilty WHERE party IS NOT NULL AND party <> '' ORDER BY party ASC");
        while($partyResAnalytics && $prow = $partyResAnalytics->fetch_assoc()){ $partyOptionsAnalytics[] = (string)$prow['party']; }
        foreach($partyOptionsAnalytics as $opt):
        ?>
          <option value="<?php echo htmlspecialchars($opt); ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="a_feed_location_list">
        <?php
        $locationOptionsAnalytics = [];
        $locationResAnalytics = $conn->query("SELECT DISTINCT location FROM bilty WHERE location IS NOT NULL AND location <> '' ORDER BY location ASC");
        while($locationResAnalytics && $lrow = $locationResAnalytics->fetch_assoc()){ $locationOptionsAnalytics[] = (string)$lrow['location']; }
        foreach($locationOptionsAnalytics as $opt):
        ?>
          <option value="<?php echo htmlspecialchars($opt); ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="a_feed_user_list"></datalist>
    </div>
    <div class="analytics-stats" id="feed_analytics_stats"></div>
    <div class="analytics-charts">
      <div class="chart-box">
        <div class="chart-title">Top Vehicles (Count)</div>
        <div class="bar-list" id="feed_chart_vehicles"></div>
      </div>
      <div class="chart-box">
        <div class="chart-title">Top Locations (Freight)</div>
        <div class="bar-list" id="feed_chart_locations"></div>
      </div>
      <div class="chart-box">
        <div class="chart-title">Daily Freight Trend</div>
        <div class="bar-list" id="feed_chart_trend"></div>
      </div>
      <div class="chart-box">
        <div class="chart-title">Collection Split</div>
        <div class="split-wrap">
          <div class="split-track">
            <div class="split-paid" id="feed_split_paid"></div>
            <div class="split-rem" id="feed_split_rem"></div>
          </div>
          <div class="split-meta">
            <span id="feed_split_paid_label">Paid: 0</span>
            <span id="feed_split_rem_label">Remaining: 0</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <div class="tbl-header">
      <span class="tbl-header-title">Records</span>
    </div>
    <table id="feed_records_table">
      <thead>
        <tr>
          <th>SR.</th>
          <th>Date</th>
          <th>Added By</th>
          <?php if($isSuperAdmin): ?><th>Section</th><?php endif; ?>
          <th>Vehicle</th>
          <th>Bilty No.</th>
          <th>Party</th>
          <th>Location</th>
          <th>Bags</th>
          <th>Freight</th>
          <th>Commission</th>
          <th>Driver Payment</th>
          <th>Status</th>
          <?php if($isSuperAdmin): ?><th>Tender</th><?php endif; ?>
          <th>Remaining</th>
          <?php if($isSuperAdmin): ?><th>Profit</th><?php endif; ?>
          <th class="th-action">Actions</th>
        </tr>
      </thead>
      <tbody id="feed_records_tbody">
        <?php while($row = $result->fetch_assoc()):
          $profit = (float)$row['calc_profit'];
          $remaining = (float)($row['remaining_balance'] ?? 0);
          $commission = (float)($row['commission'] ?? 0);
          $totalCost = (float)($row['total_cost'] ?? max(((float)($row['freight'] ?? 0)) - $commission, 0));
          $addedByName = isset($row['added_by_name']) && trim((string)$row['added_by_name']) !== '' ? (string)$row['added_by_name'] : '-';
          $paymentTypeRaw = isset($row['freight_payment_type']) ? strtolower(trim((string)$row['freight_payment_type'])) : 'to_pay';
          if(!in_array($paymentTypeRaw, ['to_pay', 'paid'], true)){ $paymentTypeRaw = 'to_pay'; }
          $paymentTypeLabel = $paymentTypeRaw === 'paid' ? 'Paid' : 'To Pay';
          $driverStatus = $remaining <= 0.0001 ? 'Confirmed' : 'Pending';
          $sectionLabel = $isSuperAdmin ? feed_portion_label_local(isset($row['feed_portion']) ? $row['feed_portion'] : '') : '';
        ?>
        <tr data-analytics-row="1"
            data-date="<?php echo htmlspecialchars((string)($row['date'] ?? '')); ?>"
            data-sr="<?php echo htmlspecialchars((string)($row['sr_no'] ?? '')); ?>"
            data-bilty="<?php echo htmlspecialchars((string)($row['bilty_no'] ?? '')); ?>"
            data-vehicle="<?php echo htmlspecialchars((string)($row['vehicle'] ?? '')); ?>"
            data-party="<?php echo htmlspecialchars((string)($row['party'] ?? '')); ?>"
            data-location="<?php echo htmlspecialchars((string)($row['location'] ?? '')); ?>"
            data-user="<?php echo htmlspecialchars((string)$addedByName); ?>"
            data-section="<?php echo htmlspecialchars((string)$sectionLabel); ?>"
            data-bags="<?php echo (int)($row['bags'] ?? 0); ?>"
            data-freight="<?php echo (float)($row['freight'] ?? 0); ?>"
            data-total="<?php echo $totalCost; ?>"
            data-commission="<?php echo $commission; ?>"
            data-tender="<?php echo (float)($row['tender'] ?? 0); ?>"
            data-remaining="<?php echo $remaining; ?>"
            data-profit="<?php echo $profit; ?>">
          <td><?php echo htmlspecialchars($row['sr_no']); ?></td>
          <td><?php echo htmlspecialchars($row['date']); ?></td>
          <td><?php echo htmlspecialchars($addedByName); ?></td>
          <?php if($isSuperAdmin): ?>
            <td><?php echo htmlspecialchars($sectionLabel); ?></td>
          <?php endif; ?>
          <td><?php echo htmlspecialchars($row['vehicle']); ?></td>
          <td><?php echo htmlspecialchars($row['bilty_no']); ?></td>
          <td><?php echo htmlspecialchars($row['party']); ?></td>
          <td><?php echo htmlspecialchars($row['location']); ?></td>
          <td><?php echo (int)($row['bags'] ?? 0); ?></td>
          <td><?php echo number_format((float)$row['freight'], 2); ?></td>
          <td><?php echo number_format($commission, 2); ?></td>
          <td>
            <span class="paytype-badge <?php echo $paymentTypeRaw === 'paid' ? 'type-paid' : 'type-to-pay'; ?>">
              <?php echo htmlspecialchars($paymentTypeLabel); ?>
            </span>
          </td>
          <td>
            <span class="rem-badge <?php echo $remaining <= 0.0001 ? 'rem-zero' : 'rem-pending'; ?>">
              <?php echo htmlspecialchars($driverStatus); ?>
            </span>
          </td>
          <?php if($isSuperAdmin): ?>
            <td><?php echo number_format((float)$row['tender'], 2); ?></td>
          <?php endif; ?>
          <td>
            <span class="rem-badge <?php echo $remaining <= 0 ? 'rem-zero' : 'rem-pending'; ?>">
              <?php echo number_format($remaining, 2); ?>
            </span>
          </td>
          <?php if($isSuperAdmin): ?>
            <td class="td-profit <?php echo $profit < 0 ? 'neg' : ''; ?>">
              <?php echo number_format($profit, 2); ?>
            </td>
          <?php endif; ?>
          <td>
            <div class="action-cell">
              <a class="act-btn act-pay" href="pay_now.php?id=<?php echo $row['id']; ?>" title="Pay">&#8377;</a>
              <?php if($canDirectModify && $paymentTypeRaw === 'to_pay' && $remaining > 0.0001): ?>
                <a class="act-btn act-confirm" href="feed.php?confirm_driver_pay=<?php echo (int)$row['id']; ?>" title="Request Full Driver Payment" onclick="return confirm('Send full driver payment request for approval?')">&#10003;</a>
              <?php endif; ?>
              <a class="act-btn act-edit" href="edit.php?id=<?php echo $row['id']; ?>" title="Edit">&#9998;</a>
              <a class="act-btn act-pdf" href="pdf.php?id=<?php echo $row['id']; ?>" target="_blank" title="PDF">&#128196;</a>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var btn = document.getElementById('feed_menu_btn');
  var pop = document.getElementById('feed_menu_pop');
  if(btn && pop){
    btn.addEventListener('click', function(e){ e.stopPropagation(); pop.classList.toggle('open'); });
    document.addEventListener('click', function(e){ if(!pop.contains(e.target) && e.target !== btn) pop.classList.remove('open'); });
  }
})();

(function(){
  var isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
  var wrap = document.getElementById('feed_analytics_wrap');
  var toggleA = document.getElementById('feed_analytics_toggle');
  var toggleB = document.getElementById('feed_analytics_toggle_menu');
  if(!wrap) return;

  var statsBox = document.getElementById('feed_analytics_stats');
  var rows = Array.prototype.slice.call(document.querySelectorAll('#feed_records_tbody tr[data-analytics-row=\"1\"]'));
  var records = rows.map(function(row){
    var d = row.dataset || {};
    var freight = Number(d.freight || 0);
    var commission = Number(d.commission || 0);
    var total = Number(d.total || Math.max(freight - commission, 0));
    var remaining = Number(d.remaining || 0);
    return {
      el: row,
      date: String(d.date || ''),
      sr: String(d.sr || ''),
      srL: String(d.sr || '').toLowerCase(),
      bilty: String(d.bilty || ''),
      biltyL: String(d.bilty || '').toLowerCase(),
      vehicle: String(d.vehicle || ''),
      vehicleL: String(d.vehicle || '').toLowerCase(),
      party: String(d.party || ''),
      partyL: String(d.party || '').toLowerCase(),
      location: String(d.location || ''),
      locationL: String(d.location || '').toLowerCase(),
      addedBy: String(d.user || ''),
      addedByL: String(d.user || '').toLowerCase(),
      section: String(d.section || ''),
      sectionL: String(d.section || '').toLowerCase(),
      bags: Number(d.bags || 0),
      freight: freight,
      commission: commission,
      totalCost: total,
      tender: Number(d.tender || 0),
      remaining: remaining,
      paid: Math.max(total - remaining, 0),
      profit: Number(d.profit || 0)
    };
  });

  function fillDatalist(id, values, maxItems){
    var list = document.getElementById(id);
    if(!list) return;
    var seen = {};
    var out = [];
    for(var i = 0; i < values.length; i += 1){
      var raw = String(values[i] || '').trim();
      if(!raw) continue;
      var key = raw.toLowerCase();
      if(seen[key]) continue;
      seen[key] = true;
      out.push(raw);
      if(maxItems && out.length >= maxItems) break;
    }
    list.innerHTML = out.map(function(v){
      var safe = escHtml(v);
      return '<option value="' + safe + '"></option>';
    }).join('');
  }

  fillDatalist('a_feed_text_list', records.reduce(function(arr, r){
    arr.push(r.sr, r.bilty, r.vehicle);
    return arr;
  }, []), 300);
  fillDatalist('a_feed_party_list', records.map(function(r){ return r.party; }), 300);
  fillDatalist('a_feed_location_list', records.map(function(r){ return r.location; }), 300);
  fillDatalist('a_feed_user_list', records.map(function(r){ return r.addedBy; }), 300);

  var f = {
    text: document.getElementById('a_feed_text'),
    party: document.getElementById('a_feed_party'),
    location: document.getElementById('a_feed_location'),
    user: document.getElementById('a_feed_user'),
    status: document.getElementById('a_feed_status'),
    section: document.getElementById('a_feed_section'),
    bagsMin: document.getElementById('a_feed_bags_min'),
    bagsMax: document.getElementById('a_feed_bags_max'),
    freightMin: document.getElementById('a_feed_freight_min'),
    freightMax: document.getElementById('a_feed_freight_max'),
    profitMin: document.getElementById('a_feed_profit_min')
  };
  var resetBtn = document.getElementById('feed_analytics_reset');

  function money(v){ return Number(v || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function intVal(v){ return Number(v || 0).toLocaleString(undefined, {maximumFractionDigits:0}); }
  function val(el){ return el ? String(el.value || '').trim().toLowerCase() : ''; }
  function num(el){ if(!el) return null; var t = String(el.value || '').trim(); if(t === '') return null; var n = Number(t); return Number.isFinite(n) ? n : null; }
  function inRange(v, min, max){ if(min !== null && v < min) return false; if(max !== null && v > max) return false; return true; }
  function escHtml(s){ return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;'); }

  function makeBars(id, items, max, fmt){
    var box = document.getElementById(id);
    if(!box) return;
    if(items.length === 0){ box.innerHTML = '<div class=\"bar-val\">No data</div>'; return; }
    box.innerHTML = items.map(function(it){
      var label = escHtml(it.label);
      var p = max > 0 ? Math.max(Math.min((it.value / max) * 100, 100), 0) : 0;
      return '<div class=\"bar-row\">'
        + '<div class=\"bar-label\" title=\"' + label + '\">' + label + '</div>'
        + '<div class=\"bar-track\"><div class=\"bar-fill\" style=\"width:' + p.toFixed(2) + '%\"></div></div>'
        + '<div class=\"bar-val\">' + fmt(it.value) + '</div>'
        + '</div>';
    }).join('');
  }

  function topMap(list, keyName, valueName, take){
    var map = {};
    list.forEach(function(r){
      var key = String(r[keyName] || '').trim() || 'Unknown';
      if(!map[key]) map[key] = 0;
      map[key] += valueName === 'count' ? 1 : Number(r[valueName] || 0);
    });
    return Object.keys(map).map(function(k){ return {label:k, value:map[k]}; })
      .sort(function(a,b){ return b.value - a.value; }).slice(0, take);
  }

  function readFilters(){
    return {
      text: val(f.text),
      party: val(f.party),
      location: val(f.location),
      user: val(f.user),
      status: val(f.status),
      section: val(f.section),
      bagsMin: num(f.bagsMin),
      bagsMax: num(f.bagsMax),
      freightMin: num(f.freightMin),
      freightMax: num(f.freightMax),
      profitMin: num(f.profitMin)
    };
  }

  function applyAnalytics(){
    var x = readFilters();
    var shown = [];
    records.forEach(function(r){
      var ok = true;
      if(x.text && (r.srL + ' ' + r.biltyL + ' ' + r.vehicleL).indexOf(x.text) === -1) ok = false;
      if(ok && x.party && r.partyL.indexOf(x.party) === -1) ok = false;
      if(ok && x.location && r.locationL.indexOf(x.location) === -1) ok = false;
      if(ok && x.user && r.addedByL.indexOf(x.user) === -1) ok = false;
      if(ok && x.section && r.sectionL.indexOf(x.section) === -1) ok = false;
      if(ok && (x.status === 'confirmed' || x.status === 'paid') && r.remaining > 0.0001) ok = false;
      if(ok && x.status === 'pending' && r.remaining <= 0.0001) ok = false;
      if(ok && !inRange(r.bags, x.bagsMin, x.bagsMax)) ok = false;
      if(ok && !inRange(r.totalCost, x.freightMin, x.freightMax)) ok = false;
      if(ok && x.profitMin !== null && r.profit < x.profitMin) ok = false;
      r.el.style.display = ok ? '' : 'none';
      if(ok) shown.push(r);
    });

    var totals = shown.reduce(function(a, r){
      a.count += 1; a.bags += r.bags; a.freight += r.freight; a.commission += r.commission; a.total += r.totalCost; a.tender += r.tender;
      a.remaining += r.remaining; a.paid += r.paid; a.profit += r.profit; return a;
    }, {count:0,bags:0,freight:0,commission:0,total:0,tender:0,remaining:0,paid:0,profit:0});
    var cards = [
      ['Bilties', intVal(totals.count)],
      ['Total Bags', intVal(totals.bags)],
      ['Freight', money(totals.freight)],
      ['Commission', money(totals.commission)],
      ['Total Cost', money(totals.total)],
      ['Paid', money(totals.paid)],
      ['Remaining', money(totals.remaining)],
      ['Collection %', totals.total > 0 ? ((totals.paid / totals.total) * 100).toFixed(2) + '%' : '0.00%']
    ];
    if(isSuperAdmin){ cards.push(['Tender', money(totals.tender)]); cards.push(['Profit', money(totals.profit)]); }
    statsBox.innerHTML = cards.map(function(c){ return '<div class=\"a-stat\"><div class=\"k\">' + escHtml(c[0]) + '</div><div class=\"v\">' + escHtml(c[1]) + '</div></div>'; }).join('');

    var topVehicles = topMap(shown, 'vehicle', 'count', 6);
    var topLocations = topMap(shown, 'location', 'totalCost', 6);
    var byDate = {};
    shown.forEach(function(r){ var k = r.date || 'Unknown'; if(!byDate[k]) byDate[k] = 0; byDate[k] += r.totalCost; });
    var trend = Object.keys(byDate).sort().slice(-8).map(function(k){ return {label:k, value:byDate[k]}; });
    makeBars('feed_chart_vehicles', topVehicles, topVehicles.length ? topVehicles[0].value : 0, intVal);
    makeBars('feed_chart_locations', topLocations, topLocations.length ? topLocations[0].value : 0, money);
    makeBars('feed_chart_trend', trend, trend.length ? Math.max.apply(null, trend.map(function(r){ return r.value; })) : 0, money);

    var paidPct = totals.total > 0 ? (totals.paid / totals.total) * 100 : 0;
    var remPct = Math.max(100 - paidPct, 0);
    var paidBar = document.getElementById('feed_split_paid');
    var remBar = document.getElementById('feed_split_rem');
    if(paidBar) paidBar.style.width = paidPct.toFixed(2) + '%';
    if(remBar) remBar.style.width = remPct.toFixed(2) + '%';
    var paidLabel = document.getElementById('feed_split_paid_label');
    var remLabel = document.getElementById('feed_split_rem_label');
    if(paidLabel) paidLabel.textContent = 'Paid: ' + money(totals.paid);
    if(remLabel) remLabel.textContent = 'Remaining: ' + money(totals.remaining);
  }

  function togglePanel(){
    wrap.classList.toggle('open');
    if(wrap.classList.contains('open')) applyAnalytics();
  }

  if(toggleA) toggleA.addEventListener('click', togglePanel);
  if(toggleB) toggleB.addEventListener('click', togglePanel);
  if(resetBtn) resetBtn.addEventListener('click', function(){
    Object.keys(f).forEach(function(k){
      if(!f[k]) return;
      if(f[k].tagName === 'SELECT') f[k].selectedIndex = 0;
      else f[k].value = '';
    });
    applyAnalytics();
  });

  Object.keys(f).forEach(function(k){
    if(!f[k]) return;
    f[k].addEventListener('input', applyAnalytics);
    f[k].addEventListener('change', applyAnalytics);
  });
})();
</script>
</body>
</html>

