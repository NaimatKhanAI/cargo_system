<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$total = $conn->query("SELECT SUM(tender - freight) as t FROM bilty")->fetch_assoc();
$total_profit = $total['t'] ? $total['t'] : 0;
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
if ($_GET['pay'] === 'success') {
$pay_message = "Payment posted successfully.";
} elseif ($_GET['pay'] === 'error') {
$pay_message = "Payment failed. Please try again.";
}
}

$vehicleOptions = [];
$vehicleRes = $conn->query("SELECT DISTINCT vehicle FROM bilty WHERE vehicle IS NOT NULL AND vehicle <> '' ORDER BY vehicle ASC");
while($vehicleRes && $vrow = $vehicleRes->fetch_assoc()){
$vehicleOptions[] = (string)$vrow['vehicle'];
}

$where = [];
$bindValues = [];
$bindTypes = "";

if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){
$where[] = "date >= ?";
$bindTypes .= "s";
$bindValues[] = $dateFrom;
}
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){
$where[] = "date <= ?";
$bindTypes .= "s";
$bindValues[] = $dateTo;
}
if($vehicleSearch !== ''){
$where[] = "vehicle LIKE ?";
$bindTypes .= "s";
$bindValues[] = "%" . $vehicleSearch . "%";
}

$sql = "SELECT *, (tender - freight) AS calc_profit FROM bilty";
if(count($where) > 0){
$sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC";

if(count($bindValues) > 0){
$stmt = $conn->prepare($sql);
if(count($bindValues) === 1){
$stmt->bind_param($bindTypes, $bindValues[0]);
} elseif(count($bindValues) === 2){
$stmt->bind_param($bindTypes, $bindValues[0], $bindValues[1]);
} else {
$stmt->bind_param($bindTypes, $bindValues[0], $bindValues[1], $bindValues[2]);
}
$stmt->execute();
$result = $stmt->get_result();
} else {
$result = $conn->query($sql);
}
?>

<!DOCTYPE html>

<html>
<head>
<title>Cargo Feed</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="assets/style.css">

<style>
.topbar{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:20px;
}
.top-actions{
display:flex;
align-items:center;
gap:4px;
position:relative;
}

.btn{
padding:10px 15px;
background:black;
color:white;
text-decoration:none;
margin:5px;
display:inline-block;
border:none;
cursor:pointer;
}

.profit-box{
background:#000;
color:#fff;
padding:15px;
margin:15px 0;
font-size:20px;
}

.import-wrap{
display:block;
margin:8px 0 0;
}

.import-wrap input[type="file"]{
max-width:220px;
}
.menu-btn{
width:40px;
height:40px;
border:none;
border-radius:8px;
background:#111;
color:#fff;
cursor:pointer;
font-size:18px;
}
.menu-pop{
display:none;
position:absolute;
right:0;
top:52px;
background:#fff;
border:1px solid #ddd;
border-radius:10px;
padding:10px;
min-width:220px;
box-shadow:0 10px 24px rgba(0,0,0,0.12);
z-index:50;
}
.menu-pop.open{
display:block;
}
.menu-pop .btn{
display:block;
margin:0;
text-align:center;
}

.status-msg{
background:#eaf7ea;
border:1px solid #8bc48b;
padding:10px;
margin:10px 0;
}
.icon-btn{
width:28px;
height:28px;
display:inline-flex;
align-items:center;
justify-content:center;
padding:0;
font-size:14px;
line-height:1;
margin:0 3px 0 0;
text-decoration:none;
}
.icon-edit{
background:#000;
}
.icon-pdf{
background:#1565c0;
}
.icon-pay{
background:#2e7d32;
}
.col-action{
width:100px;
white-space:nowrap;
text-align:center;
}
.search-panel{
background:#fff;
border:1px solid #ddd;
padding:12px;
margin:10px 0;
}
.search-form{
display:grid;
grid-template-columns:repeat(4,minmax(150px,1fr));
gap:8px;
align-items:end;
}
.search-form label{
display:block;
font-size:12px;
color:#444;
margin-bottom:4px;
}
.search-form input{
max-width:none;
margin:0;
border:1px solid #ccc;
border-radius:8px;
background:#fafafa;
}
.search-actions{
display:flex;
gap:8px;
}
.btn-light{
padding:10px 15px;
background:#ececec;
color:#111;
text-decoration:none;
border:none;
cursor:pointer;
border-radius:8px;
display:inline-block;
}
@media(max-width:900px){
.search-form{
grid-template-columns:1fr 1fr;
}
}
@media(max-width:560px){
.search-form{
grid-template-columns:1fr;
}
}
</style>

</head>
<body>

<div class="topbar">
<h2>Cargo Management Feed</h2>
<div class="top-actions">
<a class="btn" href="add_bilty.php">+ Add Bilty</a>
<a class="btn" href="haleeb.php">Haleeb</a>
<a class="btn" href="search.php">Search</a>
<a class="btn" href="dashboard.php">Dashboard</a>
<button class="menu-btn" id="feed_menu_btn" type="button" aria-label="Menu" title="Menu">&#9776;</button>
<div class="menu-pop" id="feed_menu_pop">
<a class="btn" href="rate_list.php">Rate List</a>
<a class="btn" href="export_bilty.php">Export CSV</a>
<form class="import-wrap" action="import_bilty.php" method="post" enctype="multipart/form-data">
<input type="file" name="csv_file" accept=".csv" required>
<button class="btn" type="submit">Import CSV</button>
</form>
</div>
</div>
</div>

<?php if($import_message!=""){ ?>
<div class="status-msg"><?php echo htmlspecialchars($import_message); ?></div>
<?php } ?>
<?php if($pay_message!=""){ ?>
<div class="status-msg"><?php echo htmlspecialchars($pay_message); ?></div>
<?php } ?>

<div class="profit-box">
Total Profit: Rs <?php echo $total_profit; ?>
</div>

<div class="search-panel">
<form class="search-form" method="get">
<div>
<label for="date_from">Date From</label>
<input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
</div>
<div>
<label for="date_to">Date To</label>
<input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
</div>
<div>
<label for="vehicle">Vehicle No</label>
<input id="vehicle" name="vehicle" list="vehicle_list" placeholder="Search vehicle" value="<?php echo htmlspecialchars($vehicleSearch); ?>">
<datalist id="vehicle_list">
<?php foreach($vehicleOptions as $opt){ ?>
<option value="<?php echo htmlspecialchars($opt); ?>">
<?php } ?>
</datalist>
</div>
<div class="search-actions">
<button class="btn" type="submit">Search</button>
<a class="btn-light" href="feed.php">Reset</a>
</div>
</form>
</div>

<table>
<tr>
<th>SR.</th>
<th>Date</th>
<th>Vehicle</th>
<th>Bilty</th>
<th>Party</th>
<th>Location</th>
<th>Freight</th>
<th>Tender</th>
<th>Profit</th>
<th class='col-action'>Action</th>
</tr>

<?php
while($row = $result->fetch_assoc()){
echo "<tr>
<td>{$row['sr_no']}</td>
<td>{$row['date']}</td>
<td>{$row['vehicle']}</td>
<td>{$row['bilty_no']}</td>
<td>{$row['party']}</td>
<td>{$row['location']}</td>
<td>Rs {$row['freight']}</td>
<td>Rs {$row['tender']}</td>
<td><b>Rs {$row['calc_profit']}</b></td>
<td class='col-action'>
<a class='btn icon-btn icon-pay' href='pay_now.php?id={$row['id']}' title='Pay Now' aria-label='Pay Now'>&#8377;</a>
<a class='btn icon-btn icon-edit' href='edit.php?id={$row['id']}' title='Edit' aria-label='Edit'>&#9998;</a>
<a class='btn icon-btn icon-pdf' href='pdf.php?id={$row['id']}' target='_blank' title='PDF' aria-label='PDF'>&#128196;</a>
</td>
</tr>";
}
?>

</table>
<script>
(function(){
var btn = document.getElementById('feed_menu_btn');
var pop = document.getElementById('feed_menu_pop');
if(!btn || !pop){ return; }
btn.addEventListener('click', function(e){
e.stopPropagation();
pop.classList.toggle('open');
});
document.addEventListener('click', function(e){
if(!pop.contains(e.target) && e.target !== btn){
pop.classList.remove('open');
}
});
})();
</script>

</body>
</html>
