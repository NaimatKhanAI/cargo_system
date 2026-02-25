<?php
include 'config/db.php';
$id=$_GET['id'];
$conn->query("DELETE FROM bilty WHERE id=$id");
header("location:feed.php");
?>
