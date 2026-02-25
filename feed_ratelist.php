<?php
session_start();
if(!isset($_SESSION['user'])){
    header("location:index.php");
    exit();
}

include 'config/db.php';

function slugify_label_to_key($label){
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');
    if($key === '') $key = 'column';
    if(strpos($key, 'custom_') !== 0) $key = 'custom_' . $key;
    return $key;
}
function load_columns($conn){
    $cols = [];
    $res = $conn->query("SELECT id, column_key, column_label, is_hidden, is_deleted, display_order, is_base FROM rate_list_columns ORDER BY display_order ASC, id ASC");
    while($r = $res->fetch_assoc()) $cols[] = $r;
    return $cols;
}
function normalize_header_local($v){ $v = strtolower(trim((string)$v)); $v = preg_replace('/\s+/', ' ', $v); $v = str_replace(['.','(',')'], '', $v); return $v; }
function normalize_digits_local($v){ $v = (string)$v; $map = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']; return strtr($v, $map); }
function canonical_sr_local($v){ $v = normalize_digits_local((string)$v); $v = strtolower(trim($v)); return preg_replace('/[^a-z0-9]/u', '', $v); }
function detect_sr_column_key_local($columns){ foreach($columns as $c){ if(isset($c['column_key']) && $c['column_key'] === 'sr_no') return 'sr_no'; } foreach($columns as $c){ $lbl = isset($c['column_label']) ? normalize_header_local($c['column_label']) : ''; if(in_array($lbl, ['sr','sr no','serial','serial no','serial number'], true)) return (string)$c['column_key']; } return ''; }
function sr_exists_local($conn, $srKey, $srValue, $excludeId = 0){ $srCanon = canonical_sr_local($srValue); if($srCanon === '' || $srKey === '') return false; $res = $conn->query("SELECT id, sr_no, extra_data FROM image_processed_rates"); while($res && $row = $res->fetch_assoc()){ $id = (int)$row['id']; if($excludeId > 0 && $id === $excludeId) continue; $candidate = ''; if($srKey === 'sr_no') $candidate = isset($row['sr_no']) ? (string)$row['sr_no'] : ''; else { $extra = json_decode((string)$row['extra_data'], true); if(is_array($extra) && isset($extra[$srKey])) $candidate = (string)$extra[$srKey]; } if(canonical_sr_local($candidate) === $srCanon) return true; } return false; }

$msg = ''; $err = ''; $editingId = 0; $openAddRow = false;

if(isset($_GET['delete_all']) && $_GET['delete_all'] === '1'){ $conn->query("DELETE FROM image_processed_rates"); $msg = 'Rate list cleared.'; }
if(isset($_GET['import'])){ if($_GET['import'] === 'success'){ $ins = isset($_GET['ins']) ? (int)$_GET['ins'] : 0; $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0; $msg = "Import completed. Inserted: $ins, Skipped: $skip"; } elseif($_GET['import'] === 'error'){ $reason = isset($_GET['reason']) ? trim((string)$_GET['reason']) : ''; $err = $reason === 'no_columns' ? 'Import failed. No active Rate List columns found.' : 'Import failed. Please upload a valid CSV file.'; } }

if(isset($_POST['add_column'])){ $label = isset($_POST['new_column_label']) ? trim($_POST['new_column_label']) : ''; if($label === ''){ $err = 'Column name required.'; } else { $baseKey = slugify_label_to_key($label); $key = $baseKey; $suffix = 1; while(true){ $chk = $conn->prepare("SELECT id FROM rate_list_columns WHERE column_key=? LIMIT 1"); $chk->bind_param("s", $key); $chk->execute(); $exists = $chk->get_result()->num_rows > 0; $chk->close(); if(!$exists) break; $suffix++; $key = $baseKey . '_' . $suffix; } $maxOrderRes = $conn->query("SELECT COALESCE(MAX(display_order),0) AS m FROM rate_list_columns")->fetch_assoc(); $nextOrder = ((int)$maxOrderRes['m']) + 1; $ins = $conn->prepare("INSERT INTO rate_list_columns(column_key, column_label, is_hidden, is_deleted, display_order, is_base) VALUES(?, ?, 0, 0, ?, 0)"); $ins->bind_param("ssi", $key, $label, $nextOrder); $ins->execute(); $ins->close(); $msg = 'New column added.'; } }
if(isset($_POST['save_columns'])){ $cols = load_columns($conn); foreach($cols as $c){ $key = $c['column_key']; $labelField = 'label_' . $key; $hideField = 'hide_' . $key; $newLabel = isset($_POST[$labelField]) ? trim($_POST[$labelField]) : $c['column_label']; $isHidden = isset($_POST[$hideField]) ? 1 : 0; if($newLabel === '') $newLabel = $c['column_label']; $upd = $conn->prepare("UPDATE rate_list_columns SET column_label=?, is_hidden=? WHERE column_key=?"); $upd->bind_param("sis", $newLabel, $isHidden, $key); $upd->execute(); $upd->close(); } $msg = 'Column settings updated.'; }
if(isset($_POST['delete_column'])){ $key = isset($_POST['column_key']) ? trim($_POST['column_key']) : ''; if($key !== ''){ $upd = $conn->prepare("UPDATE rate_list_columns SET is_deleted=1 WHERE column_key=?"); $upd->bind_param("s", $key); $upd->execute(); $upd->close(); $msg = 'Column deleted.'; } }
if(isset($_POST['update_rate'])){ $editingId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0; if($editingId <= 0){ $err = 'Invalid row selected.'; } else { $allCols = load_columns($conn); $activeCols = array_values(array_filter($allCols, function($c){ return (int)$c['is_deleted'] === 0; })); $base = ['sr_no'=>'','station_english'=>'','station_urdu'=>'','rate1'=>'','rate2'=>'']; $extra = []; foreach($activeCols as $c){ $key = $c['column_key']; $val = isset($_POST['col_' . $key]) ? trim($_POST['col_' . $key]) : ''; if(array_key_exists($key, $base)) $base[$key] = $val; else $extra[$key] = $val; } $srKey = detect_sr_column_key_local($activeCols); $srVal = $srKey === 'sr_no' ? $base['sr_no'] : ($extra[$srKey] ?? ''); if($srKey !== '' && sr_exists_local($conn, $srKey, $srVal, $editingId)){ $err = 'Duplicate SR not allowed.'; } else { $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE); $upd = $conn->prepare("UPDATE image_processed_rates SET sr_no=?, station_english=?, station_urdu=?, rate1=?, rate2=?, extra_data=? WHERE id=?"); $upd->bind_param("ssssssi", $base['sr_no'], $base['station_english'], $base['station_urdu'], $base['rate1'], $base['rate2'], $extraJson, $editingId); $upd->execute(); $upd->close(); $msg = 'Rate row updated.'; $editingId = 0; } } }
if(isset($_POST['add_rate'])){ $openAddRow = true; $allCols = load_columns($conn); $activeCols = array_values(array_filter($allCols, function($c){ return (int)$c['is_deleted'] === 0; })); $base = ['sr_no'=>'','station_english'=>'','station_urdu'=>'','rate1'=>'','rate2'=>'']; $extra = []; foreach($activeCols as $c){ $key = $c['column_key']; $val = isset($_POST['add_col_' . $key]) ? trim($_POST['add_col_' . $key]) : ''; if(array_key_exists($key, $base)) $base[$key] = $val; else $extra[$key] = $val; } $srKey = detect_sr_column_key_local($activeCols); $srVal = $srKey === 'sr_no' ? $base['sr_no'] : ($extra[$srKey] ?? ''); if($srKey !== '' && $srVal !== '' && sr_exists_local($conn, $srKey, $srVal, 0)){ $err = 'Duplicate SR not allowed.'; } else { $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE); $ins = $conn->prepare("INSERT INTO image_processed_rates(source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, extra_data) VALUES('', '', ?, ?, ?, ?, ?, ?)"); $ins->bind_param("ssssss", $base['sr_no'], $base['station_english'], $base['station_urdu'], $base['rate1'], $base['rate2'], $extraJson); $ins->execute(); $ins->close(); $msg = 'New rate row added.'; } }
if(isset($_GET['edit_id']) && !isset($_POST['update_rate'])) $editingId = (int)$_GET['edit_id'];

$allColumns = load_columns($conn);
$displayColumns = array_values(array_filter($allColumns, function($c){ return (int)$c['is_deleted'] === 0; }));
$visibleColumns = array_values(array_filter($displayColumns, function($c){ return (int)$c['is_hidden'] === 0; }));
$rows = $conn->query("SELECT id, source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, extra_data, created_at FROM image_processed_rates ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Rate List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --green: #22c55e; --red: #ef4444; --blue: #60a5fa;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 28px; border-bottom: 1px solid var(--border); background: var(--surface); position: sticky; top: 0; z-index: 100; flex-wrap: wrap; gap: 10px; }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .nav-btn { padding: 8px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .nav-btn.primary { background: var(--accent); color: #0e0f11; border-color: var(--accent); }
  .nav-btn.primary:hover { background: #e0b030; }
  .nav-btn.danger { background: rgba(239,68,68,0.1); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .nav-btn.danger:hover { background: rgba(239,68,68,0.2); }
  .menu-wrap { position: relative; }
  .menu-toggle { width: 38px; height: 36px; display: inline-flex; align-items: center; justify-content: center; padding: 0; font-size: 18px; line-height: 1; }
  .menu-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 8px); min-width: 170px; background: var(--surface2); border: 1px solid var(--border); z-index: 120; }
  .menu-dropdown.open { display: block; }
  .menu-item { width: 100%; display: block; text-align: left; padding: 10px 12px; background: transparent; color: var(--text); border: none; border-bottom: 1px solid var(--border); text-decoration: none; font-family: var(--font); font-size: 12px; cursor: pointer; }
  .menu-item:last-child { border-bottom: none; }
  .menu-item:hover { background: rgba(255,255,255,0.04); }
  .menu-item.danger { color: var(--red); }
  .menu-import-form { margin: 0; }
  .menu-import-input { display: none; }

  .main { padding: 24px 28px; max-width: 1600px; margin: 0 auto; }
  .alert { padding: 12px 16px; margin-bottom: 16px; font-size: 13px; border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green); }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

  /* COLLAPSIBLE PANELS */
  .panel { background: var(--surface); border: 1px solid var(--border); margin-bottom: 14px; }
  .panel-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; cursor: pointer; user-select: none; }
  .panel-head:hover { background: var(--surface2); }
  .panel-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  .panel-toggle { font-size: 18px; color: var(--muted); line-height: 1; transition: transform 0.2s; }
  .panel-toggle.open { transform: rotate(45deg); }
  .panel-body { display: none; padding: 16px 18px; border-top: 1px solid var(--border); }
  .panel-body.open { display: block; }

  /* COLUMN SETTINGS */
  .add-col-row { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; }
  .add-col-row input { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 9px 12px; font-family: var(--font); font-size: 13px; width: 260px; }
  .add-col-row input:focus { outline: none; border-color: var(--accent); }
  .col-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; margin-bottom: 14px; }
  .col-item { background: var(--surface2); border: 1px solid var(--border); padding: 12px; }
  .col-item-key { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-family: var(--mono); }
  .col-item input[type="text"] { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 7px 10px; font-family: var(--font); font-size: 13px; margin-bottom: 8px; }
  .col-item input[type="text"]:focus { outline: none; border-color: var(--accent); }
  .col-item-footer { display: flex; align-items: center; justify-content: space-between; }
  .check-label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); cursor: pointer; }
  .check-label input[type="checkbox"] { accent-color: var(--accent); width: 14px; height: 14px; }
  .btn-del-col { padding: 5px 10px; background: rgba(239,68,68,0.1); color: var(--red); border: 1px solid rgba(239,68,68,0.2); cursor: pointer; font-family: var(--font); font-size: 11px; font-weight: 700; transition: all 0.15s; }
  .btn-del-col:hover { background: rgba(239,68,68,0.2); }

  /* TABLE */
  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .tbl-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  table { width: 100%; border-collapse: collapse; min-width: 600px; }
  thead tr { background: var(--surface2); }
  th { padding: 11px 12px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  td { padding: 9px 12px; font-size: 13px; border-bottom: 1px solid rgba(42,45,53,0.7); font-family: var(--mono); color: var(--text); }
  tbody tr { transition: background 0.1s; }
  tbody tr:hover { background: var(--surface2); }
  .edit-row td { background: rgba(240,192,64,0.04); }
  .edit-input { width: 100%; background: var(--bg); border: 1px solid var(--accent); color: var(--text); padding: 6px 8px; font-family: var(--mono); font-size: 12px; }
  .edit-input:focus { outline: none; }

  .act-btn { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; border: 1px solid transparent; transition: all 0.15s; cursor: pointer; margin-right: 3px; }
  .act-view { background: rgba(96,165,250,0.12); color: var(--blue); border-color: rgba(96,165,250,0.25); }
  .act-view:hover { background: rgba(96,165,250,0.25); }
  .act-edit { background: rgba(240,192,64,0.12); color: var(--accent); border-color: rgba(240,192,64,0.25); }
  .act-edit:hover { background: rgba(240,192,64,0.25); }
  .act-save { background: rgba(34,197,94,0.12); color: var(--green); border-color: rgba(34,197,94,0.25); padding: 6px 12px; width: auto; height: auto; font-family: var(--font); font-size: 12px; font-weight: 700; }
  .act-save:hover { background: rgba(34,197,94,0.25); }
  .act-cancel { background: var(--surface2); color: var(--muted); border-color: var(--border); padding: 6px 10px; width: auto; height: auto; font-family: var(--font); font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; }
  .act-cancel:hover { color: var(--text); }
  .th-action { text-align: center; width: 80px; white-space: nowrap; }
  .td-action { text-align: center; white-space: nowrap; }
  .td-date { color: var(--muted); font-size: 11px; }

  @media(max-width: 640px) {
    .topbar { padding: 14px 16px; }
    .main { padding: 16px; }
    .add-col-row input { width: 180px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Rate List</span>
    <h1>Rate List</h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn" href="feed.php">Feed</a>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <div class="menu-wrap">
      <button type="button" class="nav-btn menu-toggle" id="menu_toggle" aria-label="Open Menu" aria-expanded="false">&#9776;</button>
      <div class="menu-dropdown" id="menu_dropdown">
        <form class="menu-import-form" action="import_rate_list.php" method="post" enctype="multipart/form-data" id="menu_import_form">
          <input class="menu-import-input" id="menu_import_file" type="file" name="csv_file" accept=".csv" required>
          <button class="menu-item" type="button" id="menu_import_btn">Import</button>
        </form>
        <a class="menu-item" href="export_rate_list.php">Export</a>
        <a class="menu-item danger" href="feed_ratelist.php?delete_all=1" onclick="return confirm('Delete entire rate list?')">Clear List</a>
      </div>
    </div>
  </div>
</div>

<div class="main">
  <?php if($msg !== ""): ?>
    <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>
  <?php if($err !== ""): ?>
    <div class="alert error"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <!-- COLUMN SETTINGS -->
  <div class="panel">
    <div class="panel-head" id="col_head">
      <span class="panel-title">Column Settings</span>
      <span class="panel-toggle" id="col_toggle">+</span>
    </div>
    <div class="panel-body" id="col_body">
      <form method="post">
        <div class="add-col-row">
          <input type="text" name="new_column_label" placeholder="New column name...">
          <button class="nav-btn primary" type="submit" name="add_column">Add Column</button>
        </div>
      </form>
      <form method="post">
        <div class="col-grid">
          <?php foreach($allColumns as $c):
            if((int)$c['is_deleted'] === 1) continue;
          ?>
          <div class="col-item">
            <div class="col-item-key"><?php echo htmlspecialchars($c['column_key']); ?></div>
            <input type="text" name="label_<?php echo htmlspecialchars($c['column_key']); ?>" value="<?php echo htmlspecialchars($c['column_label']); ?>">
            <div class="col-item-footer">
              <label class="check-label">
                <input type="checkbox" name="hide_<?php echo htmlspecialchars($c['column_key']); ?>" <?php echo (int)$c['is_hidden'] === 1 ? 'checked' : ''; ?>>
                Hide column
              </label>
              <?php if((int)$c['is_base'] === 0): ?>
                <button class="btn-del-col" type="submit" name="delete_column" value="1"
                  onclick="document.getElementById('delete_col_key').value='<?php echo htmlspecialchars($c['column_key']); ?>'; return confirm('Delete this column?')">
                  Delete
                </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="delete_col_key" name="column_key" value="">
        <button class="nav-btn primary" type="submit" name="save_columns">Save Column Settings</button>
      </form>
    </div>
  </div>

  <!-- ADD ROW -->
  <div class="panel">
    <div class="panel-head" id="add_head">
      <span class="panel-title">Add New Row</span>
      <span class="panel-toggle <?php echo $openAddRow ? 'open' : ''; ?>" id="add_toggle">+</span>
    </div>
    <div class="panel-body <?php echo $openAddRow ? 'open' : ''; ?>" id="add_body">
      <form method="post" style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <?php foreach($visibleColumns as $c): ?>
                <th><?php echo htmlspecialchars($c['column_label']); ?></th>
              <?php endforeach; ?>
              <th class="th-action">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <?php foreach($visibleColumns as $c): ?>
                <td><input class="edit-input" type="text" name="add_col_<?php echo htmlspecialchars($c['column_key']); ?>" value=""></td>
              <?php endforeach; ?>
              <td class="td-action">
                <button class="act-btn act-save" type="submit" name="add_rate">Add Row</button>
              </td>
            </tr>
          </tbody>
        </table>
      </form>
    </div>
  </div>

  <!-- DATA TABLE -->
  <div class="table-wrap">
    <div class="tbl-header">
      <span class="tbl-title">Rate Records</span>
    </div>
    <table>
      <thead>
        <tr>
          <?php foreach($visibleColumns as $c): ?>
            <th><?php echo htmlspecialchars($c['column_label']); ?></th>
          <?php endforeach; ?>
          <th>Added</th>
          <th class="th-action">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while($r = $rows->fetch_assoc()):
          $extra = [];
          if(isset($r['extra_data']) && $r['extra_data'] !== ''){
            $decoded = json_decode($r['extra_data'], true);
            if(is_array($decoded)) $extra = $decoded;
          }
        ?>
        <?php if($editingId === (int)$r['id']): ?>
          <tr class="edit-row">
            <form method="post">
              <?php foreach($visibleColumns as $c):
                $key = $c['column_key'];
                $val = array_key_exists($key, $r) ? (string)$r[$key] : ($extra[$key] ?? '');
              ?>
                <td><input class="edit-input" type="text" name="col_<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"></td>
              <?php endforeach; ?>
              <td class="td-date"><?php echo htmlspecialchars($r['created_at']); ?></td>
              <td class="td-action">
                <input type="hidden" name="edit_id" value="<?php echo (int)$r['id']; ?>">
                <?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''): ?>
                  <a class="act-btn act-view" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="View Source">&#128065;</a>
                <?php endif; ?>
                <button class="act-btn act-save" type="submit" name="update_rate">Save</button>
                <a class="act-btn act-cancel" href="feed_ratelist.php">✕</a>
              </td>
            </form>
          </tr>
        <?php else: ?>
          <tr>
            <?php foreach($visibleColumns as $c):
              $key = $c['column_key'];
              $val = array_key_exists($key, $r) ? (string)$r[$key] : ($extra[$key] ?? '');
            ?>
              <td><?php echo htmlspecialchars($val); ?></td>
            <?php endforeach; ?>
            <td class="td-date"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td class="td-action">
              <?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''): ?>
                <a class="act-btn act-view" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="View Source">&#128065;</a>
              <?php endif; ?>
              <a class="act-btn act-edit" href="feed_ratelist.php?edit_id=<?php echo (int)$r['id']; ?>" title="Edit">&#9998;</a>
            </td>
          </tr>
        <?php endif; ?>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  function initToggle(headId, toggleId, bodyId){
    var head = document.getElementById(headId);
    var toggle = document.getElementById(toggleId);
    var body = document.getElementById(bodyId);
    if(!head || !toggle || !body) return;
    head.addEventListener('click', function(){
      var isOpen = body.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
    });
  }
  initToggle('col_head', 'col_toggle', 'col_body');
  initToggle('add_head', 'add_toggle', 'add_body');

  var menuToggle = document.getElementById('menu_toggle');
  var menuDropdown = document.getElementById('menu_dropdown');
  var importBtn = document.getElementById('menu_import_btn');
  var importFile = document.getElementById('menu_import_file');
  var importForm = document.getElementById('menu_import_form');

  if(menuToggle && menuDropdown){
    menuToggle.addEventListener('click', function(e){
      e.stopPropagation();
      var isOpen = menuDropdown.classList.toggle('open');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function(e){
      if(!menuDropdown.contains(e.target) && e.target !== menuToggle){
        menuDropdown.classList.remove('open');
        menuToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if(importBtn && importFile && importForm){
    importBtn.addEventListener('click', function(){ importFile.click(); });
    importFile.addEventListener('change', function(){
      if(importFile.files && importFile.files.length > 0) importForm.submit();
    });
  }
})();
</script>
</body>
</html>
