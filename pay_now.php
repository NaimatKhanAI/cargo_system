<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){
header("location:dashboard.php?pay=error");
exit();
}

$allowedCategories = ['feed', 'haleeb', 'loan'];
$msg = '';
$err = '';
$today = date('Y-m-d');
$formDate = $today;
$formCategory = 'feed';
$formAmount = '';
$formNote = '';

$stmt = $conn->prepare("SELECT * FROM bilty WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$biltyRes = $stmt->get_result();
$row = $biltyRes->fetch_assoc();
$stmt->close();

if(!$row){
header("location:dashboard.php?pay=error");
exit();
}

if(isset($_POST['pay_now'])){
$formDate = isset($_POST['entry_date']) ? $_POST['entry_date'] : $today;
$formCategory = isset($_POST['category']) ? strtolower(trim($_POST['category'])) : 'feed';
$payAmount = isset($_POST['pay_amount']) ? (int)$_POST['pay_amount'] : 0;
$formAmount = $payAmount > 0 ? (string)$payAmount : '';
$formNote = isset($_POST['note']) ? trim($_POST['note']) : '';
$currentFreight = (int)$row['freight'];

if(!in_array($formCategory, $allowedCategories, true)){
$err = 'Invalid category selected.';
} elseif($payAmount <= 0){
$err = 'Pay amount must be greater than 0.';
} elseif($payAmount > $currentFreight){
$err = 'Pay amount cannot be more than remaining freight.';
} else {
$newFreight = $currentFreight - $payAmount;
$newProfit = (int)$row['tender'] - $newFreight;
$note = $formNote !== '' ? $formNote : ("Bilty #" . $row['bilty_no'] . " payment");

$conn->begin_transaction();
try{
$ins = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, amount, note) VALUES(?, ?, 'debit', 'account', ?, ?)");
$ins->bind_param("ssds", $formDate, $formCategory, $payAmount, $note);
$ins->execute();
$ins->close();

$upd = $conn->prepare("UPDATE bilty SET freight=?, profit=? WHERE id=?");
$upd->bind_param("iii", $newFreight, $newProfit, $id);
$upd->execute();
$upd->close();

$conn->commit();
header("location:dashboard.php?pay=success");
exit();
} catch (Throwable $e){
$conn->rollback();
$err = 'Payment failed. Please try again.';
}
}
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Pay Now</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.page-wrap{
max-width:860px;
margin:25px auto;
}
.card{
background:#fff;
border:1px solid #ddd;
border-radius:10px;
padding:20px;
box-shadow:0 10px 24px rgba(0,0,0,0.06);
}
.top{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:12px;
}
.back-link{
display:inline-block;
padding:10px 14px;
background:#ececec;
color:#111;
text-decoration:none;
border-radius:8px;
font-weight:600;
}
.info{
display:grid;
grid-template-columns:repeat(2,minmax(220px,1fr));
gap:10px;
margin-bottom:12px;
}
.info .box{
background:#f7f7f7;
border:1px solid #ddd;
padding:10px;
border-radius:8px;
}
.field label{
display:block;
margin:0 0 6px;
font-size:14px;
font-weight:600;
}
.field input, .field select{
max-width:none;
margin:0 0 10px;
border:1px solid #cfcfcf;
border-radius:8px;
background:#fafafa;
}
.actions{
display:flex;
justify-content:flex-end;
}
.actions button{
max-width:180px;
border-radius:8px;
font-weight:700;
}
.err{
color:#b71c1c;
}
@media(max-width:700px){
.info{
grid-template-columns:1fr;
}
}
</style>
</head>
<body>
<div class="page-wrap">
<div class="card">
<div class="top">
<h2>Pay Now - Bilty <?php echo htmlspecialchars($row['bilty_no']); ?></h2>
<a class="back-link" href="dashboard.php">Back to Dashboard</a>
</div>

<?php if($err!=""){ ?><p class="err"><?php echo htmlspecialchars($err); ?></p><?php } ?>
<?php if($msg!=""){ ?><p style="color:green;"><?php echo htmlspecialchars($msg); ?></p><?php } ?>

<div class="info">
<div class="box"><b>Vehicle:</b> <?php echo htmlspecialchars($row['vehicle']); ?></div>
<div class="box"><b>Party:</b> <?php echo htmlspecialchars($row['party']); ?></div>
<div class="box"><b>Tender:</b> Rs <?php echo number_format((float)$row['tender'], 0); ?></div>
<div class="box"><b>Remaining Freight:</b> Rs <?php echo number_format((float)$row['freight'], 0); ?></div>
</div>

<form method="post">
<div class="field">
<label for="entry_date">Payment Date</label>
<input id="entry_date" type="date" name="entry_date" value="<?php echo htmlspecialchars($formDate); ?>" required>
</div>
<div class="field">
<label for="category">Account Category</label>
<select id="category" name="category" required>
<option value="feed" <?php echo $formCategory === 'feed' ? 'selected' : ''; ?>>Feed</option>
<option value="haleeb" <?php echo $formCategory === 'haleeb' ? 'selected' : ''; ?>>Haleeb</option>
<option value="loan" <?php echo $formCategory === 'loan' ? 'selected' : ''; ?>>Loan</option>
</select>
</div>
<div class="field">
<label for="pay_amount">Pay Amount (Account)</label>
<input id="pay_amount" type="number" name="pay_amount" min="1" max="<?php echo (int)$row['freight']; ?>" value="<?php echo htmlspecialchars($formAmount); ?>" required>
</div>
<div class="field">
<label for="note">Note (optional)</label>
<input id="note" type="text" name="note" value="<?php echo htmlspecialchars($formNote); ?>" placeholder="Payment note">
</div>
<div class="actions">
<button type="submit" name="pay_now">Pay Now</button>
</div>
</form>
</div>
</div>
</body>
</html>
