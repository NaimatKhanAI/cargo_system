<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('account');

$canManageUsers = auth_can_manage_users();
$canReviewActivity = auth_can_review_activity();
$canManageLedger = auth_can_direct_modify('account');
$flaggedActivityCount = activity_count_flagged_for_admin_local($conn);

function bilty_detail_link_local($moduleType, $entityId, $source = 'all_bilties'){
    $moduleType = strtolower(trim((string)$moduleType));
    $entityId = (int)$entityId;
    if($entityId <= 0) return '';
    if(!in_array($moduleType, ['feed', 'haleeb'], true)) return '';
    return 'bilty_detail.php?type=' . rawurlencode($moduleType) . '&id=' . $entityId . '&src=' . rawurlencode((string)$source);
}

$module = isset($_GET['module']) ? strtolower(trim((string)$_GET['module'])) : 'all';
if(!in_array($module, ['all', 'feed', 'haleeb'], true)) $module = 'all';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$returnTailParts = [];
$returnTailParts[] = 'module=' . rawurlencode($module);
if($dateFrom !== '') $returnTailParts[] = 'date_from=' . rawurlencode($dateFrom);
if($dateTo !== '') $returnTailParts[] = 'date_to=' . rawurlencode($dateTo);
$returnToAllBilties = 'all_bilties.php' . (count($returnTailParts) > 0 ? ('?' . implode('&', $returnTailParts)) : '');

$feedWhere = [];
$haleebWhere = [];
if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){
    $safe = $conn->real_escape_string($dateFrom);
    $feedWhere[] = "b.date >= '" . $safe . "'";
    $haleebWhere[] = "h.date >= '" . $safe . "'";
}
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){
    $safe = $conn->real_escape_string($dateTo);
    $feedWhere[] = "b.date <= '" . $safe . "'";
    $haleebWhere[] = "h.date <= '" . $safe . "'";
}
$feedWhereSql = count($feedWhere) > 0 ? (' WHERE ' . implode(' AND ', $feedWhere)) : '';
$haleebWhereSql = count($haleebWhere) > 0 ? (' WHERE ' . implode(' AND ', $haleebWhere)) : '';

$feedSelect = "SELECT
  'feed' AS module_type,
  COALESCE(b.feed_portion, '') AS feed_section_key,
  b.id AS bilty_id,
  b.date AS bilty_date,
  COALESCE(NULLIF(b.bilty_no,''), CONCAT('#', b.id)) AS ref_no,
  COALESCE(b.vehicle, '') AS vehicle,
  COALESCE(b.party, '') AS party,
  COALESCE(b.location, '') AS location,
  COALESCE(b.freight, 0) AS freight,
  COALESCE(b.commission, 0) AS commission,
  COALESCE(b.original_freight, GREATEST((COALESCE(b.freight,0) - COALESCE(b.commission,0)), 0)) AS total_cost,
  COALESCE(b.freight_payment_type, 'to_pay') AS freight_payment_type,
  CASE
    WHEN COALESCE(NULLIF(LOWER(TRIM(b.freight_payment_type)), ''), 'to_pay') = 'to_pay' THEN 0
    ELSE GREATEST(COALESCE(b.original_freight, GREATEST((COALESCE(b.freight,0) - COALESCE(b.commission,0)), 0)) - COALESCE(fp.paid_total, 0), 0)
  END AS remaining_balance
FROM bilty b
LEFT JOIN (
  SELECT bilty_id, SUM(amount) AS paid_total
  FROM account_entries
  WHERE bilty_id IS NOT NULL AND entry_type='debit'
  GROUP BY bilty_id
) fp ON fp.bilty_id = b.id" . $feedWhereSql;

$haleebSelect = "SELECT
  'haleeb' AS module_type,
  '' AS feed_section_key,
  h.id AS bilty_id,
  h.date AS bilty_date,
  COALESCE(NULLIF(h.token_no,''), CONCAT('#', h.id)) AS ref_no,
  COALESCE(h.vehicle, '') AS vehicle,
  COALESCE(h.party, '') AS party,
  COALESCE(h.location, '') AS location,
  COALESCE(h.freight, 0) AS freight,
  COALESCE(h.commission, 0) AS commission,
  GREATEST((COALESCE(h.freight,0) - COALESCE(h.commission,0)), 0) AS total_cost,
  COALESCE(h.freight_payment_type, 'to_pay') AS freight_payment_type,
  CASE
    WHEN COALESCE(NULLIF(LOWER(TRIM(h.freight_payment_type)), ''), 'to_pay') = 'to_pay' THEN 0
    ELSE GREATEST(GREATEST((COALESCE(h.freight,0) - COALESCE(h.commission,0)), 0) - COALESCE(hp.paid_total, 0), 0)
  END AS remaining_balance
FROM haleeb_bilty h
LEFT JOIN (
  SELECT haleeb_bilty_id, SUM(amount) AS paid_total
  FROM account_entries
  WHERE haleeb_bilty_id IS NOT NULL AND entry_type='debit'
  GROUP BY haleeb_bilty_id
) hp ON hp.haleeb_bilty_id = h.id" . $haleebWhereSql;

if($module === 'feed'){
    $allBiltiesSql = $feedSelect . " ORDER BY CASE WHEN ROUND(remaining_balance, 2) > 0 THEN 0 ELSE 1 END ASC, bilty_date DESC, bilty_id DESC";
} elseif($module === 'haleeb'){
    $allBiltiesSql = $haleebSelect . " ORDER BY CASE WHEN ROUND(remaining_balance, 2) > 0 THEN 0 ELSE 1 END ASC, bilty_date DESC, bilty_id DESC";
} else {
    $allBiltiesSql = "SELECT * FROM (" . $feedSelect . " UNION ALL " . $haleebSelect . ") x ORDER BY CASE WHEN ROUND(x.remaining_balance, 2) > 0 THEN 0 ELSE 1 END ASC, x.bilty_date DESC, x.bilty_id DESC";
}

$allBilties = [];
$res = $conn->query($allBiltiesSql);
while($res && $r = $res->fetch_assoc()){
    $allBilties[] = $r;
}

$totalRows = count($allBilties);
$totalCost = 0.0;
$totalRemaining = 0.0;
$confirmedCount = 0;
$pendingCount = 0;
foreach($allBilties as $r){
    $cost = (float)($r['total_cost'] ?? 0);
    $remaining = (float)($r['remaining_balance'] ?? 0);
    $remainingRounded = round($remaining, 2);
    $totalCost += $cost;
    $totalRemaining += $remaining;
    if($remainingRounded <= 0) $confirmedCount++;
    else $pendingCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>All Bilties</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --green: #22c55e; --red: #ef4444; --blue: #60a5fa; --purple: #c084fc;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }
  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 28px; border-bottom: 1px solid var(--border); background: var(--surface); position: sticky; top: 0; z-index: 100; }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge-pill { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .nav-btn { padding: 8px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .nav-btn.danger { background: rgba(239,68,68,0.12); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .main { padding: 24px 28px; max-width: 1500px; margin: 0 auto; }
  .panel { background: var(--surface); border: 1px solid var(--border); margin-bottom: 16px; }
  .panel-head { padding: 14px 18px; border-bottom: 1px solid var(--border); }
  .panel-title { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); font-weight: 700; }
  .form-grid { display: grid; grid-template-columns: repeat(4, 1fr) auto; gap: 10px; align-items: end; padding: 14px 18px; }
  .field label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .field input, .field select { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 9px 10px; font-family: var(--font); font-size: 13px; }
  .field input:focus, .field select:focus { outline: none; border-color: var(--accent); }
  .actions { display: flex; gap: 8px; }
  .btn-apply { padding: 9px 16px; background: var(--surface2); color: var(--text); border: 1px solid var(--border); cursor: pointer; font-family: var(--font); font-size: 13px; font-weight: 600; }
  .btn-apply:hover { border-color: var(--muted); }
  .btn-reset { padding: 9px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border); text-decoration: none; font-size: 13px; font-weight: 600; }
  .stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; padding: 0 18px 16px; }
  .stat { background: var(--surface2); border: 1px solid var(--border); padding: 10px 12px; }
  .stat .k { color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 4px; font-weight: 700; }
  .stat .v { font-family: var(--mono); font-size: 16px; }
  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .tbl-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  .tiny-meta { font-size: 11px; color: var(--muted); font-family: var(--mono); }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: var(--surface2); }
  th { padding: 11px 14px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid rgba(42,45,53,0.7); font-family: var(--mono); }
  tbody tr:hover { background: var(--surface2); }
  .cat-badge { display: inline-block; padding: 3px 8px; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; background: rgba(240,192,64,0.1); color: var(--accent); border: 1px solid rgba(240,192,64,0.2); }
  .mode-badge { display: inline-block; padding: 3px 8px; font-size: 10px; font-weight: 600; letter-spacing: 0.8px; text-transform: uppercase; }
  .mode-to-pay { background: rgba(96,165,250,0.1); color: var(--blue); border: 1px solid rgba(96,165,250,0.2); }
  .mode-paid { background: rgba(192,132,252,0.1); color: var(--purple); border: 1px solid rgba(192,132,252,0.2); }
  .type-badge { display: inline-block; padding: 3px 10px; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
  .type-confirmed { background: rgba(34,197,94,0.12); color: var(--green); border: 1px solid rgba(34,197,94,0.25); }
  .type-pending { background: rgba(239,68,68,0.12); color: var(--red); border: 1px solid rgba(239,68,68,0.25); }
  .row-actions { display: flex; align-items: center; gap: 8px; }
  .act-pay {
    width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(34,197,94,0.15); color: var(--green); border: 1px solid rgba(34,197,94,0.25);
    text-decoration: none; font-size: 14px; transition: all 0.15s;
  }
  .act-pay:hover { background: rgba(34,197,94,0.28); }
  .act-detail {
    width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(96,165,250,0.16); color: var(--blue); border: 1px solid rgba(96,165,250,0.28);
    text-decoration: none; font-size: 14px; transition: all 0.15s;
  }
  .act-detail:hover { background: rgba(96,165,250,0.30); }
  .ref-link { color: var(--blue); text-decoration: none; border-bottom: 1px solid rgba(96,165,250,0.35); font-family: var(--font); font-size: 12px; }
  .ref-link:hover { color: #93c5fd; border-bottom-color: rgba(147,197,253,0.8); }
  @media(max-width: 1100px){
    .form-grid { grid-template-columns: 1fr 1fr; }
    .stats { grid-template-columns: 1fr 1fr; }
  }
  @media(max-width: 700px){
    .topbar { padding: 14px 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .main { padding: 16px; }
    .stats { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">
    <span class="badge-pill">Bilties</span>
    <h1>All Bilties (Feed + Haleeb)</h1>
  </div>
  <div class="nav-links">
    <?php if($canReviewActivity): ?>
      <a class="nav-btn" href="activity_review.php">Activity Review<?php echo $flaggedActivityCount > 0 ? ' (' . $flaggedActivityCount . ')' : ''; ?></a>
    <?php endif; ?>
    <?php if($canManageUsers): ?><a class="nav-btn" href="super_admin.php">Super Admin</a><?php endif; ?>
    <a class="nav-btn" href="account.php">Account</a>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <a class="nav-btn danger" href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <div class="panel">
    <div class="panel-head"><span class="panel-title">Server Filters</span></div>
    <form class="form-grid" method="get">
      <div class="field">
        <label for="module">Module</label>
        <select id="module" name="module">
          <option value="all" <?php echo $module === 'all' ? 'selected' : ''; ?>>All</option>
          <option value="feed" <?php echo $module === 'feed' ? 'selected' : ''; ?>>Feed</option>
          <option value="haleeb" <?php echo $module === 'haleeb' ? 'selected' : ''; ?>>Haleeb</option>
        </select>
      </div>
      <div class="field">
        <label for="date_from">From Date</label>
        <input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
      </div>
      <div class="field">
        <label for="date_to">To Date</label>
        <input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
      </div>
      <div class="actions">
        <button class="btn-apply" type="submit">Apply</button>
        <a class="btn-reset" href="all_bilties.php">Reset</a>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-head"><span class="panel-title">Analytics Filters</span></div>
    <div class="form-grid">
      <div class="field">
        <label for="a_text">Search (Ref / Vehicle / Party / Location)</label>
        <input id="a_text" type="text" placeholder="Search">
      </div>
      <div class="field">
        <label for="a_module">Module</label>
        <select id="a_module">
          <option value="">All</option>
          <option value="feed">Feed</option>
          <option value="haleeb">Haleeb</option>
        </select>
      </div>
      <div class="field">
        <label for="a_section">Section</label>
        <select id="a_section">
          <option value="">All</option>
          <option value="amir">Amir</option>
          <option value="hamid">Hamid</option>
          <option value="ilyas">Ilyas</option>
        </select>
      </div>
      <div class="field">
        <label for="a_driver">Driver Payment</label>
        <select id="a_driver">
          <option value="">All</option>
          <option value="to_pay">To Pay</option>
          <option value="paid">Paid</option>
        </select>
      </div>
      <div class="field">
        <label for="a_status">Status</label>
        <select id="a_status">
          <option value="">All</option>
          <option value="confirmed">Confirmed</option>
          <option value="pending">Pending</option>
        </select>
      </div>
      <div class="actions">
        <button class="btn-apply" type="button" id="a_reset">Reset Analytics</button>
      </div>
    </div>
    <div class="stats" id="a_stats">
      <div class="stat"><div class="k">Rows</div><div class="v"><?php echo $totalRows; ?></div></div>
      <div class="stat"><div class="k">Total Cost</div><div class="v">Rs <?php echo format_amount_local($totalCost, 1); ?></div></div>
      <div class="stat"><div class="k">Remaining</div><div class="v">Rs <?php echo format_amount_local($totalRemaining, 1); ?></div></div>
      <div class="stat"><div class="k">Confirmed</div><div class="v"><?php echo $confirmedCount; ?></div></div>
      <div class="stat"><div class="k">Pending</div><div class="v"><?php echo $pendingCount; ?></div></div>
    </div>
  </div>

  <div class="table-wrap">
    <div class="tbl-header">
      <span class="tbl-title">Records</span>
      <span class="tiny-meta" id="count_label">Rows: <?php echo $totalRows; ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Module</th>
          <th>Date</th>
          <th>Ref</th>
          <th>Vehicle</th>
          <th>Party</th>
          <th>Location</th>
          <th>Freight</th>
          <th>Commission</th>
          <th>Total Cost</th>
          <th>Driver Payment</th>
          <th>Status</th>
          <th>Remaining</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="all_bilties_tbody">
      <?php if(count($allBilties) === 0): ?>
        <tr><td colspan="13" class="tiny-meta" style="padding:14px;">No bilties found.</td></tr>
      <?php else: ?>
        <?php foreach($allBilties as $brow):
          $moduleType = strtolower((string)($brow['module_type'] ?? 'feed'));
          if(!in_array($moduleType, ['feed', 'haleeb'], true)) $moduleType = 'feed';
          $sectionLabel = '';
          if($moduleType === 'feed'){
            $rawSectionKey = trim((string)($brow['feed_section_key'] ?? ''));
            if($rawSectionKey !== ''){
              $sectionLabel = strtolower(feed_portion_label_local($rawSectionKey));
            }
          }
          $driverType = strtolower(trim((string)($brow['freight_payment_type'] ?? 'to_pay')));
          if(!in_array($driverType, ['to_pay', 'paid'], true)) $driverType = 'to_pay';
          $remaining = (float)($brow['remaining_balance'] ?? 0);
          $remainingRounded = round($remaining, 2);
          $statusText = $remainingRounded <= 0 ? 'confirmed' : 'pending';
          $searchBlob = strtolower(trim(
            (string)($brow['ref_no'] ?? '') . ' ' .
            (string)($brow['vehicle'] ?? '') . ' ' .
            (string)($brow['party'] ?? '') . ' ' .
            (string)($brow['location'] ?? '') . ' ' .
            (string)$sectionLabel
          ));
          $payHref = '';
          if($remainingRounded > 0){
            $payHref = $moduleType === 'haleeb'
              ? ('pay_now_haleeb.php?id=' . (int)($brow['bilty_id'] ?? 0) . '&src=all_bilties&back=' . rawurlencode($returnToAllBilties))
              : ('pay_now.php?id=' . (int)($brow['bilty_id'] ?? 0) . '&src=all_bilties&back=' . rawurlencode($returnToAllBilties));
          }
          $detailHref = bilty_detail_link_local($moduleType, (int)($brow['bilty_id'] ?? 0), 'all_bilties');
        ?>
        <tr
          data-row="1"
          data-search="<?php echo htmlspecialchars($searchBlob); ?>"
          data-module="<?php echo htmlspecialchars($moduleType); ?>"
          data-section="<?php echo htmlspecialchars($sectionLabel); ?>"
          data-driver="<?php echo htmlspecialchars($driverType); ?>"
          data-status="<?php echo htmlspecialchars($statusText); ?>"
          data-total="<?php echo (float)($brow['total_cost'] ?? 0); ?>"
          data-remaining="<?php echo $remaining; ?>"
        >
          <td><span class="cat-badge"><?php echo htmlspecialchars(strtoupper($moduleType)); ?></span></td>
          <td><?php echo htmlspecialchars((string)($brow['bilty_date'] ?? '')); ?></td>
          <td><?php echo htmlspecialchars((string)($brow['ref_no'] ?? '')); ?></td>
          <td><?php echo htmlspecialchars((string)($brow['vehicle'] ?? '')); ?></td>
          <td><?php echo htmlspecialchars((string)($brow['party'] ?? '')); ?></td>
          <td><?php echo htmlspecialchars((string)($brow['location'] ?? '')); ?></td>
          <td>Rs <?php echo format_amount_local((float)($brow['freight'] ?? 0), 1); ?></td>
          <td>Rs <?php echo format_amount_local((float)($brow['commission'] ?? 0), 1); ?></td>
          <td>Rs <?php echo format_amount_local((float)($brow['total_cost'] ?? 0), 1); ?></td>
          <td><span class="mode-badge <?php echo $driverType === 'paid' ? 'mode-paid' : 'mode-to-pay'; ?>"><?php echo $driverType === 'paid' ? 'Paid' : 'To Pay'; ?></span></td>
          <td><span class="type-badge <?php echo $statusText === 'confirmed' ? 'type-confirmed' : 'type-pending'; ?>"><?php echo ucfirst($statusText); ?></span></td>
          <td>Rs <?php echo format_amount_local($remaining, 1); ?></td>
          <td>
            <div class="row-actions">
              <?php if($canManageLedger && $payHref !== ''): ?>
                <a class="act-pay" href="<?php echo htmlspecialchars($payHref); ?>" title="Pay">&#8377;</a>
              <?php endif; ?>
              <?php if($detailHref !== ''): ?>
                <a class="act-detail" href="<?php echo htmlspecialchars($detailHref); ?>" title="Detail">&#128065;</a>
              <?php endif; ?>
              <?php if((!$canManageLedger || $payHref === '') && $detailHref === ''): ?>
                <span class="tiny-meta">-</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var rows = Array.prototype.slice.call(document.querySelectorAll('#all_bilties_tbody tr[data-row="1"]'));
  var text = document.getElementById('a_text');
  var moduleSel = document.getElementById('a_module');
  var sectionSel = document.getElementById('a_section');
  var driverSel = document.getElementById('a_driver');
  var statusSel = document.getElementById('a_status');
  var resetBtn = document.getElementById('a_reset');
  var countLabel = document.getElementById('count_label');
  var statsBox = document.getElementById('a_stats');
  if(!countLabel || !statsBox) return;

  function val(el){ return el ? String(el.value || '').trim().toLowerCase() : ''; }
  function money(v){ return Number(v || 0).toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:1}); }

  function apply(){
    var xText = val(text);
    var xModule = val(moduleSel);
    var xSection = val(sectionSel);
    var xDriver = val(driverSel);
    var xStatus = val(statusSel);
    var shown = 0, total = 0, remaining = 0, confirmed = 0, pending = 0;
    rows.forEach(function(r){
      var d = r.dataset || {};
      var ok = true;
      if(xText && String(d.search || '').indexOf(xText) === -1) ok = false;
      if(ok && xModule && String(d.module || '') !== xModule) ok = false;
      if(ok && xSection && String(d.section || '') !== xSection) ok = false;
      if(ok && xDriver && String(d.driver || '') !== xDriver) ok = false;
      if(ok && xStatus && String(d.status || '') !== xStatus) ok = false;
      r.style.display = ok ? '' : 'none';
      if(ok){
        shown += 1;
        total += Number(d.total || 0);
        remaining += Number(d.remaining || 0);
        if(String(d.status || '') === 'confirmed') confirmed += 1;
        else pending += 1;
      }
    });

    countLabel.textContent = 'Rows: ' + shown;
    statsBox.innerHTML = ''
      + '<div class="stat"><div class="k">Rows</div><div class="v">' + shown + '</div></div>'
      + '<div class="stat"><div class="k">Total Cost</div><div class="v">Rs ' + money(total) + '</div></div>'
      + '<div class="stat"><div class="k">Remaining</div><div class="v">Rs ' + money(remaining) + '</div></div>'
      + '<div class="stat"><div class="k">Confirmed</div><div class="v">' + confirmed + '</div></div>'
      + '<div class="stat"><div class="k">Pending</div><div class="v">' + pending + '</div></div>';
  }

  [text, moduleSel, sectionSel, driverSel, statusSel].forEach(function(el){
    if(!el) return;
    el.addEventListener('input', apply);
    el.addEventListener('change', apply);
  });
  if(resetBtn){
    resetBtn.addEventListener('click', function(){
      if(text) text.value = '';
      if(moduleSel) moduleSel.selectedIndex = 0;
      if(sectionSel) sectionSel.selectedIndex = 0;
      if(driverSel) driverSel.selectedIndex = 0;
      if(statusSel) statusSel.selectedIndex = 0;
      apply();
    });
  }
  apply();
})();
</script>
</body>
</html>
