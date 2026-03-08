<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/change_requests.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
$canDirectModify = auth_can_direct_modify('haleeb');
$isSuperAdmin = auth_is_super_admin();
$canFeed = auth_has_module_access('feed');
$canManageUsers = auth_can_manage_users();
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

function normalize_delivery_status_token_local($raw){
    $status = strtolower(trim((string)$raw));
    $status = str_replace(['-', ' '], '_', $status);
    if($status === 'received') return 'received';
    return 'not_received';
}

if(isset($_GET['set_delivery_status'])){
    $statusBiltyId = isset($_GET['set_delivery_status']) ? (int)$_GET['set_delivery_status'] : 0;
    $nextDeliveryStatus = normalize_delivery_status_token_local(isset($_GET['status']) ? (string)$_GET['status'] : '');
    if($statusBiltyId <= 0){
        header("location:haleeb.php?status_change=error");
        exit();
    }

    $statusStmt = $conn->prepare("SELECT id, date, vehicle, vehicle_type, driver_phone_no, delivery_status, delivery_note, token_no, party, location, stops, freight, commission, tender FROM haleeb_bilty WHERE id=? LIMIT 1");
    $statusStmt->bind_param("i", $statusBiltyId);
    $statusStmt->execute();
    $statusRow = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();
    if(!$statusRow){
        header("location:haleeb.php?status_change=error");
        exit();
    }

    $currentDeliveryStatus = normalize_delivery_status_token_local(isset($statusRow['delivery_status']) ? $statusRow['delivery_status'] : '');
    if($currentDeliveryStatus === $nextDeliveryStatus){
        header("location:haleeb.php?status_change=nochange");
        exit();
    }

    if(!$canDirectModify){
        $payload = [
            'date' => (string)($statusRow['date'] ?? date('Y-m-d')),
            'vehicle' => (string)($statusRow['vehicle'] ?? ''),
            'vehicle_type' => (string)($statusRow['vehicle_type'] ?? ''),
            'driver_phone_no' => (string)($statusRow['driver_phone_no'] ?? ''),
            'delivery_status' => $nextDeliveryStatus,
            'delivery_note' => (string)($statusRow['delivery_note'] ?? ''),
            'token_no' => (string)($statusRow['token_no'] ?? ''),
            'party' => (string)($statusRow['party'] ?? ''),
            'location' => (string)($statusRow['location'] ?? ''),
            'stops' => (string)($statusRow['stops'] ?? ''),
            'freight' => isset($statusRow['freight']) ? (float)$statusRow['freight'] : 0.0,
            'commission' => 0,
            'tender' => isset($statusRow['tender']) ? (float)$statusRow['tender'] : 0.0
        ];
        $requestId = create_change_request_local($conn, 'haleeb', 'haleeb_bilty', $statusBiltyId, 'haleeb_update', $payload, $currentUserId);
        if($requestId > 0){
            header("location:haleeb.php?status_change=requested");
            exit();
        }
        header("location:haleeb.php?status_change=error");
        exit();
    }

    $updateStatusStmt = $conn->prepare("UPDATE haleeb_bilty SET delivery_status=? WHERE id=? LIMIT 1");
    $updateStatusStmt->bind_param("si", $nextDeliveryStatus, $statusBiltyId);
    $okStatus = $updateStatusStmt->execute();
    $affectedStatus = $updateStatusStmt->affected_rows;
    $updateStatusStmt->close();
    if($okStatus && $affectedStatus > 0){
        header("location:haleeb.php?status_change=success");
        exit();
    }

    header("location:haleeb.php?status_change=error");
    exit();
}

if(isset($_GET['delete_all']) && $_GET['delete_all'] === '1'){
    if(!auth_can_direct_modify('haleeb')){
        header("location:haleeb.php?clear=denied");
        exit();
    }
    $conn->begin_transaction();
    try{
        $countRow = $conn->query("SELECT COUNT(*) AS c FROM haleeb_bilty")->fetch_assoc();
        $deletedCount = $countRow ? (int)$countRow['c'] : 0;

        if($deletedCount > 0){
            $conn->query("UPDATE account_entries e
                          JOIN haleeb_bilty h ON e.haleeb_bilty_id = h.id
                          SET e.amount = 0,
                              e.note = CONCAT(
                                  CASE
                                      WHEN COALESCE(NULLIF(e.note,''), '') = '' THEN CONCAT('Auto Driver Payment Request - Haleeb Token ', COALESCE(NULLIF(h.token_no,''), CONCAT('#', h.id)))
                                      ELSE e.note
                                  END,
                                  ' | Reversed on Bulk Delete - Haleeb Token ',
                                  COALESCE(NULLIF(h.token_no,''), CONCAT('#', h.id))
                              )
                          WHERE e.entry_type='debit' AND e.amount > 0");
            $conn->query("DELETE FROM haleeb_bilty");
        }

        $conn->commit();
        header("location:haleeb.php?clear=success&deleted=" . $deletedCount);
        exit();
    } catch (Throwable $e){
        $conn->rollback();
        header("location:haleeb.php?clear=error");
        exit();
    }
}

$total_profit = 0;
if($isSuperAdmin){
    $total = $conn->query("SELECT SUM(COALESCE(tender,0) - GREATEST((COALESCE(freight,0) - COALESCE(commission,0)),0)) AS t FROM haleeb_bilty")->fetch_assoc();
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
        $reason = isset($_GET['reason']) ? trim((string)$_GET['reason']) : '';
        if($reason === 'zip_missing'){
            $import_message = "XLSX import needs PHP zip extension (ZipArchive). Enable php_zip in XAMPP or upload CSV.";
        } else {
            $import_message = "Import failed. Please upload a valid CSV/XLSX file.";
        }
    }
}

$pay_message = "";
if (isset($_GET['pay'])) {
    if ($_GET['pay'] === 'success') $pay_message = "Payment posted successfully.";
    elseif ($_GET['pay'] === 'error') $pay_message = "Payment failed. Please try again.";
    elseif ($_GET['pay'] === 'requested') $pay_message = "Payment request sent to account ledger admin for approval.";
}
$status_change_message = "";
$status_change_error = false;
if(isset($_GET['status_change'])){
    if($_GET['status_change'] === 'success') $status_change_message = "Delivery status updated successfully.";
    elseif($_GET['status_change'] === 'requested') $status_change_message = "Delivery status change request sent for approval.";
    elseif($_GET['status_change'] === 'nochange') $status_change_message = "Delivery status is already set.";
    elseif($_GET['status_change'] === 'error'){ $status_change_message = "Delivery status update failed. Please try again."; $status_change_error = true; }
}
$clear_message = "";
if(isset($_GET['clear'])){
    if($_GET['clear'] === 'success'){
        $deletedCount = isset($_GET['deleted']) ? (int)$_GET['deleted'] : 0;
        $clear_message = "All Haleeb bilties deleted. Removed: " . $deletedCount;
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
$vehicleRes = $conn->query("SELECT DISTINCT vehicle FROM haleeb_bilty WHERE vehicle IS NOT NULL AND vehicle <> '' ORDER BY vehicle ASC");
while($vehicleRes && $vrow = $vehicleRes->fetch_assoc()){
    $vehicleOptions[] = (string)$vrow['vehicle'];
}

$pendingOwnHaleebChanges = [];
if(!$canDirectModify && $currentUserId > 0){
    $pendingEditDeleteStmt = $conn->prepare("SELECT id, entity_id, action_type, payload FROM change_requests WHERE status='pending' AND requested_by=? AND module_key='haleeb' AND entity_table='haleeb_bilty' AND action_type IN ('haleeb_update', 'haleeb_delete') ORDER BY id DESC");
    $pendingEditDeleteStmt->bind_param("i", $currentUserId);
    $pendingEditDeleteStmt->execute();
    $pendingEditDeleteRes = $pendingEditDeleteStmt->get_result();
    while($pendingEditDeleteRes && $req = $pendingEditDeleteRes->fetch_assoc()){
        $entityId = isset($req['entity_id']) ? (int)$req['entity_id'] : 0;
        if($entityId <= 0 || isset($pendingOwnHaleebChanges[$entityId])) continue;
        $pendingOwnHaleebChanges[$entityId] = [
            'request_id' => isset($req['id']) ? (int)$req['id'] : 0,
            'action_type' => isset($req['action_type']) ? (string)$req['action_type'] : '',
            'payload' => request_payload_decode_local(isset($req['payload']) ? (string)$req['payload'] : '')
        ];
    }
    $pendingEditDeleteStmt->close();
}
$pendingOwnHaleebCount = count($pendingOwnHaleebChanges);

$where = []; $bindValues = []; $bindTypes = "";
if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){ $where[] = "date >= ?"; $bindTypes .= "s"; $bindValues[] = $dateFrom; }
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){ $where[] = "date <= ?"; $bindTypes .= "s"; $bindValues[] = $dateTo; }
if($vehicleSearch !== '' && $canDirectModify){ $where[] = "vehicle LIKE ?"; $bindTypes .= "s"; $bindValues[] = "%" . $vehicleSearch . "%"; }

$sql = "SELECT h.*,
        COALESCE(NULLIF(u.username, ''), CASE WHEN h.added_by_user_id IS NULL THEN '-' ELSE CONCAT('User#', h.added_by_user_id) END) AS added_by_name,
        GREATEST((COALESCE(h.freight,0) - COALESCE(h.commission,0)), 0) AS total_cost,
        (COALESCE(h.tender,0) - GREATEST((COALESCE(h.freight,0) - COALESCE(h.commission,0)), 0)) AS calc_profit,
        GREATEST(GREATEST((COALESCE(h.freight,0) - COALESCE(h.commission,0)), 0) - COALESCE(p.paid_total, 0), 0) AS remaining_balance,
        COALESCE(p.paid_total, 0) AS paid_total
        FROM haleeb_bilty h
        LEFT JOIN users u ON u.id = h.added_by_user_id
        LEFT JOIN (
          SELECT haleeb_bilty_id, SUM(amount) AS paid_total
          FROM account_entries
          WHERE haleeb_bilty_id IS NOT NULL AND entry_type='debit'
          GROUP BY haleeb_bilty_id
        ) p ON p.haleeb_bilty_id = h.id";
if(count($where) > 0){
    $whereSql = [];
    foreach($where as $w){
        $whereSql[] = str_replace(["date", "vehicle"], ["h.date", "h.vehicle"], $w);
    }
    $sql .= " WHERE " . implode(" AND ", $whereSql);
}
$sql .= " ORDER BY id DESC";

if(count($bindValues) > 0){
    $stmt = $conn->prepare($sql);
    if(count($bindValues) === 1) $stmt->bind_param($bindTypes, $bindValues[0]);
    elseif(count($bindValues) === 2) $stmt->bind_param($bindTypes, $bindValues[0], $bindValues[1]);
    else $stmt->bind_param($bindTypes, $bindValues[0], $bindValues[1], $bindValues[2]);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$haleebRows = [];
while($result && $row = $result->fetch_assoc()){
    $rowId = isset($row['id']) ? (int)$row['id'] : 0;

    if(!$canDirectModify && $rowId > 0 && isset($pendingOwnHaleebChanges[$rowId])){
        $pendingChange = $pendingOwnHaleebChanges[$rowId];
        $pendingAction = isset($pendingChange['action_type']) ? (string)$pendingChange['action_type'] : '';
        $pendingPayload = isset($pendingChange['payload']) && is_array($pendingChange['payload']) ? $pendingChange['payload'] : [];

        if($pendingAction === 'haleeb_delete'){
            continue;
        }

        if($pendingAction === 'haleeb_update'){
            $overlayFields = ['date', 'vehicle', 'vehicle_type', 'driver_phone_no', 'delivery_status', 'delivery_note', 'token_no', 'party', 'location', 'stops', 'freight', 'commission', 'tender'];
            foreach($overlayFields as $field){
                if(array_key_exists($field, $pendingPayload)){
                    $row[$field] = $pendingPayload[$field];
                }
            }

            $freight = isset($row['freight']) ? (float)$row['freight'] : 0.0;
            $commission = isset($row['commission']) ? (float)$row['commission'] : 0.0;
            $tender = isset($row['tender']) ? (float)$row['tender'] : 0.0;
            $totalCost = max(0, $freight - $commission);
            $paidTotal = isset($row['paid_total']) ? (float)$row['paid_total'] : 0.0;
            $remaining = max(0, $totalCost - $paidTotal);

            $row['total_cost'] = $totalCost;
            $row['calc_profit'] = $tender - $totalCost;
            $row['remaining_balance'] = $remaining;
        }
    }

    if($vehicleSearch !== '' && stripos((string)($row['vehicle'] ?? ''), $vehicleSearch) === false) continue;

    $haleebRows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Haleeb</title>
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
    --accent: #60a5fa;
    --accent-dark: #1d4ed8;
    --green: #22c55e;
    --red: #ef4444;
    --text: #e8eaf0;
    --muted: #7c8091;
    --font: 'Syne', sans-serif;
    --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  .topbar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 28px; border-bottom: 1px solid var(--border);
    background: var(--surface); position: sticky; top: 0; z-index: 100;
  }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge {
    background: var(--accent); color: #0e0f11; font-size: 10px;
    font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase;
  }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 6px; }
  .nav-btn {
    padding: 8px 16px; background: transparent; color: var(--muted);
    border: 1px solid var(--border); cursor: pointer; text-decoration: none;
    font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s;
  }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .nav-btn.primary { background: var(--accent); color: #0e0f11; border-color: var(--accent); }
  .nav-btn.primary:hover { background: #3b82f6; }
  .nav-btn.danger { background: rgba(239,68,68,0.12); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .nav-btn.danger:hover { background: rgba(239,68,68,0.22); color: var(--red); border-color: rgba(239,68,68,0.35); }

  .menu-wrap { position: relative; }
  .menu-trigger {
    width: 36px; height: 36px; background: var(--surface2); border: 1px solid var(--border);
    color: var(--text); cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center;
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

  .main { padding: 24px 28px; max-width: 1400px; margin: 0 auto; }

  .alert {
    padding: 12px 16px; margin-bottom: 16px; font-size: 13px;
    border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green);
  }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

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

  .search-panel { background: var(--surface); border: 1px solid var(--border); padding: 16px 20px; margin-bottom: 20px; }
  .search-form { display: grid; grid-template-columns: 1fr 1fr 1.5fr auto; gap: 12px; align-items: end; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .field input {
    width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 9px 12px; font-family: var(--font); font-size: 13px; transition: border-color 0.15s;
  }
  .field input:focus { outline: none; border-color: var(--accent); }
  .field input::-webkit-calendar-picker-indicator { filter: invert(0.5); cursor: pointer; }
  .search-actions { display: flex; gap: 8px; }
  .btn-ghost {
    padding: 9px 16px; background: transparent; color: var(--muted); border: 1px solid var(--border);
    cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s;
  }
  .btn-ghost:hover { color: var(--text); border-color: var(--muted); }

  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .tbl-header-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: var(--surface2); }
  th { padding: 11px 14px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  td { padding: 11px 14px; font-size: 13px; border-bottom: 1px solid rgba(42,45,53,0.7); font-family: var(--mono); color: var(--text); }
  tbody tr { transition: background 0.1s; }
  tbody tr:hover { background: var(--surface2); }
  .td-profit { color: var(--green); font-weight: 500; }
  .td-profit.neg { color: var(--red); }

  .action-cell { display: flex; gap: 4px; justify-content: center; }
  .act-btn { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; border: 1px solid transparent; transition: all 0.15s; cursor: pointer; }
  .act-view { background: rgba(59,130,246,0.15); color: #60a5fa; border-color: rgba(59,130,246,0.25); }
  .act-view:hover { background: rgba(59,130,246,0.25); }
  .act-pay { background: rgba(34,197,94,0.15); color: var(--green); border-color: rgba(34,197,94,0.25); }
  .act-pay:hover { background: rgba(34,197,94,0.25); }
  .act-confirm { background: rgba(16,185,129,0.15); color: #10b981; border-color: rgba(16,185,129,0.25); }
  .act-confirm:hover { background: rgba(16,185,129,0.25); }
  .act-edit { background: rgba(96,165,250,0.15); color: var(--accent); border-color: rgba(96,165,250,0.25); }
  .act-edit:hover { background: rgba(96,165,250,0.25); }
  .act-pdf { background: rgba(168,85,247,0.15); color: #c084fc; border-color: rgba(168,85,247,0.25); }
  .act-pdf:hover { background: rgba(168,85,247,0.25); }
  .th-action { text-align: center; width: 170px; }
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
  .status-toggle {
    font-family: var(--font); line-height: 1.2; background: transparent;
    cursor: pointer; transition: transform 0.12s ease, border-color 0.15s ease;
  }
  .status-toggle:hover { transform: translateY(-1px); }
  .status-toggle:focus { outline: none; border-color: var(--accent); }
  .rem-zero { background: rgba(34,197,94,0.15); color: var(--green); border-color: rgba(34,197,94,0.25); }
  .rem-pending { background: rgba(239,68,68,0.15); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .status-modal-mask {
    position: fixed; inset: 0; background: rgba(0,0,0,0.6);
    display: none; align-items: center; justify-content: center; z-index: 2600; padding: 14px;
  }
  .status-modal-mask.open { display: flex; }
  .status-modal-card {
    width: min(420px, 100%); background: var(--surface); border: 1px solid var(--border);
    padding: 18px 16px 14px; box-shadow: 0 24px 50px rgba(0,0,0,0.55);
  }
  .status-modal-title { font-size: 16px; font-weight: 800; margin-bottom: 8px; }
  .status-modal-text { font-size: 13px; color: var(--muted); line-height: 1.45; margin-bottom: 14px; }
  .status-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
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
  .bar-fill { background: linear-gradient(90deg, var(--accent), #1d4ed8); height: 100%; width: 0; }
  .bar-val { font-size: 11px; color: var(--muted); font-family: var(--mono); }
  .split-wrap { display: grid; gap: 8px; }
  .split-track { background: #121317; border: 1px solid var(--border); height: 14px; display: flex; overflow: hidden; }
  .split-paid { background: rgba(34,197,94,0.85); height: 100%; }
  .split-rem { background: rgba(239,68,68,0.85); height: 100%; }
  .split-meta { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); font-family: var(--mono); }

  .vtype-badge {
    display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase; background: rgba(96,165,250,0.12);
    color: var(--accent); border: 1px solid rgba(96,165,250,0.2);
  }

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
    .search-form { grid-template-columns: 1fr; }
    .analytics-grid { grid-template-columns: 1fr; }
    .analytics-stats { grid-template-columns: 1fr; }
    .bar-row { grid-template-columns: 90px 1fr auto; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Haleeb</span>
    <h1>Haleeb</h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn primary" href="add_haleeb_bilty.php">Add Bilty</a>
    <?php if($canDirectModify): ?>
      <button class="nav-btn" type="button" id="haleeb_analytics_toggle">Analytics</button>
    <?php endif; ?>
    <?php if($canFeed): ?>
      <a class="nav-btn" href="feed.php">Feed</a>
    <?php endif; ?>
    <?php if($canManageUsers): ?>
      <a class="nav-btn" href="super_admin.php">Super Admin</a>
    <?php endif; ?>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <div class="menu-wrap">
      <button class="menu-trigger" id="haleeb_menu_btn" type="button" aria-label="Menu">&#9776;</button>
      <div class="menu-pop" id="haleeb_menu_pop">
        <a class="nav-btn" href="dashboard.php">Dashboard</a>
        <a class="nav-btn" href="request_status.php">View Request Status</a>
        <?php if($canDirectModify): ?>
          <button class="nav-btn" type="button" id="haleeb_analytics_toggle_menu">Analytics</button>
        <?php endif; ?>
        <?php if($canDirectModify): ?>
          <div class="menu-sep"></div>
          <a class="nav-btn" href="haleeb_ratelist.php">Rate List</a>
          <a class="nav-btn" href="export_haleeb.php">Export CSV</a>
          <?php if($canDirectModify): ?>
            <a class="nav-btn danger" href="haleeb.php?delete_all=1" onclick="return confirm('Delete all Haleeb bilties?')">Delete All Bilties</a>
          <?php endif; ?>
          <div class="menu-sep"></div>
          <div class="import-row">
            <form action="import_haleeb.php" method="post" enctype="multipart/form-data">
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
  <?php if($status_change_message !== ""): ?>
    <div class="alert <?php echo $status_change_error ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($status_change_message); ?>
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
  <?php if(!$canDirectModify && $pendingOwnHaleebCount > 0): ?>
    <div class="alert">Pending requests view enabled: your submitted edit/delete requests are shown temporarily until reviewed.</div>
  <?php endif; ?>

  <?php if($isSuperAdmin): ?>
    <div class="profit-banner">
      <div>
        <div class="profit-label">Total Profit</div>
        <div class="profit-value">Rs <?php echo number_format((float)$total_profit, 2); ?></div>
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
        <a class="btn-ghost" href="haleeb.php">Reset</a>
      </div>
    </form>
  </div>

  <?php if($isSuperAdmin): ?>
  <div class="analytics-wrap" id="haleeb_analytics_wrap">
    <div class="analytics-head">
      <span class="tbl-header-title">Analytics</span>
      <button class="nav-btn" type="button" id="haleeb_analytics_reset">Reset Analytics</button>
    </div>
    <div class="analytics-grid">
      <div class="field">
        <label for="a_h_text">Token / Vehicle / Note</label>
        <input id="a_h_text" list="a_h_text_list" placeholder="Search">
      </div>
      <div class="field">
        <label for="a_h_type">Vehicle Type</label>
        <input id="a_h_type" list="a_h_type_list" placeholder="Type">
      </div>
      <div class="field">
        <label for="a_h_party">Party</label>
        <input id="a_h_party" list="a_h_party_list" placeholder="Party">
      </div>
      <div class="field">
        <label for="a_h_location">Location</label>
        <input id="a_h_location" list="a_h_location_list" placeholder="Location">
      </div>
      <div class="field">
        <label for="a_h_status">Payment Status</label>
        <select id="a_h_status">
          <option value="">All</option>
          <option value="confirmed">Confirmed</option>
          <option value="pending">Pending</option>
        </select>
      </div>
      <div class="field">
        <label for="a_h_user">Added By</label>
        <input id="a_h_user" list="a_h_user_list" placeholder="User">
      </div>
      <div class="field">
        <label for="a_h_same_min">Min Same City Stops</label>
        <input id="a_h_same_min" type="number" min="0" placeholder="0">
      </div>
      <div class="field">
        <label for="a_h_out_min">Min Out City Stops</label>
        <input id="a_h_out_min" type="number" min="0" placeholder="0">
      </div>
      <div class="field">
        <label for="a_h_freight_min">Min Total Cost</label>
        <input id="a_h_freight_min" type="number" min="0" placeholder="0">
      </div>
      <div class="field">
        <label for="a_h_freight_max">Max Total Cost</label>
        <input id="a_h_freight_max" type="number" min="0" placeholder="Any">
      </div>
      <?php if($isSuperAdmin): ?>
      <div class="field">
        <label for="a_h_profit_min">Min Profit</label>
        <input id="a_h_profit_min" type="number" placeholder="Any">
      </div>
      <?php endif; ?>

      <datalist id="a_h_text_list"></datalist>
      <datalist id="a_h_type_list"></datalist>
      <datalist id="a_h_party_list"></datalist>
      <datalist id="a_h_location_list"></datalist>
      <datalist id="a_h_user_list"></datalist>
    </div>
    <div class="analytics-stats" id="haleeb_analytics_stats"></div>
    <div class="analytics-charts">
      <div class="chart-box">
        <div class="chart-title">Top Vehicles (Count)</div>
        <div class="bar-list" id="haleeb_chart_vehicles"></div>
      </div>
      <div class="chart-box">
        <div class="chart-title">Top Locations (Freight)</div>
        <div class="bar-list" id="haleeb_chart_locations"></div>
      </div>
      <div class="chart-box">
        <div class="chart-title">Top Vehicle Types</div>
        <div class="bar-list" id="haleeb_chart_types"></div>
      </div>
      <div class="chart-box">
        <div class="chart-title">Daily Freight Trend</div>
        <div class="bar-list" id="haleeb_chart_trend"></div>
      </div>
      <div class="chart-box" style="grid-column: 1 / -1;">
        <div class="chart-title">Collection Split</div>
        <div class="split-wrap">
          <div class="split-track">
            <div class="split-paid" id="haleeb_split_paid"></div>
            <div class="split-rem" id="haleeb_split_rem"></div>
          </div>
          <div class="split-meta">
            <span id="haleeb_split_paid_label">Paid: 0</span>
            <span id="haleeb_split_rem_label">Remaining: 0</span>
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
    <table id="haleeb_records_table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Vehicle</th>
          <th>Type</th>
          <th>Driver No</th>
          <th>Party</th>
          <th>Location</th>
          <th>Delivery Status</th>
          <th>Token No</th>
          <th>Delivery Note</th>
          <th>Remaining</th>
          <th class="th-action">Actions</th>
          <?php if($isSuperAdmin): ?><th>Tender</th><th>Profit</th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="haleeb_records_tbody">
        <?php foreach($haleebRows as $row):
          $profit = (float)$row['calc_profit'];
          $remaining = (float)($row['remaining_balance'] ?? 0);
          $commission = (float)($row['commission'] ?? 0);
          $totalCost = (float)($row['total_cost'] ?? max(((float)($row['freight'] ?? 0)) - $commission, 0));
          $addedByName = isset($row['added_by_name']) && trim((string)$row['added_by_name']) !== '' ? (string)$row['added_by_name'] : '-';
          $driverPhoneNo = trim((string)($row['driver_phone_no'] ?? ''));
          $deliveryStatusRaw = strtolower(trim((string)($row['delivery_status'] ?? 'not_received')));
          if($deliveryStatusRaw !== 'received') $deliveryStatusRaw = 'not_received';
          $deliveryStatusLabel = $deliveryStatusRaw === 'received' ? 'Received' : 'Not Received';
          $deliveryStatusClass = $deliveryStatusRaw === 'received' ? 'rem-zero' : 'rem-pending';
          $deliveryStatusNext = $deliveryStatusRaw === 'received' ? 'not_received' : 'received';
          $deliveryStatusNextLabel = $deliveryStatusNext === 'received' ? 'Received' : 'Not Received';
          $detailHref = 'bilty_detail.php?type=haleeb&id=' . (int)$row['id'] . '&src=haleeb';
          $stopsRaw = isset($row['stops']) ? (string)$row['stops'] : '';
          $sameStops = 0;
          $outStops = 0;
          if(preg_match('/SC:\s*(\d+)/i', $stopsRaw, $m)){ $sameStops = (int)$m[1]; }
          if(preg_match('/OC:\s*(\d+)/i', $stopsRaw, $m)){ $outStops = (int)$m[1]; }
        ?>
        <tr data-analytics-row="1"
            data-date="<?php echo htmlspecialchars((string)($row['date'] ?? '')); ?>"
            data-vehicle="<?php echo htmlspecialchars((string)($row['vehicle'] ?? '')); ?>"
            data-type="<?php echo htmlspecialchars((string)($row['vehicle_type'] ?? '')); ?>"
            data-note="<?php echo htmlspecialchars((string)($row['delivery_note'] ?? '')); ?>"
            data-token="<?php echo htmlspecialchars((string)($row['token_no'] ?? '')); ?>"
            data-party="<?php echo htmlspecialchars((string)($row['party'] ?? '')); ?>"
            data-location="<?php echo htmlspecialchars((string)($row['location'] ?? '')); ?>"
            data-delivery-status="<?php echo htmlspecialchars((string)$deliveryStatusRaw); ?>"
            data-user="<?php echo htmlspecialchars((string)$addedByName); ?>"
            data-stops="<?php echo htmlspecialchars($stopsRaw); ?>"
            data-same-stops="<?php echo $sameStops; ?>"
            data-out-stops="<?php echo $outStops; ?>"
            data-freight="<?php echo (float)($row['freight'] ?? 0); ?>"
            data-total="<?php echo $totalCost; ?>"
            data-commission="<?php echo $commission; ?>"
            data-tender="<?php echo (float)($row['tender'] ?? 0); ?>"
            data-remaining="<?php echo $remaining; ?>"
            data-profit="<?php echo $profit; ?>">
          <td><?php echo htmlspecialchars($row['date']); ?></td>
          <td><?php echo htmlspecialchars($row['vehicle']); ?></td>
          <td><span class="vtype-badge"><?php echo htmlspecialchars($row['vehicle_type']); ?></span></td>
          <td><?php echo htmlspecialchars($driverPhoneNo !== '' ? $driverPhoneNo : '-'); ?></td>
          <td><?php echo htmlspecialchars($row['party']); ?></td>
          <td><?php echo htmlspecialchars($row['location']); ?></td>
          <td>
            <button type="button"
              class="rem-badge status-toggle <?php echo $deliveryStatusClass; ?>"
              data-status-id="<?php echo (int)$row['id']; ?>"
              data-current-status="<?php echo htmlspecialchars($deliveryStatusRaw); ?>"
              data-next-status="<?php echo htmlspecialchars($deliveryStatusNext); ?>"
              data-next-label="<?php echo htmlspecialchars($deliveryStatusNextLabel); ?>"
              title="Click to change status">
              <?php echo htmlspecialchars($deliveryStatusLabel); ?>
            </button>
          </td>
          <td><?php echo htmlspecialchars($row['token_no']); ?></td>
          <td><?php echo htmlspecialchars($row['delivery_note']); ?></td>
          <td>
            <span class="rem-badge <?php echo $remaining <= 0 ? 'rem-zero' : 'rem-pending'; ?>">
              Rs <?php echo number_format($remaining, 2); ?>
            </span>
          </td>
          <td>
            <div class="action-cell">
              <a class="act-btn act-view" href="<?php echo htmlspecialchars($detailHref); ?>" title="View Details">&#128065;</a>
              <a class="act-btn act-pay" href="pay_now_haleeb.php?id=<?php echo $row['id']; ?>" title="Pay">&#8377;</a>
              <a class="act-btn act-edit" href="edit_haleeb_bilty.php?id=<?php echo $row['id']; ?>" title="Edit">&#9998;</a>
              <a class="act-btn act-pdf" href="haleeb_pdf.php?id=<?php echo $row['id']; ?>" target="_blank" title="PDF">&#128196;</a>
            </div>
          </td>
          <?php if($isSuperAdmin): ?>
            <td>Rs <?php echo number_format((float)$row['tender'], 2); ?></td>
            <td class="td-profit <?php echo $profit < 0 ? 'neg' : ''; ?>">
              Rs <?php echo number_format($profit, 2); ?>
            </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="status-modal-mask" id="status_change_modal" aria-hidden="true">
  <div class="status-modal-card" role="dialog" aria-modal="true" aria-labelledby="status_change_title">
    <div class="status-modal-title" id="status_change_title">Change Delivery Status</div>
    <div class="status-modal-text" id="status_change_text">Do you want to change delivery status?</div>
    <div class="status-modal-actions">
      <button class="btn-ghost" type="button" id="status_change_cancel">Cancel</button>
      <button class="nav-btn primary" type="button" id="status_change_confirm">Yes, Change</button>
    </div>
  </div>
</div>

<script>
(function(){
  var btn = document.getElementById('haleeb_menu_btn');
  var pop = document.getElementById('haleeb_menu_pop');
  if(btn && pop){
    btn.addEventListener('click', function(e){ e.stopPropagation(); pop.classList.toggle('open'); });
    document.addEventListener('click', function(e){ if(!pop.contains(e.target) && e.target !== btn) pop.classList.remove('open'); });
  }
})();

(function(){
  var modal = document.getElementById('status_change_modal');
  var text = document.getElementById('status_change_text');
  var cancelBtn = document.getElementById('status_change_cancel');
  var confirmBtn = document.getElementById('status_change_confirm');
  var pending = null;
  if(!modal || !text || !cancelBtn || !confirmBtn) return;

  function closeModal(){
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    pending = null;
  }

  function openModal(payload){
    pending = payload;
    text.textContent = 'Do you want to change delivery status from ' + payload.currentLabel + ' to ' + payload.nextLabel + '?';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  }

  var toggles = Array.prototype.slice.call(document.querySelectorAll('.status-toggle[data-status-id]'));
  toggles.forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = Number(btn.getAttribute('data-status-id') || 0);
      var currentStatus = String(btn.getAttribute('data-current-status') || 'not_received');
      var nextStatus = String(btn.getAttribute('data-next-status') || 'received');
      var nextLabel = String(btn.getAttribute('data-next-label') || (nextStatus === 'received' ? 'Received' : 'Not Received'));
      if(!Number.isFinite(id) || id <= 0) return;
      var currentLabel = currentStatus === 'received' ? 'Received' : 'Not Received';
      openModal({ id: id, nextStatus: nextStatus, currentLabel: currentLabel, nextLabel: nextLabel });
    });
  });

  confirmBtn.addEventListener('click', function(){
    if(!pending || !pending.id) return;
    window.location.href = 'haleeb.php?set_delivery_status=' + encodeURIComponent(String(pending.id)) + '&status=' + encodeURIComponent(String(pending.nextStatus));
  });

  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){
    if(e.target === modal) closeModal();
  });
})();

(function(){
  var isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
  var wrap = document.getElementById('haleeb_analytics_wrap');
  var toggleA = document.getElementById('haleeb_analytics_toggle');
  var toggleB = document.getElementById('haleeb_analytics_toggle_menu');
  if(!wrap) return;

  var statsBox = document.getElementById('haleeb_analytics_stats');
  var rows = Array.prototype.slice.call(document.querySelectorAll('#haleeb_records_tbody tr[data-analytics-row=\"1\"]'));
  var records = rows.map(function(row){
    var d = row.dataset || {};
    var freight = Number(d.freight || 0);
    var commission = Number(d.commission || 0);
    var total = Number(d.total || Math.max(freight - commission, 0));
    var remaining = Number(d.remaining || 0);
    return {
      el: row,
      date: String(d.date || ''),
      vehicle: String(d.vehicle || ''),
      vehicleL: String(d.vehicle || '').toLowerCase(),
      type: String(d.type || ''),
      typeL: String(d.type || '').toLowerCase(),
      note: String(d.note || ''),
      noteL: String(d.note || '').toLowerCase(),
      token: String(d.token || ''),
      tokenL: String(d.token || '').toLowerCase(),
      party: String(d.party || ''),
      partyL: String(d.party || '').toLowerCase(),
      location: String(d.location || ''),
      locationL: String(d.location || '').toLowerCase(),
      addedBy: String(d.user || ''),
      addedByL: String(d.user || '').toLowerCase(),
      sameStops: Number(d.sameStops || 0),
      outStops: Number(d.outStops || 0),
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

  fillDatalist('a_h_text_list', records.reduce(function(arr, r){
    arr.push(r.token, r.vehicle, r.note);
    return arr;
  }, []), 300);
  fillDatalist('a_h_type_list', records.map(function(r){ return r.type; }), 100);
  fillDatalist('a_h_party_list', records.map(function(r){ return r.party; }), 300);
  fillDatalist('a_h_location_list', records.map(function(r){ return r.location; }), 300);
  fillDatalist('a_h_user_list', records.map(function(r){ return r.addedBy; }), 300);

  var f = {
    text: document.getElementById('a_h_text'),
    type: document.getElementById('a_h_type'),
    party: document.getElementById('a_h_party'),
    location: document.getElementById('a_h_location'),
    user: document.getElementById('a_h_user'),
    status: document.getElementById('a_h_status'),
    sameMin: document.getElementById('a_h_same_min'),
    outMin: document.getElementById('a_h_out_min'),
    freightMin: document.getElementById('a_h_freight_min'),
    freightMax: document.getElementById('a_h_freight_max'),
    profitMin: document.getElementById('a_h_profit_min')
  };
  var resetBtn = document.getElementById('haleeb_analytics_reset');

  function money(v){ return Number(v || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function intVal(v){ return Number(v || 0).toLocaleString(undefined, {maximumFractionDigits:0}); }
  function val(el){ return el ? String(el.value || '').trim().toLowerCase() : ''; }
  function num(el){ if(!el) return null; var t = String(el.value || '').trim(); if(t === '') return null; var n = Number(t); return Number.isFinite(n) ? n : null; }
  function escHtml(s){ return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;'); }
  function inRange(v, min, max){ if(min !== null && v < min) return false; if(max !== null && v > max) return false; return true; }

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
      type: val(f.type),
      party: val(f.party),
      location: val(f.location),
      user: val(f.user),
      status: val(f.status),
      sameMin: num(f.sameMin),
      outMin: num(f.outMin),
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
      if(x.text && (r.tokenL + ' ' + r.vehicleL + ' ' + r.noteL).indexOf(x.text) === -1) ok = false;
      if(ok && x.type && r.typeL.indexOf(x.type) === -1) ok = false;
      if(ok && x.party && r.partyL.indexOf(x.party) === -1) ok = false;
      if(ok && x.location && r.locationL.indexOf(x.location) === -1) ok = false;
      if(ok && x.user && r.addedByL.indexOf(x.user) === -1) ok = false;
      if(ok && (x.status === 'confirmed' || x.status === 'paid') && r.remaining > 0.0001) ok = false;
      if(ok && x.status === 'pending' && r.remaining <= 0.0001) ok = false;
      if(ok && x.sameMin !== null && r.sameStops < x.sameMin) ok = false;
      if(ok && x.outMin !== null && r.outStops < x.outMin) ok = false;
      if(ok && !inRange(r.totalCost, x.freightMin, x.freightMax)) ok = false;
      if(ok && x.profitMin !== null && r.profit < x.profitMin) ok = false;
      r.el.style.display = ok ? '' : 'none';
      if(ok) shown.push(r);
    });

    var totals = shown.reduce(function(a, r){
      a.count += 1; a.freight += r.freight; a.commission += r.commission; a.total += r.totalCost; a.tender += r.tender; a.remaining += r.remaining;
      a.paid += r.paid; a.profit += r.profit; a.same += r.sameStops; a.out += r.outStops; return a;
    }, {count:0,freight:0,commission:0,total:0,tender:0,remaining:0,paid:0,profit:0,same:0,out:0});
    var cards = [
      ['Bilties', intVal(totals.count)],
      ['Freight', money(totals.freight)],
      ['Commission', money(totals.commission)],
      ['Total Cost', money(totals.total)],
      ['Paid', money(totals.paid)],
      ['Remaining', money(totals.remaining)],
      ['Same City Stops', intVal(totals.same)],
      ['Out City Stops', intVal(totals.out)]
    ];
    if(isSuperAdmin){ cards.push(['Tender', money(totals.tender)]); cards.push(['Profit', money(totals.profit)]); }
    statsBox.innerHTML = cards.map(function(c){ return '<div class=\"a-stat\"><div class=\"k\">' + escHtml(c[0]) + '</div><div class=\"v\">' + escHtml(c[1]) + '</div></div>'; }).join('');

    var topVehicles = topMap(shown, 'vehicle', 'count', 6);
    var topLocations = topMap(shown, 'location', 'totalCost', 6);
    var topTypes = topMap(shown, 'type', 'count', 6);
    var byDate = {};
    shown.forEach(function(r){ var k = r.date || 'Unknown'; if(!byDate[k]) byDate[k] = 0; byDate[k] += r.totalCost; });
    var trend = Object.keys(byDate).sort().slice(-8).map(function(k){ return {label:k, value:byDate[k]}; });
    makeBars('haleeb_chart_vehicles', topVehicles, topVehicles.length ? topVehicles[0].value : 0, intVal);
    makeBars('haleeb_chart_locations', topLocations, topLocations.length ? topLocations[0].value : 0, money);
    makeBars('haleeb_chart_types', topTypes, topTypes.length ? topTypes[0].value : 0, intVal);
    makeBars('haleeb_chart_trend', trend, trend.length ? Math.max.apply(null, trend.map(function(r){ return r.value; })) : 0, money);

    var paidPct = totals.total > 0 ? (totals.paid / totals.total) * 100 : 0;
    var remPct = Math.max(100 - paidPct, 0);
    var paidBar = document.getElementById('haleeb_split_paid');
    var remBar = document.getElementById('haleeb_split_rem');
    if(paidBar) paidBar.style.width = paidPct.toFixed(2) + '%';
    if(remBar) remBar.style.width = remPct.toFixed(2) + '%';
    var paidLabel = document.getElementById('haleeb_split_paid_label');
    var remLabel = document.getElementById('haleeb_split_rem_label');
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

