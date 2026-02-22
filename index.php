<?php
session_start();
include 'config/db.php';

if(isset($_POST['login'])){
$u=$_POST['user'];
$p=$_POST['pass'];

$q=$conn->query("SELECT * FROM users WHERE username='$u' AND password='$p'");
if($q->num_rows>0){
$_SESSION['user']=$u;
header("location:dashboard.php");
}else{
echo "Wrong login";
}
}
?>

<h2>Login</h2>
<form method="post">
<input name="user" placeholder="username">
<input name="pass" placeholder="password" type="password">
<button name="login">Login</button>
</form>
