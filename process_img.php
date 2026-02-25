<?php
session_start();
if(!isset($_SESSION['user'])){
    header("location:index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Image Processing</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #c084fc; --green: #22c55e; --text: #e8eaf0; --muted: #7c8091;
    --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; display: flex; flex-direction: column; }

  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 32px; border-bottom: 1px solid var(--border); background: var(--surface); }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-btn { padding: 8px 16px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }

  .main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 24px; }

  .upload-card { background: var(--surface); border: 1px solid var(--border); padding: 40px; width: min(560px, 100%); position: relative; overflow: hidden; }
  .upload-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent); }

  .card-title { font-size: 26px; font-weight: 800; letter-spacing: -0.8px; margin-bottom: 8px; }
  .card-desc { font-size: 14px; color: var(--muted); line-height: 1.6; margin-bottom: 32px; }

  .drop-zone {
    border: 2px dashed var(--border); padding: 32px 20px;
    text-align: center; margin-bottom: 20px; transition: all 0.2s; cursor: pointer;
    position: relative;
  }
  .drop-zone:hover, .drop-zone.dragover { border-color: var(--accent); background: rgba(192,132,252,0.04); }
  .drop-icon { font-size: 36px; margin-bottom: 12px; opacity: 0.6; }
  .drop-title { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
  .drop-hint { font-size: 12px; color: var(--muted); font-family: var(--mono); }
  .drop-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }

  .file-preview { display: none; margin-bottom: 16px; }
  .file-preview.show { display: block; }
  .file-list { list-style: none; }
  .file-list li { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: var(--surface2); border: 1px solid var(--border); margin-bottom: 4px; font-size: 12px; font-family: var(--mono); }
  .file-list li::before { content: '▸'; color: var(--accent); }

  .submit-btn {
    width: 100%; padding: 14px; background: var(--accent); color: #0e0f11;
    border: none; cursor: pointer; font-family: var(--font); font-size: 15px;
    font-weight: 800; letter-spacing: 0.3px; transition: background 0.15s;
  }
  .submit-btn:hover { background: #a855f7; color: #fff; }
  .formats { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; justify-content: center; }
  .fmt-tag { padding: 4px 10px; background: var(--surface2); border: 1px solid var(--border); font-family: var(--mono); font-size: 11px; color: var(--muted); }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Image</span>
    <h1>Image Processing</h1>
  </div>
  <a class="nav-btn" href="dashboard.php">Back to Dashboard</a>
</div>

<div class="main">
  <div class="upload-card">
    <div class="card-title">Image → Excel</div>
    <div class="card-desc">Upload a rate table image and extract it into an Excel-ready CSV file. Multiple images supported.</div>

    <form action="process.php" method="post" enctype="multipart/form-data" id="upload_form">
      <div class="drop-zone" id="drop_zone">
        <div class="drop-icon">&#128247;</div>
        <div class="drop-title">Drop images here or click to browse</div>
        <div class="drop-hint">PNG · JPG · JPEG · WEBP · Multiple files OK</div>
        <input type="file" name="image[]" accept=".png,.jpg,.jpeg,.webp" multiple required id="file_input">
      </div>

      <div class="file-preview" id="file_preview">
        <ul class="file-list" id="file_list"></ul>
      </div>

      <button class="submit-btn" type="submit">Convert to Excel</button>
    </form>

    <div class="formats">
      <span class="fmt-tag">PNG</span>
      <span class="fmt-tag">JPG</span>
      <span class="fmt-tag">JPEG</span>
      <span class="fmt-tag">WEBP</span>
    </div>
  </div>
</div>

<script>
(function(){
  var input = document.getElementById('file_input');
  var preview = document.getElementById('file_preview');
  var list = document.getElementById('file_list');
  var zone = document.getElementById('drop_zone');

  function updatePreview(files){
    list.innerHTML = '';
    if(!files || files.length === 0){ preview.classList.remove('show'); return; }
    for(var i = 0; i < files.length; i++){
      var li = document.createElement('li');
      li.textContent = files[i].name + ' (' + (files[i].size / 1024).toFixed(1) + ' KB)';
      list.appendChild(li);
    }
    preview.classList.add('show');
  }

  input.addEventListener('change', function(){ updatePreview(this.files); });
  zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', function(){ zone.classList.remove('dragover'); });
  zone.addEventListener('drop', function(e){ e.preventDefault(); zone.classList.remove('dragover'); });
})();
</script>
</body>
</html>
