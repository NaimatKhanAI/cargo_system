<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$rows = $conn->query("SELECT source_file, source_image_path, sr_no, station_english, station_urdu, rate_2026_01_01, rate_2026_01_02, created_at FROM image_processed_rates ORDER BY id DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Rate List</title>
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
padding:12px;
margin-bottom:10px;
}
.muted{
color:#666;
font-size:13px;
}
.icon-link{
display:inline-flex;
width:28px;
height:28px;
align-items:center;
justify-content:center;
background:#1565c0;
color:#fff;
text-decoration:none;
border-radius:6px;
font-size:14px;
}
</style>
</head>
<body>
<div class="topbar">
<h2>Rate List</h2>
<div>
<a class="btn" href="process_img.php">Process Image</a>
<a class="btn" href="dashboard.php">Dashboard</a>
</div>
</div>

<div class="panel">
<div class="muted">Processed image rows saved in database.</div>
</div>

<table>
<tr>
<th>Source</th>
<th>SR.</th>
<th>STATION (ENGLISH)</th>
<th>STATION (URDU)</th>
<th>1/1/2026</th>
<th>1/2/2026</th>
<th>Created</th>
</tr>
<?php while($r = $rows->fetch_assoc()){ ?>
<tr>
<td>
<?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''){ ?>
<a class="icon-link" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="<?php echo htmlspecialchars($r['source_file']); ?>" aria-label="View Source Image">&#128065;</a>
<?php } else { ?>
-
<?php } ?>
</td>
<td><?php echo htmlspecialchars($r['sr_no']); ?></td>
<td><?php echo htmlspecialchars($r['station_english']); ?></td>
<td><?php echo htmlspecialchars($r['station_urdu']); ?></td>
<td><?php echo htmlspecialchars($r['rate_2026_01_01']); ?></td>
<td><?php echo htmlspecialchars($r['rate_2026_01_02']); ?></td>
<td><?php echo htmlspecialchars($r['created_at']); ?></td>
</tr>
<?php } ?>
</table>
</body>
</html>
