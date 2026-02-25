<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$total = $conn->query("SELECT SUM(tender - freight) AS t FROM haleeb_bilty")->fetch_assoc();
$total_profit = $total && $total['t'] ? $total['t'] : 0;
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
<div>
<a class="btn" href="add_haleeb_bilty.php">+ Add Bilty</a>
<a class="btn" href="feed.php">Feed</a>
<a class="btn" href="account.php">Account Ledger</a>
<a class="btn" href="logout.php">Logout</a>
</div>
</div>

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
</body>
</html>
