<?php
session_start();
if(!isset($_SESSION['user'])){
    header("location:index.php");
    exit();
}

include 'config/db.php';

$total = $conn->query("SELECT SUM(tender - freight) AS t FROM haleeb_bilty")->fetch_assoc();
$total_profit = $total && $total['t'] ? $total['t'] : 0;
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$vehicleSearch = isset($_GET['vehicle']) ? trim((string)$_GET['vehicle']) : '';

$import_message = "";
if (isset($_GET['import'])) {
    if ($_GET['import'] === 'success') {
        $ins = isset($_GET['ins']) ? (int)$_GET['ins'] : 0;
        $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;
        $import_message = "Import completed. Inserted: $ins, Skipped: $skip";
    } elseif ($_GET['import'] === 'error') {
        $import_message = "Import failed. Please upload a valid CSV file.";
    }
}

$pay_message = "";
if (isset($_GET['pay'])) {
    if ($_GET['pay'] === 'success') $pay_message = "Payment posted successfully.";
    elseif ($_GET['pay'] === 'error') $pay_message = "Payment failed. Please try again.";
}

$vehicleOptions = [];
$vehicleRes = $conn->query("SELECT DISTINCT vehicle FROM haleeb_bilty WHERE vehicle IS NOT NULL AND vehicle <> '' ORDER BY vehicle ASC");
while($vehicleRes && $vrow = $vehicleRes->fetch_assoc()){
    $vehicleOptions[] = (string)$vrow['vehicle'];
}

$where = []; $bindValues = []; $bindTypes = "";
if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){ $where[] = "date >= ?"; $bindTypes .= "s"; $bindValues[] = $dateFrom; }
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){ $where[] = "date <= ?"; $bindTypes .= "s"; $bindValues[] = $dateTo; }
if($vehicleSearch !== ''){ $where[] = "vehicle LIKE ?"; $bindTypes .= "s"; $bindValues[] = "%" . $vehicleSearch . "%"; }

$sql = "SELECT *, (tender - freight) AS calc_profit FROM haleeb_bilty";
if(count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Haleeb</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
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
  .act-pay { background: rgba(34,197,94,0.15); color: var(--green); border-color: rgba(34,197,94,0.25); }
  .act-pay:hover { background: rgba(34,197,94,0.25); }
  .act-edit { background: rgba(96,165,250,0.15); color: var(--accent); border-color: rgba(96,165,250,0.25); }
  .act-edit:hover { background: rgba(96,165,250,0.25); }
  .act-pdf { background: rgba(168,85,247,0.15); color: #c084fc; border-color: rgba(168,85,247,0.25); }
  .act-pdf:hover { background: rgba(168,85,247,0.25); }
  .th-action { text-align: center; width: 90px; }

  .vtype-badge {
    display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase; background: rgba(96,165,250,0.12);
    color: var(--accent); border: 1px solid rgba(96,165,250,0.2);
  }

  @media(max-width: 900px) {
    .search-form { grid-template-columns: 1fr 1fr; }
    .search-form .field:nth-child(3) { grid-column: 1 / -1; }
    .search-actions { grid-column: 1 / -1; justify-content: flex-end; }
  }
  @media(max-width: 640px) {
    .topbar { padding: 14px 16px; }
    .main { padding: 16px; }
    .search-form { grid-template-columns: 1fr; }
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
    <a class="nav-btn" href="feed.php">Feed</a>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <div class="menu-wrap">
      <button class="menu-trigger" id="haleeb_menu_btn" type="button" aria-label="Menu">&#9776;</button>
      <div class="menu-pop" id="haleeb_menu_pop">
        <a class="nav-btn" href="export_haleeb.php">Export CSV</a>
        <div class="menu-sep"></div>
        <div class="import-row">
          <form action="import_haleeb.php" method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" accept=".csv" required>
            <button class="nav-btn" type="submit" style="width:100%">Import CSV</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="main">
  <?php if($import_message !== ""): ?>
    <div class="alert <?php echo strpos($import_message,'failed') !== false ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($import_message); ?>
    </div>
  <?php endif; ?>
  <?php if($pay_message !== ""): ?>
    <div class="alert <?php echo $pay_message === 'Payment failed. Please try again.' ? 'error' : ''; ?>">
      <?php echo htmlspecialchars($pay_message); ?>
    </div>
  <?php endif; ?>

  <div class="profit-banner">
    <div>
      <div class="profit-label">Total Profit</div>
      <div class="profit-value">Rs <?php echo number_format((float)$total_profit, 2); ?></div>
    </div>
  </div>

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

  <div class="table-wrap">
    <div class="tbl-header">
      <span class="tbl-header-title">Records</span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Vehicle</th>
          <th>Type</th>
          <th>Delivery Note</th>
          <th>Token No.</th>
          <th>Party</th>
          <th>Location</th>
          <th>Tender</th>
          <th>Freight</th>
          <th>Profit</th>
          <th class="th-action">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while($row = $result->fetch_assoc()):
          $profit = (float)$row['calc_profit'];
        ?>
        <tr>
          <td><?php echo htmlspecialchars($row['date']); ?></td>
          <td><?php echo htmlspecialchars($row['vehicle']); ?></td>
          <td><span class="vtype-badge"><?php echo htmlspecialchars($row['vehicle_type']); ?></span></td>
          <td><?php echo htmlspecialchars($row['delivery_note']); ?></td>
          <td><?php echo htmlspecialchars($row['token_no']); ?></td>
          <td><?php echo htmlspecialchars($row['party']); ?></td>
          <td><?php echo htmlspecialchars($row['location']); ?></td>
          <td>Rs <?php echo number_format((float)$row['tender'], 2); ?></td>
          <td>Rs <?php echo number_format((float)$row['freight'], 2); ?></td>
          <td class="td-profit <?php echo $profit < 0 ? 'neg' : ''; ?>">
            Rs <?php echo number_format($profit, 2); ?>
          </td>
          <td>
            <div class="action-cell">
              <a class="act-btn act-pay" href="pay_now_haleeb.php?id=<?php echo $row['id']; ?>" title="Pay">&#8377;</a>
              <a class="act-btn act-edit" href="edit_haleeb_bilty.php?id=<?php echo $row['id']; ?>" title="Edit">&#9998;</a>
              <a class="act-btn act-pdf" href="haleeb_pdf.php?id=<?php echo $row['id']; ?>" target="_blank" title="PDF">&#128196;</a>
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
  var btn = document.getElementById('haleeb_menu_btn');
  var pop = document.getElementById('haleeb_menu_pop');
  if(!btn||!pop) return;
  btn.addEventListener('click', function(e){ e.stopPropagation(); pop.classList.toggle('open'); });
  document.addEventListener('click', function(e){ if(!pop.contains(e.target) && e.target !== btn) pop.classList.remove('open'); });
})();
</script>
</body>
</html>
