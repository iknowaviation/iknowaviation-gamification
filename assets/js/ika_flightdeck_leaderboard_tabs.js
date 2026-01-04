(function () {
  function initLeaderboard(root) {
    var tabs = root.querySelectorAll('.ika-fd-tab[data-mode]');
    var panels = root.querySelectorAll('.ika-fd-leaderboard-panel[data-mode]');
    var label = root.querySelector('[data-ika-fd-leaderboard-label]');

    if (!tabs.length || !panels.length) return;

    function setMode(mode) {
      // Tabs
      tabs.forEach(function (btn) {
        var isActive = btn.getAttribute('data-mode') === mode;
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });

      // Panels
      panels.forEach(function (p) {
        var show = p.getAttribute('data-mode') === mode;
        if (show) p.removeAttribute('hidden');
        else p.setAttribute('hidden', '');
      });

      if (label) {
        label.textContent = (mode === 'all') ? 'All-time rankings' : 'This week’s rankings';
      }
    }

    // Click binding
    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setMode(btn.getAttribute('data-mode'));
      });
    });

    // Ensure initial state matches markup
    var active = root.querySelector('.ika-fd-tab.is-active[data-mode]');
    setMode(active ? active.getAttribute('data-mode') : 'week');
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-ika-fd-leaderboard]').forEach(initLeaderboard);
  });
})();