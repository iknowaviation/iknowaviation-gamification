/**
 * Flight Deck – Flight Log filter
 *
 * Filters the Flight Log table by:
 *  - all
 *  - completed
 *  - in_progress
 *
 * Markup:
 *  - select.ika-fd-flightlog-filter
 *  - table rows: tr[data-status="completed|in_progress"]
 */
(function () {
  function ready(fn) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", fn);
    else fn();
  }

  ready(function () {
    var select = document.querySelector(".ika-fd-flightlog-filter");
    if (!select) return;

    var table = document.querySelector(".ika-fd-flightlog-table");
    if (!table) return;

    var rows = Array.prototype.slice.call(table.querySelectorAll("tbody tr[data-status]"));
    if (!rows.length) return;

    function applyFilter(val) {
      rows.forEach(function (tr) {
        var s = (tr.getAttribute("data-status") || "").toLowerCase();
        var show = (val === "all") ? true : (s === val);
        tr.style.display = show ? "" : "none";
      });
    }

    // Initial
    applyFilter((select.value || "all").toLowerCase());

    select.addEventListener("change", function () {
      applyFilter((select.value || "all").toLowerCase());
    });
  });
})();
