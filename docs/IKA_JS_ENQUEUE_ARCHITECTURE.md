# iKnowAviation – Gamification Plugin JS & Enqueue Architecture

_Last updated: 2025-12-28_

This document describes the **production JS + enqueue structure** for the iKnowAviation WordPress gamification plugin after the Phase 1 hardening pass.

It is intended to be committed alongside the plugin so future changes remain traceable.

---

## Source of Truth

Front-end enqueues are defined in:

```
includes/frontend-deps.php
```

This file controls:
- Which CSS files load and where
- Which JS libraries are loaded (currently jQuery UI Dialog)
- Cache-busting versions (via `filemtime()`)

---

## Enqueue Design Principles

- **Only load what’s needed** (conditional enqueues)
- **Scope by page type** (quiz singles vs Flight Deck vs global)
- **Avoid theme dependencies** (plugin owns UI assets)
- **Cache-bust correctly** (use file modification timestamps)

---

## Helper Functions

### `ika_gam_asset_ver( string $rel_path ): string`

**Purpose**
- Returns a version string for enqueue cache-busting.

**Implementation**
- Uses `filemtime()` of the file in the plugin directory.
- Falls back to `IKA_GAM_PLUGIN_VERSION` if filemtime is unavailable.

**Why**
- Ensures users receive updated CSS/JS immediately after deployments.
- Avoids manual version bumps just to clear caches.

---

### `ika_gam_is_quiz_single(): bool`

**Purpose**
- Detects single quiz CPT pages.

**Logic**
- `is_singular('quiz')`

**Used for**
- Enqueuing WatuPRO quiz + results CSS
- Enqueuing Watu Play modal CSS

---

### `ika_gam_is_flightdeck_page(): bool`

**Purpose**
- Detects the Flight Deck page reliably, including Elementor-built layouts.

**Logic**
1) **Direct match**
   - `is_page('flight-deck')`
2) **Fallback marker search** (for future-proofing and sub-pages)
   - Searches both:
     - `$post->post_content`
     - Elementor `_elementor_data` post meta
   - Looks for wrapper markers:
     - `ika-scope-flightdeck`
     - `ika-profile-hub`
     - `ika-hub-hero`

**Why**
- Elementor often stores layout content in `_elementor_data` instead of `post_content`,
  so checking only `post_content` can fail to detect markup markers.

---

## Enqueued Assets (Current)

### Always Enqueued (All Front-End Pages)

- **CSS**
  - `assets/css/ika_master.css`

---

### Quiz Single Pages (`is_singular('quiz')`)

- **CSS**
  - `assets/css/ika_watupro_quiz.css`
  - `assets/css/ika_watupro_results.css` (loaded after quiz CSS)
  - `assets/css/ika_watuproplay_modal.css`

- **JS**
  - None plugin-specific at the moment (beyond core WP dependencies)

---

### Flight Deck Page (`ika_gam_is_flightdeck_page()`)

- **CSS**
  - `assets/css/ika_flightdeck.css`

- **JS**
  - None plugin-specific at the moment (beyond core WP dependencies)

---

### Logged-In Users (Any Page)

- **JS**
  - `jquery-ui-dialog`
- **CSS**
  - `wp-jquery-ui-dialog`

**Why**
- Supports jQuery UI dialog modals used by Watu Play modal functionality.

---

## Operational Notes

### Cache/Deployment
After changing CSS/JS:
- `filemtime()` will automatically bump versions.
- If using server/CDN caching, still perform a **hard refresh** once after deploy.

### Debugging “Asset Not Loading”
If a scoped file does not load:
1) Confirm the enqueue condition evaluates true (page detection)
2) Confirm the file exists at the expected plugin path
3) DevTools → Network → filter by “css” or “js”
4) Check for any minification/optimization plugin rewriting asset URLs

---

## When to Update This Document

Update this file whenever you:
- Add/remove/enqueue any new CSS or JS file
- Change page detection logic (`is_page` slug, marker detection, CPT slug)
- Introduce new interactive UI requiring new scripts
- Add Flight Deck sub-pages that need new conditional assets

---

## Future Expansion (Planned)

- If/when we add plugin JS (Flight Deck interactivity, AJAX widgets, dashboard rails):
  - Create `assets/js/ika_flightdeck.js` and enqueue only on Flight Deck pages.
  - Use `defer` where appropriate and keep dependencies explicit.
  - Keep scripts modular and scoped (avoid global selectors).

---

_End of document_
