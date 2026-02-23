<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$total = $conn->query("SELECT SUM(profit) as t FROM bilty")->fetch_assoc();
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
</style>

</head>
<body>

<div class="topbar">
<h2>Cargo Management Dashboard</h2>
<div>
<a class="btn" href="add_bilty.php">+ Add Bilty</a>
<a class="btn" href="search.php">Search</a>
<a class="btn" href="account.php">Account Ledger</a>
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

<div class="profit-box">
Total Profit: Rs <?php echo $total_profit; ?>
</div>

<table>
<tr>
<th>Date</th>
<th>Vehicle</th>
<th>Bilty</th>
<th>Location</th>
<th>Freight</th>
<th>Tender</th>
<th>Profit</th>
<th>Action</th>
</tr>

<?php
$result = $conn->query("SELECT * FROM bilty ORDER BY id DESC");

while($row = $result->fetch_assoc()){
echo "<tr>
<td>{$row['date']}</td>
<td>{$row['vehicle']}</td>
<td>{$row['bilty_no']}</td>
<td>{$row['location']}</td>
<td>Rs {$row['freight']}</td>
<td>Rs {$row['tender']}</td>
<td><b>Rs {$row['profit']}</b></td>
<td>
<a class='btn' href='edit.php?id={$row['id']}'>Edit</a>
<a class='btn' href='delete.php?id={$row['id']}' onclick=\"return confirm('Delete this record?')\">Delete</a>
<a class='btn' href='pdf.php?id={$row['id']}' target='_blank'>PDF</a>
</td>
</tr>";
}
?>

</table>

</body>
</html>
