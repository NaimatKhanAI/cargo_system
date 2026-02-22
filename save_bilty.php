<?php
include 'config/db.php';

$d=$_POST['date'];
$v=$_POST['vehicle'];
$b=$_POST['bilty'];
$l=$_POST['location'];
$f=$_POST['freight'];
$t=$_POST['tender'];

$p=$f-$t;

$conn->query("INSERT INTO bilty(date,vehicle,bilty_no,location,freight,tender,profit)
VALUES('$d','$v','$b','$l','$f','$t','$p')");

header("location:dashboard.php");
?>