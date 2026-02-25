<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$total = $conn->query("SELECT SUM(tender - freight) AS t FROM haleeb_bilty")->fetch_assoc();
$total_profit = $total && $total['t'] ? $total['t'] : 0;

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
?>
<!DOCTYPE html>
<html>
<head>
<title>Haleeb Feed</title>
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
.col-action{
width:90px;
white-space:nowrap;
text-align:center;
}
</style>
</head>
<body>
<div class="topbar">
<h2>Haleeb Management Feed</h2>
<div class="top-actions">
<a class="btn" href="add_haleeb_bilty.php">+ Add Bilty</a>
<a class="btn" href="feed.php">Feed</a>
<a class="btn" href="dashboard.php">Dashboard</a>
<button class="menu-btn" id="haleeb_menu_btn" type="button" aria-label="Menu" title="Menu">&#9776;</button>
<div class="menu-pop" id="haleeb_menu_pop">
<a class="btn" href="export_haleeb.php">Export CSV</a>
<form class="import-wrap" action="import_haleeb.php" method="post" enctype="multipart/form-data">
<input type="file" name="csv_file" accept=".csv" required>
<button class="btn" type="submit">Import CSV</button>
</form>
</div>
</div>
</div>

<?php if($import_message!=""){ ?>
<div class="status-msg"><?php echo htmlspecialchars($import_message); ?></div>
<?php } ?>

<div class="profit-box">
Total Profit: Rs <?php echo $total_profit; ?>
</div>

<table>
<tr>
<th>Date</th>
<th>Vehicle</th>
<th>Vehicle Type</th>
<th>Delivery Note</th>
<th>Token No</th>
<th>Party</th>
<th>Location</th>
<th>Tender</th>
<th>Freight</th>
<th>Profit</th>
<th class="col-action">Action</th>
</tr>
<?php
$result = $conn->query("SELECT *, (tender - freight) AS calc_profit FROM haleeb_bilty ORDER BY id DESC");
while($row = $result->fetch_assoc()){
echo "<tr>
<td>{$row['date']}</td>
<td>{$row['vehicle']}</td>
<td>{$row['vehicle_type']}</td>
<td>{$row['delivery_note']}</td>
<td>{$row['token_no']}</td>
<td>{$row['party']}</td>
<td>{$row['location']}</td>
<td>Rs {$row['tender']}</td>
<td>Rs {$row['freight']}</td>
<td><b>Rs {$row['calc_profit']}</b></td>
<td class='col-action'>
<a class='btn icon-btn icon-edit' href='edit_haleeb_bilty.php?id={$row['id']}' title='Edit' aria-label='Edit'>&#9998;</a>
<a class='btn icon-btn icon-pdf' href='haleeb_pdf.php?id={$row['id']}' target='_blank' title='PDF' aria-label='PDF'>&#128196;</a>
</td>
</tr>";
}
?>
</table>
<script>
(function(){
var btn = document.getElementById('haleeb_menu_btn');
var pop = document.getElementById('haleeb_menu_pop');
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
