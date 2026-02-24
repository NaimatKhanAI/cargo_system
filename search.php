<?php
$today = date('Y-m-d');
$d1Val = isset($_POST['d1']) && $_POST['d1'] !== '' ? $_POST['d1'] : $today;
$d2Val = isset($_POST['d2']) && $_POST['d2'] !== '' ? $_POST['d2'] : $today;
?>

<h2>Search by date</h2>
<form method="post">
<input type="date" name="d1" value="<?php echo htmlspecialchars($d1Val); ?>">
<input type="date" name="d2" value="<?php echo htmlspecialchars($d2Val); ?>">
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
