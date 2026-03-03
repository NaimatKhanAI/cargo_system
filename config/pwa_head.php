<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0f76d8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Cargo System">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<script defer src="assets/pwa.js"></script>
<style>
  .dev-backlink {
    position: fixed;
    right: 12px;
    bottom: 10px;
    z-index: 9999;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.7);
    color: #f5f5f5;
    font: 600 11px/1.2 Arial, sans-serif;
    text-decoration: none;
    letter-spacing: 0.2px;
    backdrop-filter: blur(3px);
  }
  .dev-backlink:hover {
    background: rgba(0, 0, 0, 0.85);
    color: #ffffff;
  }
</style>
<script>
  (function () {
    function injectBacklink() {
      if (!document.body) return;
      if (document.getElementById('dev_backlink_jawadahmadcs')) return;
      var a = document.createElement('a');
      a.id = 'dev_backlink_jawadahmadcs';
      a.className = 'dev-backlink';
      a.href = 'https://jawadahmadcs.com/';
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = 'Developer: JawadAhmadCS';
      document.body.appendChild(a);
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', injectBacklink);
    } else {
      injectBacklink();
    }
  })();
</script>
