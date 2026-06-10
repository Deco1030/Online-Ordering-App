/*
  OnTrackX Landing Page JavaScript
  - Smooth scrolling already handled by CSS.
  - Quick tracking stub: validates input and routes to tracking page later.
*/

(function () {
  const trackingBtn = window.trackNow;

  // Global function called by inline onclick.
  window.trackNow = function () {
    const el = document.getElementById('trackingNumber');
    const hint = document.getElementById('trackingHint');
    if (!el) return;

    const value = (el.value || '').trim();
    if (!hint) return;

    if (!value) {
      hint.textContent = 'Please enter a tracking number.';
      hint.style.color = '#ffd36a';
      return;
    }

    // Placeholder behavior until backend tracking page is implemented.
    // We just show a message; later you can redirect to: /?page=client_track&tn=...
    hint.textContent = 'Tracking request received for: ' + value + ' (demo).';
    hint.style.color = '#a7f3d0';

    // Example: Uncomment when client tracking page exists.
    // const url = (window.location.pathname.replace(/\/$/, '') + '/?page=client_track&tn=' + encodeURIComponent(value));
    // window.location.href = url;
  };
})();

