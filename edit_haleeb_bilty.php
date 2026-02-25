<?php
include 'config/db.php';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
header("location:haleeb.php");
exit();
}

if(isset($_POST['update'])){
$d = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
$v = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
$vt = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : '';
$dn = isset($_POST['delivery_note']) ? trim($_POST['delivery_note']) : '';
$tn = isset($_POST['token_no']) ? trim($_POST['token_no']) : '';
$party = isset($_POST['party']) ? trim($_POST['party']) : '';
$l = isset($_POST['location']) ? trim($_POST['location']) : '';
$t = isset($_POST['tender']) ? (int)$_POST['tender'] : 0;
$f = isset($_POST['freight']) ? (int)$_POST['freight'] : 0;
$p = $t - $f;

$stmt = $conn->prepare("UPDATE haleeb_bilty SET date=?, vehicle=?, vehicle_type=?, delivery_note=?, token_no=?, party=?, location=?, freight=?, tender=?, profit=? WHERE id=?");
$stmt->bind_param("sssssssiiii", $d, $v, $vt, $dn, $tn, $party, $l, $f, $t, $p, $id);
$stmt->execute();
$stmt->close();

header("location:haleeb.php");
exit();
}

$stmt = $conn->prepare("SELECT * FROM haleeb_bilty WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$row){
header("location:haleeb.php");
exit();
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Haleeb Bilty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.page-wrap{
max-width:900px;
margin:25px auto;
}
.form-card{
background:#fff;
border:1px solid #ddd;
border-radius:10px;
padding:20px;
box-shadow:0 10px 24px rgba(0,0,0,0.06);
}
.form-head{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:15px;
}
.back-link{
display:inline-block;
padding:10px 14px;
background:#ececec;
color:#111;
text-decoration:none;
border-radius:8px;
font-weight:600;
}
.grid{
display:grid;
grid-template-columns:repeat(2, minmax(220px, 1fr));
gap:12px 16px;
}
.field label{
display:block;
margin:0 0 6px;
font-size:14px;
color:#333;
font-weight:600;
}
.field input{
max-width:none;
margin:0;
border:1px solid #cfcfcf;
border-radius:8px;
background:#fafafa;
}
.actions{
margin-top:16px;
display:flex;
justify-content:flex-end;
gap:10px;
}
.actions button{
max-width:180px;
border-radius:8px;
font-weight:700;
}
.delete-link{
display:inline-flex;
align-items:center;
justify-content:center;
padding:12px 16px;
background:#c62828;
color:#fff;
text-decoration:none;
border-radius:8px;
font-weight:700;
}
@media(max-width:700px){
.grid{
grid-template-columns:1fr;
}
.form-head{
align-items:flex-start;
flex-direction:column;
}
.actions{
justify-content:stretch;
}
.actions button{
max-width:none;
}
}
</style>
</head>
<body>
<div class="page-wrap">
<div class="form-card">
<div class="form-head">
<h2>Edit Haleeb Bilty</h2>
<a class="back-link" href="haleeb.php">Back to Haleeb Feed</a>
</div>

<form method="post">
<div class="grid">
<div class="field">
<label for="date">Date</label>
<input id="date" type="date" name="date" value="<?=htmlspecialchars($row['date'])?>" required>
</div>
<div class="field">
<label for="vehicle">Vehicle</label>
<input id="vehicle" name="vehicle" value="<?=htmlspecialchars($row['vehicle'])?>" required>
</div>
<div class="field">
<label for="vehicle_type">Vehicle Type</label>
<input id="vehicle_type" name="vehicle_type" value="<?=htmlspecialchars($row['vehicle_type'])?>" required>
</div>
<div class="field">
<label for="delivery_note">Delivery Note</label>
<input id="delivery_note" name="delivery_note" value="<?=htmlspecialchars($row['delivery_note'])?>" required>
</div>
<div class="field">
<label for="token_no">Token No</label>
<input id="token_no" name="token_no" value="<?=htmlspecialchars($row['token_no'])?>" required>
</div>
<div class="field">
<label for="party">Party</label>
<input id="party" name="party" value="<?=htmlspecialchars($row['party'])?>">
</div>
<div class="field">
<label for="location">Location</label>
<input id="location" name="location" value="<?=htmlspecialchars($row['location'])?>" required>
</div>
<div class="field">
<label for="tender">Tender</label>
<input id="tender" type="number" name="tender" value="<?=htmlspecialchars($row['tender'])?>" min="0" required>
</div>
<div class="field">
<label for="freight">Freight</label>
<input id="freight" type="number" name="freight" value="<?=htmlspecialchars($row['freight'])?>" min="0" required>
</div>
</div>

<div class="actions">
<a class="delete-link" href="delete_haleeb_bilty.php?id=<?=$id?>" onclick="return confirm('Delete this record?')">Delete</a>
<button type="submit" name="update">Update Bilty</button>
</div>
</form>
</div>
</div>
</body>
</html>
