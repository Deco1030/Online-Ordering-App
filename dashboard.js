/* Client Dashboard JavaScript */

(function () {
  const topbar = document.getElementById('clientTopbar');
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');

  if (sidebar && toggle) {
    const key = 'otx_sidebar_collapsed';

    const apply = (collapsed) => {
      sidebar.classList.toggle('is-collapsed', !!collapsed);
      try {
        localStorage.setItem(key, collapsed ? '1' : '0');
      } catch (e) {
        // ignore
      }
    };

    let initial = false;
    try {
      initial = localStorage.getItem(key) === '1';
    } catch (e) {
      initial = false;
    }

    apply(initial);

    toggle.addEventListener('click', () => {
      const now = !sidebar.classList.contains('is-collapsed');
      apply(now);
    });
  }

  // Notifications dropdown UX (optional)
  const notifBtn = document.querySelector('[aria-label="Notifications"]');
  if (notifBtn) {
    notifBtn.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' || ev.key === ' ') {
        // let bootstrap handle click
      }
    });
  }
})();

