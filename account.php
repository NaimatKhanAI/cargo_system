<?php
session_start();
if(!isset($_SESSION['user']) && (!isset($_SESSION['login_verified']) || $_SESSION['login_verified'] !== true)){
header("location:index.php");
exit();
}

include 'config/db.php';

function exec_prepared_result_local($conn, $sql, $types = '', $values = []){
$stmt = $conn->prepare($sql);
if(!$stmt){
return [null, false];
}
$count = count($values);
if($count === 1){
$stmt->bind_param($types, $values[0]);
} elseif($count === 2){
$stmt->bind_param($types, $values[0], $values[1]);
} elseif($count === 3){
$stmt->bind_param($types, $values[0], $values[1], $values[2]);
}
$stmt->execute();
$res = $stmt->get_result();
return [$stmt, $res];
}

$allowedCategories = ['feed', 'haleeb', 'loan'];
$allowedTypes = ['debit', 'credit'];
$allowedModes = ['cash', 'account'];
$msg = '';
$err = '';
$formEntryDate = date('Y-m-d');
$formCategory = 'feed';
$formEntryType = 'debit';
$formAmountMode = 'cash';
$formAmount = '';
$formNote = '';
$editingId = 0;
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

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
$amountMode = isset($_POST['amount_mode']) ? strtolower(trim($_POST['amount_mode'])) : '';
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';

$formEntryDate = $entryDate;
$formCategory = $category;
$formEntryType = $entryType;
$formAmountMode = $amountMode;
$formAmount = $amount > 0 ? (string)$amount : '';
$formNote = $note;

if($editingId <= 0){
$err = 'Invalid entry id.';
} elseif(!in_array($category, $allowedCategories, true)){
$err = 'Invalid category.';
} elseif(!in_array($entryType, $allowedTypes, true)){
$err = 'Invalid entry type.';
} elseif(!in_array($amountMode, $allowedModes, true)){
$err = 'Invalid amount mode.';
} elseif($amount <= 0){
$err = 'Amount must be greater than 0.';
} else {
$canUpdate = true;

$linkStmt = $conn->prepare("SELECT bilty_id, haleeb_bilty_id, entry_type FROM account_entries WHERE id=? LIMIT 1");
$linkStmt->bind_param("i", $editingId);
$linkStmt->execute();
$linkRes = $linkStmt->get_result()->fetch_assoc();
$linkStmt->close();

if($linkRes && isset($linkRes['bilty_id']) && (int)$linkRes['bilty_id'] > 0 && strtolower((string)$linkRes['entry_type']) === 'debit'){
$biltyId = (int)$linkRes['bilty_id'];

$freightStmt = $conn->prepare("SELECT COALESCE(original_freight, freight) AS freight_total FROM bilty WHERE id=? LIMIT 1");
$freightStmt->bind_param("i", $biltyId);
$freightStmt->execute();
$freightRes = $freightStmt->get_result()->fetch_assoc();
$freightStmt->close();

if(!$freightRes){
$err = 'Linked bilty not found. Transaction update blocked.';
$canUpdate = false;
} else {
$freightTotal = (float)$freightRes['freight_total'];

$paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE bilty_id=? AND entry_type='debit' AND id<>?");
$paidStmt->bind_param("ii", $biltyId, $editingId);
$paidStmt->execute();
$paidRes = $paidStmt->get_result()->fetch_assoc();
$paidStmt->close();

$paidByOthers = $paidRes && $paidRes['paid_total'] ? (float)$paidRes['paid_total'] : 0;
$maxAllowed = $freightTotal - $paidByOthers;
if($maxAllowed < 0){
$maxAllowed = 0;
}

if((float)$amount > $maxAllowed){
$err = 'You cannot update this transaction. Amount is greater than bilty remaining.';
$canUpdate = false;
}
}
} elseif($linkRes && isset($linkRes['haleeb_bilty_id']) && (int)$linkRes['haleeb_bilty_id'] > 0 && strtolower((string)$linkRes['entry_type']) === 'debit'){
$haleebBiltyId = (int)$linkRes['haleeb_bilty_id'];

$freightStmt = $conn->prepare("SELECT freight AS freight_total FROM haleeb_bilty WHERE id=? LIMIT 1");
$freightStmt->bind_param("i", $haleebBiltyId);
$freightStmt->execute();
$freightRes = $freightStmt->get_result()->fetch_assoc();
$freightStmt->close();

if(!$freightRes){
$err = 'Linked haleeb bilty not found. Transaction update blocked.';
$canUpdate = false;
} else {
$freightTotal = (float)$freightRes['freight_total'];

$paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit' AND id<>?");
$paidStmt->bind_param("ii", $haleebBiltyId, $editingId);
$paidStmt->execute();
$paidRes = $paidStmt->get_result()->fetch_assoc();
$paidStmt->close();

$paidByOthers = $paidRes && $paidRes['paid_total'] ? (float)$paidRes['paid_total'] : 0;
$maxAllowed = $freightTotal - $paidByOthers;
if($maxAllowed < 0){
$maxAllowed = 0;
}

if((float)$amount > $maxAllowed){
$err = 'You cannot update this transaction. Amount is greater than haleeb bilty remaining.';
$canUpdate = false;
}
}
}

if($canUpdate){
$stmt = $conn->prepare("UPDATE account_entries SET entry_date=?, category=?, entry_type=?, amount_mode=?, amount=?, note=? WHERE id=?");
$stmt->bind_param("ssssdsi", $entryDate, $category, $entryType, $amountMode, $amount, $note, $editingId);
$stmt->execute();
$stmt->close();
$msg = 'Entry updated.';
$editingId = 0;
$formEntryDate = date('Y-m-d');
$formCategory = 'feed';
$formEntryType = 'debit';
$formAmountMode = 'cash';
$formAmount = '';
$formNote = '';
}
}
}

if(isset($_POST['add_entry'])){
$entryDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : date('Y-m-d');
$category = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : '';
$entryType = isset($_POST['entry_type']) ? strtolower(trim($_POST['entry_type'])) : '';
$amountMode = isset($_POST['amount_mode']) ? strtolower(trim($_POST['amount_mode'])) : '';
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';

if(!in_array($category, $allowedCategories, true)){
$err = 'Invalid category.';
} elseif(!in_array($entryType, $allowedTypes, true)){
$err = 'Invalid entry type.';
} elseif(!in_array($amountMode, $allowedModes, true)){
$err = 'Invalid amount mode.';
} elseif($amount <= 0){
$err = 'Amount must be greater than 0.';
} else {
$stmt = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, amount, note) VALUES(?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssds", $entryDate, $category, $entryType, $amountMode, $amount, $note);
$stmt->execute();
$stmt->close();
$msg = 'Entry saved.';
}
}

$cat = isset($_GET['cat']) ? strtolower($_GET['cat']) : 'all';
if(!in_array($cat, array_merge(['all'], $allowedCategories), true)){
$cat = 'all';
}

$where = [];
$bindTypes = '';
$bindValues = [];
if($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)){
$where[] = "entry_date >= ?";
$bindTypes .= 's';
$bindValues[] = $dateFrom;
}
if($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)){
$where[] = "entry_date <= ?";
$bindTypes .= 's';
$bindValues[] = $dateTo;
}
if($cat !== 'all'){
$where[] = "category = ?";
$bindTypes .= 's';
$bindValues[] = $cat;
}
$whereSql = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';
$dateQueryTail = '';
if($dateFrom !== ''){
$dateQueryTail .= '&date_from=' . urlencode($dateFrom);
}
if($dateTo !== ''){
$dateQueryTail .= '&date_to=' . urlencode($dateTo);
}

if(isset($_GET['edit_id']) && !isset($_POST['update_entry'])){
$requestedEditId = (int)$_GET['edit_id'];
if($requestedEditId > 0){
$editStmt = $conn->prepare("SELECT id, entry_date, category, entry_type, amount_mode, amount, note FROM account_entries WHERE id=? LIMIT 1");
$editStmt->bind_param("i", $requestedEditId);
$editStmt->execute();
$editRes = $editStmt->get_result();
if($editRes->num_rows > 0){
$editRow = $editRes->fetch_assoc();
$editingId = (int)$editRow['id'];
$formEntryDate = $editRow['entry_date'];
$formCategory = $editRow['category'];
$formEntryType = $editRow['entry_type'];
$formAmountMode = isset($editRow['amount_mode']) && $editRow['amount_mode'] !== '' ? $editRow['amount_mode'] : 'cash';
$formAmount = (string)$editRow['amount'];
$formNote = $editRow['note'];
}
$editStmt->close();
}
}

$totalSql = "SELECT 
SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS total_debit,
SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS total_credit
FROM account_entries" . $whereSql;
list($totalStmt, $totalsRes) = exec_prepared_result_local($conn, $totalSql, $bindTypes, $bindValues);
$totals = $totalsRes ? $totalsRes->fetch_assoc() : ['total_debit' => 0, 'total_credit' => 0];
if($totalStmt){ $totalStmt->close(); }
$totalDebit = $totals['total_debit'] ? $totals['total_debit'] : 0;
$totalCredit = $totals['total_credit'] ? $totals['total_credit'] : 0;
$netBalance = (float)$totalCredit - (float)$totalDebit;

$modeTotalsSql = "SELECT
SUM(CASE WHEN entry_type='debit' AND amount_mode='cash' THEN amount ELSE 0 END) AS debit_cash,
SUM(CASE WHEN entry_type='debit' AND amount_mode='account' THEN amount ELSE 0 END) AS debit_account,
SUM(CASE WHEN entry_type='credit' AND amount_mode='cash' THEN amount ELSE 0 END) AS credit_cash,
SUM(CASE WHEN entry_type='credit' AND amount_mode='account' THEN amount ELSE 0 END) AS credit_account
FROM account_entries" . $whereSql;
list($modeStmt, $modeRes) = exec_prepared_result_local($conn, $modeTotalsSql, $bindTypes, $bindValues);
$modeTotals = $modeRes ? $modeRes->fetch_assoc() : ['debit_cash' => 0, 'debit_account' => 0, 'credit_cash' => 0, 'credit_account' => 0];
if($modeStmt){ $modeStmt->close(); }
$debitCash = $modeTotals['debit_cash'] ? $modeTotals['debit_cash'] : 0;
$debitAccount = $modeTotals['debit_account'] ? $modeTotals['debit_account'] : 0;
$creditCash = $modeTotals['credit_cash'] ? $modeTotals['credit_cash'] : 0;
$creditAccount = $modeTotals['credit_account'] ? $modeTotals['credit_account'] : 0;

$categoryTotals = [];
$catTotalsSql = "SELECT category,
SUM(CASE WHEN entry_type='debit' THEN amount ELSE 0 END) AS debit_total,
SUM(CASE WHEN entry_type='credit' THEN amount ELSE 0 END) AS credit_total,
SUM(CASE WHEN entry_type='debit' AND amount_mode='cash' THEN amount ELSE 0 END) AS debit_cash,
SUM(CASE WHEN entry_type='debit' AND amount_mode='account' THEN amount ELSE 0 END) AS debit_account,
SUM(CASE WHEN entry_type='credit' AND amount_mode='cash' THEN amount ELSE 0 END) AS credit_cash,
SUM(CASE WHEN entry_type='credit' AND amount_mode='account' THEN amount ELSE 0 END) AS credit_account
FROM account_entries" . $whereSql . "
GROUP BY category";
list($catStmt, $catResult) = exec_prepared_result_local($conn, $catTotalsSql, $bindTypes, $bindValues);
while($r = $catResult->fetch_assoc()){
$categoryTotals[$r['category']] = $r;
}
if($catStmt){ $catStmt->close(); }

$entriesSql = "SELECT * FROM account_entries" . $whereSql . " ORDER BY entry_date DESC, id DESC";
list($entryStmt, $entries) = exec_prepared_result_local($conn, $entriesSql, $bindTypes, $bindValues);
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
.cat-card{
background:#fcfcfc;
border:1px solid #e4e4e4;
border-radius:10px;
padding:12px;
}
.cat-title{
display:block;
font-size:16px;
margin-bottom:8px;
}
.line{
display:block;
padding:6px 8px;
margin:5px 0;
border-radius:6px;
font-size:14px;
}
.line-debit{
background:#ffe9e9;
color:#9f1d1d;
}
.line-credit{
background:#e8f7ea;
color:#1f7a35;
}
.line-debit-cash{
background:#fff2f2;
color:#8f2a2a;
}
.line-debit-account{
background:#ffe3e3;
color:#7f1f1f;
}
.line-credit-cash{
background:#f1fbf3;
color:#256f3a;
}
.line-credit-account{
background:#dff4e4;
color:#145f2f;
}
.line-net{
background:#eef3ff;
color:#1e3f8a;
font-weight:700;
}
.line-net-cash{
background:#e6f4ff;
color:#0f4c81;
font-weight:700;
}
.line-net-account{
background:#fff4df;
color:#7a5200;
font-weight:700;
}
.totals-grid{
display:grid;
grid-template-columns:repeat(3,minmax(180px,1fr));
gap:10px;
}
.totals-full{
margin-top:10px;
}
.icon-btn{
width:26px;
height:26px;
display:inline-flex;
align-items:center;
justify-content:center;
padding:0;
font-size:14px;
line-height:1;
margin:0 2px 0 0;
text-decoration:none;
}
.icon-delete{
background:#c62828;
}
.col-note{
width:40%;
}
.col-action{
width:70px;
white-space:nowrap;
text-align:center;
}
.filter-form{
display:flex;
flex-wrap:wrap;
gap:8px;
align-items:end;
}
.filter-form input{
max-width:none;
margin:0;
}
@media(max-width:700px){
.totals-grid{
grid-template-columns:1fr;
}
}
</style>
</head>
<body>
<div class="topbar">
<h2>Account Ledger</h2>
<div>
<a class="btn" href="feed.php">Feed</a>
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
<select name="amount_mode" required>
<option value="cash" <?php echo $formAmountMode === 'cash' ? 'selected' : ''; ?>>Cash</option>
<option value="account" <?php echo $formAmountMode === 'account' ? 'selected' : ''; ?>>Account</option>
</select>
<input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" value="<?php echo htmlspecialchars($formAmount); ?>" required>
<input type="text" name="note" placeholder="Note (optional)" value="<?php echo htmlspecialchars($formNote); ?>">
<?php if($editingId > 0){ ?>
<input type="hidden" name="edit_id" value="<?php echo (int)$editingId; ?>">
<button class="btn" type="submit" name="update_entry">Update Entry</button>
<a class="btn" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">Cancel Edit</a>
<a class="btn icon-btn icon-delete" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&delete_id=<?php echo (int)$editingId; ?>" onclick="return confirm('Delete this entry?')" title="Delete" aria-label="Delete">&#128465;</a>
<?php }else{ ?>
<button class="btn" type="submit" name="add_entry">Save Entry</button>
<?php } ?>
</form>
</div>

<div class="panel">
<h3>Category View</h3>
<a class="btn" href="account.php?cat=all<?php echo $dateQueryTail; ?>">All</a>
<a class="btn" href="account.php?cat=feed<?php echo $dateQueryTail; ?>">Feed</a>
<a class="btn" href="account.php?cat=haleeb<?php echo $dateQueryTail; ?>">Haleeb</a>
<a class="btn" href="account.php?cat=loan<?php echo $dateQueryTail; ?>">Loan</a>
<form class="filter-form" method="get" style="margin-top:8px;">
<input type="hidden" name="cat" value="<?php echo htmlspecialchars($cat); ?>">
<div>
<label for="date_from" style="display:block;font-size:12px;margin-bottom:4px;">From</label>
<input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
</div>
<div>
<label for="date_to" style="display:block;font-size:12px;margin-bottom:4px;">To</label>
<input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
</div>
<button class="btn" type="submit">Apply</button>
<a class="btn" href="account.php?cat=<?php echo urlencode($cat); ?>">Reset</a>
</form>
</div>

<div class="panel">
<h3>Overall Totals</h3>
<div class="totals-grid">
<div class="sum">
<b>Total Debit:</b> Rs <?php echo number_format((float)$totalDebit, 2); ?><br>
<b>Total Credit:</b> Rs <?php echo number_format((float)$totalCredit, 2); ?>
</div>
<div class="sum">
<b>Debit (Cash):</b> Rs <?php echo number_format((float)$debitCash, 2); ?><br>
<b>Debit (Account):</b> Rs <?php echo number_format((float)$debitAccount, 2); ?>
</div>
<div class="sum">
<b>Credit (Cash):</b> Rs <?php echo number_format((float)$creditCash, 2); ?><br>
<b>Credit (Account):</b> Rs <?php echo number_format((float)$creditAccount, 2); ?>
</div>
</div>
<div class="sum totals-full"><b>Net Balance (Credit - Debit):</b> Rs <?php echo number_format((float)$netBalance, 2); ?></div>
</div>

<div class="panel">
<h3>Category Totals</h3>
<div class="grid">
<?php $categoriesToShow = $cat === 'all' ? $allowedCategories : [$cat]; ?>
<?php foreach($categoriesToShow as $c){ 
$d = isset($categoryTotals[$c]) ? $categoryTotals[$c]['debit_total'] : 0;
$cr = isset($categoryTotals[$c]) ? $categoryTotals[$c]['credit_total'] : 0;
$dCash = isset($categoryTotals[$c]) ? $categoryTotals[$c]['debit_cash'] : 0;
$dAccount = isset($categoryTotals[$c]) ? $categoryTotals[$c]['debit_account'] : 0;
$cCash = isset($categoryTotals[$c]) ? $categoryTotals[$c]['credit_cash'] : 0;
$cAccount = isset($categoryTotals[$c]) ? $categoryTotals[$c]['credit_account'] : 0;
$nCash = (float)$cCash - (float)$dCash;
$nAccount = (float)$cAccount - (float)$dAccount;
$n = (float)$cr - (float)$d;
?>
<div class="cat-card">
<b class="cat-title"><?php echo ucfirst($c); ?></b>
<span class="line line-debit">Debit: Rs <?php echo number_format((float)$d, 2); ?></span>
<span class="line line-credit">Credit: Rs <?php echo number_format((float)$cr, 2); ?></span>
<span class="line line-debit-cash">Debit (Cash): Rs <?php echo number_format((float)$dCash, 2); ?></span>
<span class="line line-debit-account">Debit (Account): Rs <?php echo number_format((float)$dAccount, 2); ?></span>
<span class="line line-credit-cash">Credit (Cash): Rs <?php echo number_format((float)$cCash, 2); ?></span>
<span class="line line-credit-account">Credit (Account): Rs <?php echo number_format((float)$cAccount, 2); ?></span>
<span class="line line-net-cash">Net (Cash): Rs <?php echo number_format((float)$nCash, 2); ?></span>
<span class="line line-net-account">Net (Account): Rs <?php echo number_format((float)$nAccount, 2); ?></span>
<span class="line line-net">Net: Rs <?php echo number_format((float)$n, 2); ?></span>
</div>
<?php } ?>
</div>
</div>

<table>
<tr>
<th>Date</th>
<th>Category</th>
<th>Type</th>
<th>Mode</th>
<th>Amount</th>
<th class="col-note">Note</th>
<th class="col-action">Action</th>
</tr>
<?php while($row = $entries->fetch_assoc()){ ?>
<tr>
<td><?php echo htmlspecialchars($row['entry_date']); ?></td>
<td><?php echo htmlspecialchars(ucfirst($row['category'])); ?></td>
<td><?php echo htmlspecialchars(ucfirst($row['entry_type'])); ?></td>
<td><?php echo htmlspecialchars(ucfirst(isset($row['amount_mode']) && $row['amount_mode'] !== '' ? $row['amount_mode'] : 'cash')); ?></td>
<td>Rs <?php echo number_format((float)$row['amount'], 2); ?></td>
<td class="col-note"><?php echo htmlspecialchars($row['note']); ?></td>
<td class="col-action">
<a class="btn icon-btn" href="account.php?cat=<?php echo urlencode($cat); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&edit_id=<?php echo (int)$row['id']; ?>" title="Edit" aria-label="Edit">&#9998;</a>
</td>
</tr>
<?php } ?>
</table>
<?php if($entryStmt){ $entryStmt->close(); } ?>
</body>
</html>

