(function () {
  if (!("serviceWorker" in navigator)) return;

  window.addEventListener("load", function () {
    navigator.serviceWorker
      .register("sw.js")
      .then(function (reg) {
        reg.update();
      })
      .catch(function () {
        // Silent fail to avoid interrupting app flow.
      });
  });
})();
