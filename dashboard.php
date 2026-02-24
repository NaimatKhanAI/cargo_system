<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$total = $conn->query("SELECT SUM(tender - freight) as t FROM bilty")->fetch_assoc();
$total_profit = $total['t'] ? $total['t'] : 0;

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
?>

<!DOCTYPE html>

<html>
<head>
<title>Cargo Dashboard</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="assets/style.css">

<style>
.topbar{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:20px;
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
display:inline-block;
margin:5px;
}

.import-wrap input[type="file"]{
max-width:220px;
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
</style>

</head>
<body>

<div class="topbar">
<h2>Cargo Management Dashboard</h2>
<div>
<a class="btn" href="add_bilty.php">+ Add Bilty</a>
<a class="btn" href="search.php">Search</a>
<a class="btn" href="account.php">Account Ledger</a>
<a class="btn" href="process_img.php">Process Image</a>
<a class="btn" href="rate_list.php">Rate List</a>
<a class="btn" href="export_bilty.php">Export CSV</a>
<form class="import-wrap" action="import_bilty.php" method="post" enctype="multipart/form-data">
<input type="file" name="csv_file" accept=".csv" required>
<button class="btn" type="submit">Import CSV</button>
</form>
<a class="btn" href="logout.php">Logout</a>
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
$result = $conn->query("SELECT *, (tender - freight) AS calc_profit FROM bilty ORDER BY id DESC");

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

</body>
</html>
