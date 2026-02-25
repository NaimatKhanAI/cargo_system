<?php
session_start();
include 'config/db.php';
$error = "";
$canChooseOption = isset($_SESSION['login_verified']) && $_SESSION['login_verified'] === true;
$pendingUser = isset($_SESSION['pending_user']) ? $_SESSION['pending_user'] : '';

if(isset($_POST['guest'])){
if($canChooseOption){
$_SESSION['user']='Guest';
unset($_SESSION['login_verified']);
unset($_SESSION['pending_user']);
header("location:feed.php");
exit();
}else{
$error = "Pehle sahi login karein.";
}
}

if(isset($_POST['account'])){
if($canChooseOption){
header("location:account.php");
exit();
}else{
$error = "Pehle sahi login karein.";
}
}

if(isset($_POST['process_img'])){
if($canChooseOption){
header("location:process_img.php");
exit();
}else{
$error = "Pehle sahi login karein.";
}
}

if(isset($_POST['rate_list'])){
if($canChooseOption){
header("location:rate_list.php");
exit();
}else{
$error = "Pehle sahi login karein.";
}
}

if(isset($_POST['feed'])){
if($canChooseOption){
$_SESSION['user'] = $pendingUser !== '' ? $pendingUser : 'User';
unset($_SESSION['login_verified']);
unset($_SESSION['pending_user']);
header("location:feed.php");
exit();
}else{
$error = "Pehle sahi login karein.";
}
}

if(isset($_POST['login'])){
$u=isset($_POST['user']) ? trim($_POST['user']) : '';
$p=isset($_POST['pass']) ? trim($_POST['pass']) : '';

if($u === '' || $p === ''){
$error = "Username aur password required hai.";
$canChooseOption = false;
unset($_SESSION['login_verified']);
unset($_SESSION['pending_user']);
} else {
$stmt = $conn->prepare("SELECT username FROM users WHERE username=? AND password=? LIMIT 1");
$stmt->bind_param("ss", $u, $p);
$stmt->execute();
$res = $stmt->get_result();
if($res->num_rows > 0){
$_SESSION['login_verified'] = true;
$_SESSION['pending_user'] = $u;
$canChooseOption = true;
}else{
unset($_SESSION['login_verified']);
unset($_SESSION['pending_user']);
$canChooseOption = false;
$error = "Wrong login";
}
$stmt->close();
}
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.auth-wrap{
min-height:100vh;
display:flex;
align-items:center;
justify-content:center;
padding:20px;
}
.auth-card{
width:100%;
max-width:520px;
background:#fff;
border:1px solid #ddd;
border-radius:12px;
padding:24px;
box-shadow:0 12px 30px rgba(0,0,0,0.08);
}
.auth-title{
margin:0 0 6px;
}
.auth-sub{
margin:0 0 14px;
color:#666;
font-size:14px;
}
.field-label{
display:block;
margin:8px 0 6px;
font-size:14px;
font-weight:600;
color:#333;
}
.auth-input{
max-width:none;
margin:0 0 10px;
border:1px solid #cfcfcf;
border-radius:8px;
background:#fafafa;
}
.primary-btn{
max-width:none;
border-radius:8px;
font-weight:700;
}
.option-wrap{
margin-top:16px;
padding-top:14px;
border-top:1px solid #eee;
}
.option-title{
margin:0 0 10px;
font-size:14px;
font-weight:700;
color:#222;
}
.option-grid{
display:grid;
grid-template-columns:repeat(3,minmax(100px,1fr));
gap:10px;
}
.option-btn{
max-width:none;
border-radius:8px;
padding:12px 10px;
font-weight:700;
}
.err{
margin:10px 0 0;
color:#b71c1c;
font-weight:600;
}
.ok{
margin:10px 0 0;
color:#1f7a35;
font-weight:600;
}
@media(max-width:700px){
.option-grid{
grid-template-columns:1fr;
}
}
</style>
</head>
<body>
<div class="auth-wrap">
<div class="auth-card">
<h2 class="auth-title">Cargo System Login</h2>
<p class="auth-sub">Username/password enter karein, phir desired section choose karein.</p>

<form method="post">
<label class="field-label" for="user">Username</label>
<input class="auth-input" id="user" name="user" placeholder="Enter username" value="<?php echo htmlspecialchars($pendingUser); ?>">

<label class="field-label" for="pass">Password</label>
<input class="auth-input" id="pass" name="pass" placeholder="Enter password" type="password">

<button class="primary-btn" name="login" type="submit">Login</button>

<div class="option-wrap" id="optionWrap" style="<?php echo $canChooseOption ? 'display:block;' : 'display:none;'; ?>">
<p class="option-title">Login verified. Choose where to continue:</p>
<div class="option-grid">
<button class="option-btn" name="feed" type="submit">Cargo Feed</button>
<button class="option-btn" name="account" type="submit">Account Ledger</button>
<button class="option-btn" name="process_img" type="submit">Process Image</button>
<button class="option-btn" name="rate_list" type="submit">Rate List</button>
<button class="option-btn" name="guest" type="submit">Guest Mode</button>
</div>
</div>
</form>

<?php if($canChooseOption){ ?>
<p class="ok">Login successful.</p>
<?php } ?>
<?php if($error!=""){ ?>
<p class="err"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>
</div>
</div>
</body>
</html>

