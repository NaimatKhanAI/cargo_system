<?php
session_start();
if(!isset($_SESSION['user']) && (!isset($_SESSION['login_verified']) || $_SESSION['login_verified'] !== true)){
header("location:index.php");
exit();
}

include 'config/db.php';

$allowedCategories = ['feed', 'haleeb', 'loan'];
$allowedTypes = ['debit', 'credit'];
$msg = '';
$err = '';

if(isset($_POST['add_entry'])){
$entryDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : date('Y-m-d');
$category = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : '';
$entryType = isset($_POST['entry_type']) ? strtolower(trim($_POST['entry_type'])) : '';
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';

if(!in_array($category, $allowedCategories, true)){
$err = 'Invalid category.';
} elseif(!in_array($entryType, $allowedTypes, true)){
$err = 'Invalid entry type.';
} elseif($amount <= 0){
$err = 'Amount must be greater than 0.';
} else {
$stmt = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount, note) VALUES(?, ?, ?, ?, ?)");
$stmt->bind_param("sssds", $entryDate, $category, $entryType, $amount, $note);
$stmt->execute();
$stmt->close();
$msg = 'Entry saved.';
}
}

$cat = isset($_GET['cat']) ? strtolower($_GET['cat']) : 'all';
if(!in_array($cat, array_merge(['all'], $allowedCategories), true)){
$cat = 'all';
}

$where = "";
if($cat !== 'all'){
$safeCat = $conn->real_escape_string($cat);
$where = " WHERE category='$safeCat'";
}

$totalSql = "SELECT 
SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS total_debit,
SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS total_credit
FROM account_entries";
$totals = $conn->query($totalSql)->fetch_assoc();
$totalDebit = $totals['total_debit'] ? $totals['total_debit'] : 0;
$totalCredit = $totals['total_credit'] ? $totals['total_credit'] : 0;

$categoryTotals = [];
$catTotalsSql = "SELECT category,
SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS debit_total,
SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS credit_total
FROM account_entries
GROUP BY category";
$catResult = $conn->query($catTotalsSql);
while($r = $catResult->fetch_assoc()){
$categoryTotals[$r['category']] = $r;
}

$entries = $conn->query("SELECT * FROM account_entries".$where." ORDER BY entry_date DESC, id DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Account Ledger</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.topbar{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:15px;
}
.btn{
padding:10px 14px;
background:black;
color:white;
text-decoration:none;
border:none;
cursor:pointer;
display:inline-block;
margin:4px 4px 4px 0;
}
.panel{
background:#fff;
border:1px solid #ddd;
padding:15px;
margin:10px 0;
}
.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
gap:10px;
}
.sum{
background:#f7f7f7;
padding:10px;
border:1px solid #ddd;
}
</style>
</head>
<body>
<div class="topbar">
<h2>Account Ledger</h2>
<div>
<a class="btn" href="dashboard.php">Dashboard</a>
<a class="btn" href="logout.php">Logout</a>
</div>
</div>

<?php if($msg!=""){ ?>
<p style="color:green;"><?php echo htmlspecialchars($msg); ?></p>
<?php } ?>
<?php if($err!=""){ ?>
<p style="color:red;"><?php echo htmlspecialchars($err); ?></p>
<?php } ?>

<div class="panel">
<h3>Add Entry</h3>
<form method="post">
<input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" required>
<select name="category" required>
<option value="feed">Feed</option>
<option value="haleeb">Haleeb</option>
<option value="loan">Loan</option>
</select>
<select name="entry_type" required>
<option value="debit">Debit</option>
<option value="credit">Credit</option>
</select>
<input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" required>
<input type="text" name="note" placeholder="Note (optional)">
<button class="btn" type="submit" name="add_entry">Save Entry</button>
</form>
</div>

<div class="panel">
<h3>Category View</h3>
<a class="btn" href="account.php?cat=all">All</a>
<a class="btn" href="account.php?cat=feed">Feed</a>
<a class="btn" href="account.php?cat=haleeb">Haleeb</a>
<a class="btn" href="account.php?cat=loan">Loan</a>
</div>

<div class="panel">
<h3>Overall Totals</h3>
<div class="grid">
<div class="sum"><b>Total Debit:</b> Rs <?php echo number_format((float)$totalDebit, 2); ?></div>
<div class="sum"><b>Total Credit:</b> Rs <?php echo number_format((float)$totalCredit, 2); ?></div>
</div>
</div>

<div class="panel">
<h3>Category Totals</h3>
<div class="grid">
<?php foreach($allowedCategories as $c){ 
$d = isset($categoryTotals[$c]) ? $categoryTotals[$c]['debit_total'] : 0;
$cr = isset($categoryTotals[$c]) ? $categoryTotals[$c]['credit_total'] : 0;
?>
<div class="sum">
<b><?php echo ucfirst($c); ?></b><br>
Debit: Rs <?php echo number_format((float)$d, 2); ?><br>
Credit: Rs <?php echo number_format((float)$cr, 2); ?>
</div>
<?php } ?>
</div>
</div>

<table>
<tr>
<th>Date</th>
<th>Category</th>
<th>Type</th>
<th>Amount</th>
<th>Note</th>
</tr>
<?php while($row = $entries->fetch_assoc()){ ?>
<tr>
<td><?php echo htmlspecialchars($row['entry_date']); ?></td>
<td><?php echo htmlspecialchars(ucfirst($row['category'])); ?></td>
<td><?php echo htmlspecialchars(ucfirst($row['entry_type'])); ?></td>
<td>Rs <?php echo number_format((float)$row['amount'], 2); ?></td>
<td><?php echo htmlspecialchars($row['note']); ?></td>
</tr>
<?php } ?>
</table>
</body>
</html>
