<?php $today = date('Y-m-d'); ?>
<!DOCTYPE html>
<html>
<head>
<title>Add Bilty</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/style.css">
<style>
.page-wrap{
max-width:900px;
margin:25px auto;
}
.form-card{
background:#fff;
border:1px solid #ddd;
border-radius:10px;
padding:20px;
box-shadow:0 10px 24px rgba(0,0,0,0.06);
}
.form-head{
display:flex;
justify-content:space-between;
align-items:center;
gap:10px;
margin-bottom:15px;
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
.grid{
display:grid;
grid-template-columns:repeat(2, minmax(220px, 1fr));
gap:12px 16px;
}
.field label{
display:block;
margin:0 0 6px;
font-size:14px;
color:#333;
font-weight:600;
}
.field input{
max-width:none;
margin:0;
border:1px solid #cfcfcf;
border-radius:8px;
background:#fafafa;
}
.actions{
margin-top:16px;
display:flex;
justify-content:flex-end;
}
.actions button{
max-width:180px;
border-radius:8px;
font-weight:700;
}
@media(max-width:700px){
.grid{
grid-template-columns:1fr;
}
.form-head{
align-items:flex-start;
flex-direction:column;
}
.actions{
justify-content:stretch;
}
.actions button{
max-width:none;
}
}
</style>
</head>
<body>
<div class="page-wrap">
<div class="form-card">
<div class="form-head">
<h2>Add Bilty</h2>
<a class="back-link" href="dashboard.php">Back to Dashboard</a>
</div>

<form action="save_bilty.php" method="post">
<div class="grid">
<div class="field">
<label for="sr_no">SR No</label>
<input id="sr_no" name="sr_no" placeholder="Enter SR no" required>
</div>
<div class="field">
<label for="date">Date</label>
<input id="date" type="date" name="date" value="<?php echo $today; ?>" required>
</div>
<div class="field">
<label for="vehicle">Vehicle</label>
<input id="vehicle" name="vehicle" placeholder="Vehicle number" required>
</div>
<div class="field">
<label for="bilty">Bilty No</label>
<input id="bilty" name="bilty" placeholder="Bilty number" required>
</div>
<div class="field">
<label for="party">Party</label>
<input id="party" name="party" placeholder="Party name">
</div>
<div class="field">
<label for="location">Location</label>
<input id="location" name="location" placeholder="Pickup / drop location" required>
</div>
<div class="field">
<label for="freight">Freight</label>
<input id="freight" type="number" name="freight" placeholder="Freight amount" min="0" required>
</div>
<div class="field">
<label for="tender">Tender</label>
<input id="tender" type="number" name="tender" placeholder="Tender amount" min="0" required>
</div>
</div>

<div class="actions">
<button type="submit">Save Bilty</button>
</div>
</form>
</div>
</div>
</body>
</html>
