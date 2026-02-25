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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Image to Excel</title>
  <style>
    :root{
      --bg:#f6f7fb;
      --card:#ffffff;
      --text:#111827;
      --muted:#6b7280;
      --accent:#2563eb;
      --accent-dark:#1e40af;
      --border:#e5e7eb;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:"Trebuchet MS", Arial, sans-serif;
      background: radial-gradient(1200px 600px at 10% -10%, #e0e7ff 0%, var(--bg) 45%);
      color:var(--text);
    }
    .top{
      max-width:960px;
      margin:18px auto 0;
      padding:0 16px;
    }
    .back{
      display:inline-block;
      text-decoration:none;
      color:#111827;
      border:1px solid var(--border);
      background:#fff;
      border-radius:10px;
      padding:10px 14px;
      font-weight:600;
    }
    .wrap{
      min-height:calc(100vh - 70px);
      display:flex;
      align-items:center;
      justify-content:center;
      padding:20px 16px 32px;
    }
    .card{
      background:var(--card);
      border:1px solid var(--border);
      box-shadow:0 12px 40px rgba(17,24,39,0.08);
      border-radius:18px;
      width:min(560px, 100%);
      padding:28px;
    }
    h1{
      margin:0 0 8px 0;
      font-size:26px;
      letter-spacing:0.2px;
    }
    p{
      margin:0 0 18px 0;
      color:var(--muted);
      font-size:14px;
      line-height:1.5;
    }
    .drop{
      border:2px dashed var(--border);
      border-radius:14px;
      padding:18px;
      text-align:center;
      background:#fafafa;
      margin-bottom:16px;
    }
    .drop input[type="file"]{
      display:block;
      margin:10px auto 0 auto;
    }
    .actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }
    button{
      background:var(--accent);
      color:white;
      border:none;
      border-radius:10px;
      padding:12px 18px;
      font-size:15px;
      cursor:pointer;
    }
    button:hover{background:var(--accent-dark);}
    .hint{
      font-size:12px;
      color:var(--muted);
      margin-top:8px;
    }
  </style>
</head>
<body>
  <div class="top">
    <a class="back" href="feed.php">Back to Feed</a>
  </div>
  <div class="wrap">
    <div class="card">
      <h1>Upload Image -> Excel</h1>
      <p>Upload a table image and get an Excel-ready CSV file in seconds.</p>

      <form action="process.php" method="post" enctype="multipart/form-data">
        <div class="drop">
          <div><strong>Select an image file</strong></div>
          <input type="file" name="image[]" accept=".png,.jpg,.jpeg,.webp" multiple required>
          <div class="hint">You can select multiple images. Supported: PNG, JPG, JPEG, WEBP</div>
        </div>
        <div class="actions">
          <button type="submit">Convert to Excel</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>

