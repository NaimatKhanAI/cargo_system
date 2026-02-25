<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Cargo Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.wrap{
max-width:980px;
margin:24px auto;
}
.top{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:16px;
}
.logout{
padding:10px 14px;
background:#111;
color:#fff;
text-decoration:none;
border-radius:8px;
}
.grid{
display:grid;
grid-template-columns:repeat(2,minmax(220px,1fr));
gap:14px;
}
.card{
display:block;
background:#fff;
border:1px solid #ddd;
border-radius:12px;
padding:20px;
text-decoration:none;
color:#111;
box-shadow:0 10px 24px rgba(0,0,0,0.06);
}
.card h3{
margin:0 0 6px;
}
.card p{
margin:0;
color:#666;
font-size:14px;
}
@media(max-width:700px){
.grid{
grid-template-columns:1fr;
}
}
</style>
</head>
<body>
<div class="wrap">
<div class="top">
<h2>Cargo Dashboard</h2>
<a class="logout" href="logout.php">Logout</a>
</div>

<div class="grid">
<a class="card" href="feed.php">
<h3>Feed</h3>
<p>Feed factory bilty records and profit details.</p>
</a>
<a class="card" href="haleeb.php">
<h3>Haleeb</h3>
<p>Haleeb factory bilty records and tracking.</p>
</a>
<a class="card" href="account.php">
<h3>Account Ledger</h3>
<p>Debit/credit entries and overall balances.</p>
</a>
<a class="card" href="process_img.php">
<h3>Image Processing</h3>
<p>Rate-list image processing and extraction.</p>
</a>
</div>
</div>
</body>
</html>
