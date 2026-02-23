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
header("location:dashboard.php");
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

if(isset($_POST['dashboard'])){
if($canChooseOption){
$_SESSION['user'] = $pendingUser !== '' ? $pendingUser : 'User';
unset($_SESSION['login_verified']);
unset($_SESSION['pending_user']);
header("location:dashboard.php");
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

<h2>Login</h2>
<form method="post">
<input name="user" placeholder="username" value="<?php echo htmlspecialchars($pendingUser); ?>">
<input name="pass" placeholder="password" type="password">
<button name="login" type="submit">Login</button>
<div id="optionWrap" style="<?php echo $canChooseOption ? 'display:block;' : 'display:none;'; ?>">
<button name="dashboard" type="submit">Cargo Dashboard</button>
<button name="guest" type="submit">Guest</button>
<button name="account" type="submit">Account</button>
</div>
</form>
<?php if($error!=""){ ?>
<p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>
