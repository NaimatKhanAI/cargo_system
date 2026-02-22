<?php
include 'config/db.php';
$id=$_GET['id'];

if(isset($_POST['update'])){
$d=$_POST['date'];
$v=$_POST['vehicle'];
$b=$_POST['bilty'];
$l=$_POST['location'];
$f=$_POST['freight'];
$t=$_POST['tender'];
$p=$f-$t;

$conn->query("UPDATE bilty SET date='$d',vehicle='$v',bilty_no='$b',
location='$l',freight='$f',tender='$t',profit='$p' WHERE id=$id");
header("location:dashboard.php");
}

$row=$conn->query("SELECT * FROM bilty WHERE id=$id")->fetch_assoc();
?>

<form method="post">
<input type="date" name="date" value="<?=$row['date']?>"><br>
<input name="vehicle" value="<?=$row['vehicle']?>"><br>
<input name="bilty" value="<?=$row['bilty_no']?>"><br>
<input name="location" value="<?=$row['location']?>"><br>
<input name="freight" value="<?=$row['freight']?>"><br>
<input name="tender" value="<?=$row['tender']?>"><br>
<button name="update">Update</button>
</form>
