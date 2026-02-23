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
$formEntryDate = date('Y-m-d');
$formCategory = 'feed';
$formEntryType = 'debit';
$formAmount = '';
$formNote = '';
$editingId = 0;

if(isset($_GET['delete_id'])){
$deleteId = (int)$_GET['delete_id'];
if($deleteId > 0){
$deleteStmt = $conn->prepare("DELETE FROM account_entries WHERE id=?");
$deleteStmt->bind_param("i", $deleteId);
$deleteStmt->execute();
$deleteStmt->close();
$msg = 'Entry deleted.';
}
}

if(isset($_POST['update_entry'])){
$editingId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
$entryDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : date('Y-m-d');
$category = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : '';
$entryType = isset($_POST['entry_type']) ? strtolower(trim($_POST['entry_type'])) : '';
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';

$formEntryDate = $entryDate;
$formCategory = $category;
$formEntryType = $entryType;
$formAmount = $amount > 0 ? (string)$amount : '';
$formNote = $note;

if($editingId <= 0){
$err = 'Invalid entry id.';
} elseif(!in_array($category, $allowedCategories, true)){
$err = 'Invalid category.';
} elseif(!in_array($entryType, $allowedTypes, true)){
$err = 'Invalid entry type.';
} elseif($amount <= 0){
$err = 'Amount must be greater than 0.';
} else {
$stmt = $conn->prepare("UPDATE account_entries SET entry_date=?, category=?, entry_type=?, amount=?, note=? WHERE id=?");
$stmt->bind_param("sssdsi", $entryDate, $category, $entryType, $amount, $note, $editingId);
$stmt->execute();
$stmt->close();
$msg = 'Entry updated.';
$editingId = 0;
$formEntryDate = date('Y-m-d');
$formCategory = 'feed';
$formEntryType = 'debit';
$formAmount = '';
$formNote = '';
}
}

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

if(isset($_GET['edit_id']) && !isset($_POST['update_entry'])){
$requestedEditId = (int)$_GET['edit_id'];
if($requestedEditId > 0){
$editStmt = $conn->prepare("SELECT id, entry_date, category, entry_type, amount, note FROM account_entries WHERE id=? LIMIT 1");
$editStmt->bind_param("i", $requestedEditId);
$editStmt->execute();
$editRes = $editStmt->get_result();
if($editRes->num_rows > 0){
$editRow = $editRes->fetch_assoc();
$editingId = (int)$editRow['id'];
$formEntryDate = $editRow['entry_date'];
$formCategory = $editRow['category'];
$formEntryType = $editRow['entry_type'];
$formAmount = (string)$editRow['amount'];
$formNote = $editRow['note'];
}
$editStmt->close();
}
}

$totalSql = "SELECT 
SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS total_debit,
SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS total_credit
FROM account_entries";
$totals = $conn->query($totalSql)->fetch_assoc();
$totalDebit = $totals['total_debit'] ? $totals['total_debit'] : 0;
$totalCredit = $totals['total_credit'] ? $totals['total_credit'] : 0;
$netBalance = (float)$totalCredit - (float)$totalDebit;

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

if($cat === 'all'){
$entries = $conn->query("SELECT * FROM account_entries ORDER BY entry_date DESC, id DESC");
} else {
$entryStmt = $conn->prepare("SELECT * FROM account_entries WHERE category=? ORDER BY entry_date DESC, id DESC");
$entryStmt->bind_param("s", $cat);
$entryStmt->execute();
$entries = $entryStmt->get_result();
}
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
<h3><?php echo $editingId > 0 ? 'Edit Entry' : 'Add Entry'; ?></h3>
<form method="post">
<input type="date" name="entry_date" value="<?php echo htmlspecialchars($formEntryDate); ?>" required>
<select name="category" required>
<option value="feed" <?php echo $formCategory === 'feed' ? 'selected' : ''; ?>>Feed</option>
<option value="haleeb" <?php echo $formCategory === 'haleeb' ? 'selected' : ''; ?>>Haleeb</option>
<option value="loan" <?php echo $formCategory === 'loan' ? 'selected' : ''; ?>>Loan</option>
</select>
<select name="entry_type" required>
<option value="debit" <?php echo $formEntryType === 'debit' ? 'selected' : ''; ?>>Debit</option>
<option value="credit" <?php echo $formEntryType === 'credit' ? 'selected' : ''; ?>>Credit</option>
</select>
<input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" value="<?php echo htmlspecialchars($formAmount); ?>" required>
<input type="text" name="note" placeholder="Note (optional)" value="<?php echo htmlspecialchars($formNote); ?>">
<?php if($editingId > 0){ ?>
<input type="hidden" name="edit_id" value="<?php echo (int)$editingId; ?>">
<button class="btn" type="submit" name="update_entry">Update Entry</button>
<a class="btn" href="account.php?cat=<?php echo urlencode($cat); ?>">Cancel Edit</a>
<?php }else{ ?>
<button class="btn" type="submit" name="add_entry">Save Entry</button>
<?php } ?>
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
<div class="sum"><b>Net Balance (Credit - Debit):</b> Rs <?php echo number_format((float)$netBalance, 2); ?></div>
</div>
</div>

<div class="panel">
<h3>Category Totals</h3>
<div class="grid">
<?php foreach($allowedCategories as $c){ 
$d = isset($categoryTotals[$c]) ? $categoryTotals[$c]['debit_total'] : 0;
$cr = isset($categoryTotals[$c]) ? $categoryTotals[$c]['credit_total'] : 0;
$n = (float)$cr - (float)$d;
?>
<div class="sum">
<b><?php echo ucfirst($c); ?></b><br>
Debit: Rs <?php echo number_format((float)$d, 2); ?><br>
Credit: Rs <?php echo number_format((float)$cr, 2); ?><br>
Net: Rs <?php echo number_format((float)$n, 2); ?>
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
<th>Action</th>
</tr>
<?php while($row = $entries->fetch_assoc()){ ?>
<tr>
<td><?php echo htmlspecialchars($row['entry_date']); ?></td>
<td><?php echo htmlspecialchars(ucfirst($row['category'])); ?></td>
<td><?php echo htmlspecialchars(ucfirst($row['entry_type'])); ?></td>
<td>Rs <?php echo number_format((float)$row['amount'], 2); ?></td>
<td><?php echo htmlspecialchars($row['note']); ?></td>
<td>
<a class="btn" href="account.php?cat=<?php echo urlencode($cat); ?>&edit_id=<?php echo (int)$row['id']; ?>">Edit</a>
<a class="btn" href="account.php?cat=<?php echo urlencode($cat); ?>&delete_id=<?php echo (int)$row['id']; ?>" onclick="return confirm('Delete this entry?')">Delete</a>
</td>
</tr>
<?php } ?>
</table>
<?php if(isset($entryStmt)){ $entryStmt->close(); } ?>
</body>
</html>
