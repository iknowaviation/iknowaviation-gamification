/**
 * Flight Deck – Jump To sticky + scrollspy (v2)
 *
 * Goals:
 *  - Make the Jump To nav "stick" reliably even when Elementor ancestors have overflow/transform quirks.
 *    (We use a fixed-position fallback via .is-stuck.)
 *  - Highlight the active section link as you scroll (.is-active).
 *  - Smooth-scroll with correct offset (so anchors don't hide under the sticky nav/header).
 *
 * Markup expectations:
 *  - Nav: .ika-fd-jumpto
 *  - Links: .ika-fd-jumpto__links a[href^="#"]
 *  - Sections: elements with IDs that match link hrefs (e.g. #fd-missions)
 */
(function() {
  function ready(fn) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", fn);
    else fn();
  }

  function getAdminBarHeight() {
    var bar = document.getElementById("wpadminbar");
    if (!bar) return 0;
    var r = bar.getBoundingClientRect();
    return Math.max(0, Math.round(r.height || 0));
  }

  function getHeaderOffset() {
    // Try to account for sticky headers. We keep it conservative.
    var admin = getAdminBarHeight();
    // If user sets CSS variable, respect it.
    var cssTop = 0;
    try {
      var v = getComputedStyle(document.documentElement).getPropertyValue("--ika-fd-jumpto-top").trim();
      if (v) cssTop = parseInt(v, 10) || 0;
    } catch(e) {}
    // Default gap below admin bar / header.
    return admin + (cssTop || 12);
  }

  function getDocTop(el) {
    var y = window.scrollY || window.pageYOffset || 0;
    return el.getBoundingClientRect().top + y;
  }

  function setActive(linksById, activeId) {
    if (!activeId) return;
    Object.keys(linksById).forEach(function(id) {
      linksById[id].classList.toggle("is-active", id === activeId);
    });
  }

  ready(function() {
    var nav = document.querySelector(".ika-fd-jumpto");
    if (!nav) return;

    var links = Array.prototype.slice.call(
      nav.querySelectorAll(".ika-fd-jumpto__links a[href^='#']")
    );
    if (!links.length) return;

    // Build mapping href->section.
    var sections = [];
    var linksById = {};
    links.forEach(function(a) {
      var href = a.getAttribute("href") || "";
      if (!href || href.charAt(0) !== "#") return;
      var id = href.slice(1);
      var sec = document.getElementById(id);
      if (!sec) return;
      sections.push(sec);
      linksById[id] = a;
    });

    if (!sections.length) return;

    // Sort by document position.
    sections.sort(function(a, b) {
      return getDocTop(a) - getDocTop(b);
    });

    // Spacer to prevent layout jump when nav becomes fixed.
    var spacer = document.createElement("div");
    spacer.className = "ika-fd-jumpto-spacer";
    spacer.style.display = "none";
    nav.parentNode.insertBefore(spacer, nav.nextSibling);

    var navStartTop = getDocTop(nav);
    var ticking = false;

    function update() {
      ticking = false;

      var y = window.scrollY || window.pageYOffset || 0;
      var topOffset = getHeaderOffset();
      var navH = nav.getBoundingClientRect().height || 0;

      // ---- Sticky (fixed fallback) ----
      // When the scroll position reaches the nav's original top, lock it.
      if (y + topOffset >= navStartTop) {
        if (!nav.classList.contains("is-stuck")) {
          nav.classList.add("is-stuck");
          spacer.style.display = "block";
          spacer.style.height = Math.round(navH) + "px";
        } else {
          spacer.style.height = Math.round(navH) + "px";
        }
        // Write top offset as CSS variable so CSS can position it.
        nav.style.setProperty("--ika-fd-jumpto-top-runtime", topOffset + "px");
      } else {
        if (nav.classList.contains("is-stuck")) {
          nav.classList.remove("is-stuck");
          spacer.style.display = "none";
          spacer.style.height = "0px";
        }
        nav.style.removeProperty("--ika-fd-jumpto-top-runtime");
      }

      // ---- Scrollspy ----
      // Determine the current section based on a "reading line" below the sticky nav.
      var probeY = y + topOffset + navH + 18;
      var activeId = sections[0].id;

      for (var i = 0; i < sections.length; i++) {
        var sTop = getDocTop(sections[i]);
        if (sTop <= probeY) activeId = sections[i].id;
      }

      setActive(linksById, activeId);
    }

    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(update);
    }

    // Smooth scroll with correct offset.
    nav.addEventListener("click", function(e) {
      var a = e.target.closest && e.target.closest("a[href^='#']");
      if (!a) return;

      var href = a.getAttribute("href") || "";
      if (!href || href.charAt(0) !== "#") return;

      var id = href.slice(1);
      var sec = document.getElementById(id);
      if (!sec) return;

      e.preventDefault();

      var y = window.scrollY || window.pageYOffset || 0;
      var topOffset = getHeaderOffset();
      var navH = nav.getBoundingClientRect().height || 0;
      var target = getDocTop(sec) - topOffset - navH - 14;

      window.scrollTo({ top: Math.max(0, Math.round(target)), behavior: "smooth" });

      // Update immediately for responsiveness.
      setActive(linksById, id);
    });

    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", function() {
      navStartTop = getDocTop(nav);
      onScroll();
    });

    // Debug line (useful while we stabilize; harmless otherwise)
    try { console.log("IKA JumpTo: scrollspy v2 initialized"); } catch(e) {}

    // Initial
    update();
  });
})();
