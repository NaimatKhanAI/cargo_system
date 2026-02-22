<h2>Search by date</h2>
<form method="post">
<input type="date" name="d1">
<input type="date" name="d2">
<button>Search</button>
</form>

<?php
include 'config/db.php';
if(isset($_POST['d1'])){
$d1=$_POST['d1'];
$d2=$_POST['d2'];

$r=$conn->query("SELECT * FROM bilty WHERE date BETWEEN '$d1' AND '$d2'");
while($row=$r->fetch_assoc()){
echo $row['date']." | ".$row['vehicle']." | Profit: ".$row['profit']."<br>";
}
}
?>
<a href="dashboard.php">Back</a>
