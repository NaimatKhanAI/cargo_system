<?php
include 'config/db.php';

$d=$_POST['date'];
$v=$_POST['vehicle'];
$b=$_POST['bilty'];
$party=$_POST['party'];
$l=$_POST['location'];
$f=$_POST['freight'];
$t=$_POST['tender'];

$p=$t-$f;

$conn->query("INSERT INTO bilty(date,vehicle,bilty_no,party,location,freight,tender,profit)
VALUES('$d','$v','$b','$party','$l','$f','$t','$p')");

header("location:dashboard.php");
?>
