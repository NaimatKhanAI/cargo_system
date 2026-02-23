<?php
session_start();
include 'config/db.php';
$error = "";
$canChooseOption = isset($_SESSION['login_verified']) && $_SESSION['login_verified'] === true;

if(isset($_POST['guest'])){
if($canChooseOption){
$_SESSION['user']='Guest';
unset($_SESSION['login_verified']);
header("location:dashboard.php");
exit();
}else{
$error = "Pehle sahi login karein.";
}
}

if(isset($_POST['login'])){
$u=$_POST['user'];
$p=$_POST['pass'];

$q=$conn->query("SELECT * FROM users WHERE username='$u' AND password='$p'");
if($q->num_rows>0){
$_SESSION['login_verified'] = true;
$canChooseOption = true;
}else{
unset($_SESSION['login_verified']);
$canChooseOption = false;
$error = "Wrong login";
}
}
?>

<h2>Login</h2>
<form method="post">
<input name="user" placeholder="username">
<input name="pass" placeholder="password" type="password">
<button name="login" type="submit">Login</button>
<div id="optionWrap" style="<?php echo $canChooseOption ? 'display:block;' : 'display:none;'; ?>">
<button name="guest" type="submit">Guest</button>
<button type="button">Account</button>
<button type="button">Feed</button>
<button type="button">Haleeb</button>
</div>
</form>
<?php if($error!=""){ ?>
<p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php } ?>
