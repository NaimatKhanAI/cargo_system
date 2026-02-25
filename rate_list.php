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
if($key === ''){
$key = 'column';
}
if(strpos($key, 'custom_') !== 0){
$key = 'custom_' . $key;
}
return $key;
}

function load_columns($conn){
$cols = [];
$res = $conn->query("SELECT id, column_key, column_label, is_hidden, is_deleted, display_order, is_base FROM rate_list_columns ORDER BY display_order ASC, id ASC");
while($r = $res->fetch_assoc()){
$cols[] = $r;
}
return $cols;
}

$msg = '';
$err = '';
$editingId = 0;

if(isset($_GET['delete_all']) && $_GET['delete_all'] === '1'){
$conn->query("DELETE FROM image_processed_rates");
$msg = 'Rate list cleared.';
}

if(isset($_GET['import'])){
if($_GET['import'] === 'success'){
$ins = isset($_GET['ins']) ? (int)$_GET['ins'] : 0;
$skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;
$msg = "Import completed. Inserted: $ins, Skipped: $skip";
} elseif($_GET['import'] === 'error'){
$reason = isset($_GET['reason']) ? trim((string)$_GET['reason']) : '';
if($reason === 'no_columns'){
$err = 'Import failed. No active Rate List columns found. Add columns first, then import.';
} else {
$err = 'Import failed. Please upload a valid CSV file.';
}
}
}

if(isset($_POST['add_column'])){
$label = isset($_POST['new_column_label']) ? trim($_POST['new_column_label']) : '';
if($label === ''){
$err = 'Column name required.';
} else {
$baseKey = slugify_label_to_key($label);
$key = $baseKey;
$suffix = 1;
while(true){
$chk = $conn->prepare("SELECT id FROM rate_list_columns WHERE column_key=? LIMIT 1");
$chk->bind_param("s", $key);
$chk->execute();
$exists = $chk->get_result()->num_rows > 0;
$chk->close();
if(!$exists){ break; }
$suffix++;
$key = $baseKey . '_' . $suffix;
}

$maxOrderRes = $conn->query("SELECT COALESCE(MAX(display_order),0) AS m FROM rate_list_columns")->fetch_assoc();
$nextOrder = ((int)$maxOrderRes['m']) + 1;
$ins = $conn->prepare("INSERT INTO rate_list_columns(column_key, column_label, is_hidden, is_deleted, display_order, is_base) VALUES(?, ?, 0, 0, ?, 0)");
$ins->bind_param("ssi", $key, $label, $nextOrder);
$ins->execute();
$ins->close();
$msg = 'New column added.';
}
}

if(isset($_POST['save_columns'])){
$cols = load_columns($conn);
foreach($cols as $c){
$key = $c['column_key'];
$labelField = 'label_' . $key;
$hideField = 'hide_' . $key;
$newLabel = isset($_POST[$labelField]) ? trim($_POST[$labelField]) : $c['column_label'];
$isHidden = isset($_POST[$hideField]) ? 1 : 0;
if($newLabel === ''){
$newLabel = $c['column_label'];
}
$upd = $conn->prepare("UPDATE rate_list_columns SET column_label=?, is_hidden=? WHERE column_key=?");
$upd->bind_param("sis", $newLabel, $isHidden, $key);
$upd->execute();
$upd->close();
}
$msg = 'Column settings updated.';
}

if(isset($_POST['delete_column'])){
$key = isset($_POST['column_key']) ? trim($_POST['column_key']) : '';
if($key !== ''){
$upd = $conn->prepare("UPDATE rate_list_columns SET is_deleted=1 WHERE column_key=?");
$upd->bind_param("s", $key);
$upd->execute();
$upd->close();
$msg = 'Column deleted.';
}
}

if(isset($_POST['update_rate'])){
$editingId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
if($editingId <= 0){
$err = 'Invalid row selected.';
} else {
$allCols = load_columns($conn);
$activeCols = array_values(array_filter($allCols, function($c){
return (int)$c['is_deleted'] === 0;
}));

$base = [
'sr_no' => '',
'station_english' => '',
'station_urdu' => '',
'rate1' => '',
'rate2' => '',
];
$extra = [];

foreach($activeCols as $c){
$key = $c['column_key'];
$field = 'col_' . $key;
$val = isset($_POST[$field]) ? trim($_POST[$field]) : '';
if(array_key_exists($key, $base)){
$base[$key] = $val;
} else {
$extra[$key] = $val;
}
}

$extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
$upd = $conn->prepare("UPDATE image_processed_rates SET sr_no=?, station_english=?, station_urdu=?, rate1=?, rate2=?, extra_data=? WHERE id=?");
$upd->bind_param("ssssssi", $base['sr_no'], $base['station_english'], $base['station_urdu'], $base['rate1'], $base['rate2'], $extraJson, $editingId);
$upd->execute();
$upd->close();
$msg = 'Rate row updated.';
$editingId = 0;
}
}

if(isset($_GET['edit_id']) && !isset($_POST['update_rate'])){
$editingId = (int)$_GET['edit_id'];
}

$allColumns = load_columns($conn);
$displayColumns = array_values(array_filter($allColumns, function($c){
return (int)$c['is_deleted'] === 0;
}));
$visibleColumns = array_values(array_filter($displayColumns, function($c){
return (int)$c['is_hidden'] === 0;
}));

$rows = $conn->query("SELECT id, source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, extra_data, created_at FROM image_processed_rates ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Rate List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.topbar{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:15px;
}
.btn{
padding:10px 14px;
background:black;
color:white;
text-decoration:none;
border:none;
cursor:pointer;
display:inline-block;
margin:4px 4px 4px 0;
}
.panel{
background:#fff;
border:1px solid #ddd;
padding:12px;
margin-bottom:10px;
}
.muted{
color:#666;
font-size:13px;
}
.icon-link{
display:inline-flex;
width:28px;
height:28px;
align-items:center;
justify-content:center;
background:#1565c0;
color:#fff;
text-decoration:none;
border-radius:6px;
font-size:14px;
}
.icon-edit{
display:inline-flex;
width:28px;
height:28px;
align-items:center;
justify-content:center;
background:#111;
color:#fff;
text-decoration:none;
border-radius:6px;
font-size:14px;
margin-left:4px;
}
.edit-row input{
width:100%;
max-width:none;
margin:0;
padding:8px;
border:1px solid #ccc;
border-radius:6px;
}
.save-btn{
padding:8px 10px;
background:#2e7d32;
color:#fff;
border:none;
border-radius:6px;
cursor:pointer;
}
.cancel-btn{
padding:8px 10px;
background:#eee;
color:#111;
text-decoration:none;
border-radius:6px;
display:inline-block;
}
.column-grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
gap:8px 12px;
}
.col-item{
border:1px solid #e1e1e1;
padding:8px;
border-radius:8px;
background:#fafafa;
}
.col-item label{
display:block;
font-size:12px;
color:#555;
margin-bottom:4px;
}
.col-item input[type="text"]{
width:100%;
max-width:none;
margin:0 0 6px 0;
padding:8px;
border:1px solid #ccc;
border-radius:6px;
}
.danger-btn{
padding:8px 10px;
background:#c62828;
color:#fff;
border:none;
border-radius:6px;
cursor:pointer;
}
.panel-head{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
}
.toggle-btn{
padding:8px 12px;
background:#111;
color:#fff;
border:none;
border-radius:6px;
cursor:pointer;
}
.settings-body{
display:none;
margin-top:10px;
}
.settings-body.open{
display:block;
}
</style>
</head>
<body>
<div class="topbar">
<h2>Rate List</h2>
<div>
<a class="btn" href="process_img.php">Process Image</a>
<a class="btn" href="export_rate_list.php">Export List</a>
<form action="import_rate_list.php" method="post" enctype="multipart/form-data" style="display:inline-block;">
<input type="file" name="csv_file" accept=".csv" required style="max-width:180px;">
<button class="btn" type="submit">Import List</button>
</form>
<a class="btn" href="rate_list.php?delete_all=1" onclick="return confirm('Delete complete rate list?')">Delete List</a>
<a class="btn" href="feed.php">Feed</a>
</div>
</div>

<div class="panel">
<div class="muted">Processed image rows saved in database.</div>
<?php if($msg!=""){ ?><div style="color:green;margin-top:8px;"><?php echo htmlspecialchars($msg); ?></div><?php } ?>
<?php if($err!=""){ ?><div style="color:#b71c1c;margin-top:8px;"><?php echo htmlspecialchars($err); ?></div><?php } ?>
</div>

<div class="panel">
<div class="panel-head">
<h3 style="margin:0;">Column Settings</h3>
<button class="toggle-btn" type="button" id="toggle_column_settings" aria-expanded="false" aria-controls="column_settings_body">Open</button>
</div>
<div id="column_settings_body" class="settings-body">
<form method="post" style="margin-bottom:10px;">
<input type="text" name="new_column_label" placeholder="New column name" style="max-width:260px;">
<button class="btn" type="submit" name="add_column">Add Column</button>
</form>

<form method="post">
<div class="column-grid">
<?php foreach($allColumns as $c){ if((int)$c['is_deleted'] === 1){ continue; } ?>
<div class="col-item">
<label><?php echo htmlspecialchars($c['column_key']); ?></label>
<input type="text" name="label_<?php echo htmlspecialchars($c['column_key']); ?>" value="<?php echo htmlspecialchars($c['column_label']); ?>">
<label><input type="checkbox" name="hide_<?php echo htmlspecialchars($c['column_key']); ?>" <?php echo (int)$c['is_hidden'] === 1 ? 'checked' : ''; ?>> Hide</label>
<?php if((int)$c['is_base'] === 0){ ?>
<button class="danger-btn" type="submit" name="delete_column" value="1" onclick="document.getElementById('delete_col_key').value='<?php echo htmlspecialchars($c['column_key']); ?>'; return confirm('Delete this column?')">Delete Column</button>
<?php } ?>
</div>
<?php } ?>
</div>
<input type="hidden" id="delete_col_key" name="column_key" value="">
<button class="btn" type="submit" name="save_columns">Save Column Settings</button>
</form>
</div>
</div>

<table>
<tr>
<?php foreach($visibleColumns as $c){ ?>
<th><?php echo htmlspecialchars($c['column_label']); ?></th>
<?php } ?>
<th>Created</th>
<th>Action</th>
</tr>
<?php while($r = $rows->fetch_assoc()){ ?>
<?php
$extra = [];
if(isset($r['extra_data']) && $r['extra_data'] !== ''){
$decoded = json_decode($r['extra_data'], true);
if(is_array($decoded)){ $extra = $decoded; }
}
?>
<?php if($editingId === (int)$r['id']){ ?>
<tr class="edit-row">
<form method="post">
<?php foreach($visibleColumns as $c){
$key = $c['column_key'];
$val = '';
if(array_key_exists($key, $r)){ $val = (string)$r[$key]; }
elseif(isset($extra[$key])){ $val = (string)$extra[$key]; }
?>
<td><input type="text" name="col_<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"></td>
<?php } ?>
<td><?php echo htmlspecialchars($r['created_at']); ?></td>
<td>
<?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''){ ?>
<a class="icon-link" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="<?php echo htmlspecialchars($r['source_file']); ?>" aria-label="View Source Image">&#128065;</a>
<?php } ?>
<input type="hidden" name="edit_id" value="<?php echo (int)$r['id']; ?>">
<button class="save-btn" type="submit" name="update_rate">Save</button>
<a class="cancel-btn" href="rate_list.php">Cancel</a>
</td>
</form>
</tr>
<?php } else { ?>
<tr>
<?php foreach($visibleColumns as $c){
$key = $c['column_key'];
$val = '';
if(array_key_exists($key, $r)){ $val = (string)$r[$key]; }
elseif(isset($extra[$key])){ $val = (string)$extra[$key]; }
?>
<td><?php echo htmlspecialchars($val); ?></td>
<?php } ?>
<td><?php echo htmlspecialchars($r['created_at']); ?></td>
<td>
<?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''){ ?>
<a class="icon-link" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="<?php echo htmlspecialchars($r['source_file']); ?>" aria-label="View Source Image">&#128065;</a>
<?php } ?>
<a class="icon-edit" href="rate_list.php?edit_id=<?php echo (int)$r['id']; ?>" title="Edit" aria-label="Edit">&#9998;</a>
</td>
</tr>
<?php } ?>
<?php } ?>
</table>
<script>
(function(){
var btn = document.getElementById('toggle_column_settings');
var body = document.getElementById('column_settings_body');
if(!btn || !body){ return; }
btn.addEventListener('click', function(){
var isOpen = body.classList.toggle('open');
btn.textContent = isOpen ? 'Close' : 'Open';
btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});
})();
</script>
</body>
</html>

