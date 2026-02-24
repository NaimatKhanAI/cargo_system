<?php
include 'config/db.php';

$sr=isset($_POST['sr_no']) ? trim($_POST['sr_no']) : '';
$d=$_POST['date'];
$v=$_POST['vehicle'];
$b=$_POST['bilty'];
$party=$_POST['party'];
$l=$_POST['location'];
$f=$_POST['freight'];
$t=$_POST['tender'];

$p=$t-$f;

$conn->query("INSERT INTO bilty(sr_no,date,vehicle,bilty_no,party,location,freight,original_freight,tender,profit)
VALUES('$sr','$d','$v','$b','$party','$l','$f','$f','$t','$p')");

header("location:dashboard.php");
?>
