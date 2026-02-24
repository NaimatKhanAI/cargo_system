<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$msg = '';
$err = '';
$editingId = 0;

if(isset($_POST['update_rate'])){
$editingId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
$srNo = isset($_POST['sr_no']) ? trim($_POST['sr_no']) : '';
$stationEnglish = isset($_POST['station_english']) ? trim($_POST['station_english']) : '';
$stationUrdu = isset($_POST['station_urdu']) ? trim($_POST['station_urdu']) : '';
$rate1 = isset($_POST['rate_2026_01_01']) ? trim($_POST['rate_2026_01_01']) : '';
$rate2 = isset($_POST['rate_2026_01_02']) ? trim($_POST['rate_2026_01_02']) : '';

if($editingId <= 0){
$err = 'Invalid row selected.';
} else {
$upd = $conn->prepare("UPDATE image_processed_rates SET sr_no=?, station_english=?, station_urdu=?, rate_2026_01_01=?, rate_2026_01_02=? WHERE id=?");
$upd->bind_param("sssssi", $srNo, $stationEnglish, $stationUrdu, $rate1, $rate2, $editingId);
$upd->execute();
$upd->close();
$msg = 'Rate row updated.';
$editingId = 0;
}
}

if(isset($_GET['edit_id']) && !isset($_POST['update_rate'])){
$editingId = (int)$_GET['edit_id'];
}

$rows = $conn->query("SELECT id, source_file, source_image_path, sr_no, station_english, station_urdu, rate_2026_01_01, rate_2026_01_02, created_at FROM image_processed_rates ORDER BY id DESC");
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
.icon-edit{
display:inline-flex;
width:28px;
height:28px;
align-items:center;
justify-content:center;
background:#111;
color:#fff;
text-decoration:none;
border-radius:6px;
font-size:14px;
}
.edit-row input{
width:100%;
max-width:none;
margin:0;
padding:8px;
border:1px solid #ccc;
border-radius:6px;
}
.save-btn{
padding:8px 10px;
background:#2e7d32;
color:#fff;
border:none;
border-radius:6px;
cursor:pointer;
}
.cancel-btn{
padding:8px 10px;
background:#eee;
color:#111;
text-decoration:none;
border-radius:6px;
display:inline-block;
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
<?php if($msg!=""){ ?><div style="color:green;margin-top:8px;"><?php echo htmlspecialchars($msg); ?></div><?php } ?>
<?php if($err!=""){ ?><div style="color:#b71c1c;margin-top:8px;"><?php echo htmlspecialchars($err); ?></div><?php } ?>
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
<th>Action</th>
</tr>
<?php while($r = $rows->fetch_assoc()){ ?>
<?php if($editingId === (int)$r['id']){ ?>
<tr class="edit-row">
<form method="post">
<td>
<?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''){ ?>
<a class="icon-link" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="<?php echo htmlspecialchars($r['source_file']); ?>" aria-label="View Source Image">&#128065;</a>
<?php } else { ?>
-
<?php } ?>
</td>
<td><input type="text" name="sr_no" value="<?php echo htmlspecialchars($r['sr_no']); ?>"></td>
<td><input type="text" name="station_english" value="<?php echo htmlspecialchars($r['station_english']); ?>"></td>
<td><input type="text" name="station_urdu" value="<?php echo htmlspecialchars($r['station_urdu']); ?>"></td>
<td><input type="text" name="rate_2026_01_01" value="<?php echo htmlspecialchars($r['rate_2026_01_01']); ?>"></td>
<td><input type="text" name="rate_2026_01_02" value="<?php echo htmlspecialchars($r['rate_2026_01_02']); ?>"></td>
<td><?php echo htmlspecialchars($r['created_at']); ?></td>
<td>
<input type="hidden" name="edit_id" value="<?php echo (int)$r['id']; ?>">
<button class="save-btn" type="submit" name="update_rate">Save</button>
<a class="cancel-btn" href="rate_list.php">Cancel</a>
</td>
</form>
</tr>
<?php } else { ?>
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
<td><a class="icon-edit" href="rate_list.php?edit_id=<?php echo (int)$r['id']; ?>" title="Edit" aria-label="Edit">&#9998;</a></td>
</tr>
<?php } ?>
<?php } ?>
</table>
</body>
</html>
