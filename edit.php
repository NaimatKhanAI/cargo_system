<?php
include 'config/db.php';
$id=$_GET['id'];

if(isset($_POST['update'])){
$sr=isset($_POST['sr_no']) ? trim($_POST['sr_no']) : '';
$d=$_POST['date'];
$v=$_POST['vehicle'];
$b=$_POST['bilty'];
$party=$_POST['party'];
$l=$_POST['location'];
$f=$_POST['freight'];
$t=$_POST['tender'];
$p=$t-$f;

$conn->query("UPDATE bilty SET sr_no='$sr',date='$d',vehicle='$v',bilty_no='$b',
party='$party',location='$l',freight='$f',original_freight='$f',tender='$t',profit='$p' WHERE id=$id");
header("location:feed.php");
}

$row=$conn->query("SELECT * FROM bilty WHERE id=$id")->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Bilty</title>
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
<h2>Edit Bilty</h2>
<a class="back-link" href="feed.php">Back to Feed</a>
</div>

<form method="post">
<div class="grid">
<div class="field">
<label for="sr_no">SR No</label>
<input id="sr_no" name="sr_no" value="<?=htmlspecialchars($row['sr_no'] ?? '')?>" required>
</div>
<div class="field">
<label for="date">Date</label>
<input id="date" type="date" name="date" value="<?=htmlspecialchars($row['date'] ? $row['date'] : date('Y-m-d'))?>" required>
</div>
<div class="field">
<label for="vehicle">Vehicle</label>
<input id="vehicle" name="vehicle" value="<?=htmlspecialchars($row['vehicle'])?>" required>
</div>
<div class="field">
<label for="bilty">Bilty No</label>
<input id="bilty" name="bilty" value="<?=htmlspecialchars($row['bilty_no'])?>" required>
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
<label for="freight">Freight</label>
<input id="freight" type="number" name="freight" value="<?=htmlspecialchars($row['freight'])?>" min="0" required>
</div>
<div class="field">
<label for="tender">Tender</label>
<input id="tender" type="number" name="tender" value="<?=htmlspecialchars($row['tender'])?>" min="0" required>
</div>
</div>

<div class="actions">
<a class="delete-link" href="delete.php?id=<?=$id?>" onclick="return confirm('Delete this record?')">Delete</a>
<button type="submit" name="update">Update Bilty</button>
</div>
</form>
</div>
</div>
</body>
</html>


