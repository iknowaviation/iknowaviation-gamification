# iKnowAviation – Gamification Plugin CSS Architecture

_Last updated: 2025-12-28_

This document describes the **production CSS structure** for the iKnowAviation WordPress gamification plugin after the Phase 1 cleanup and hardening pass.

---

## Overview

All quiz, results, modal, and Flight Deck styling now lives **inside the plugin**, with **clear responsibility boundaries** and **conditional enqueues** to avoid bleed, duplication, or performance issues.

This file is the source-of-truth reference for:
- What each CSS file does
- Where it is enqueued
- Why it exists

---

## CSS Files & Responsibilities

### 1. `assets/css/ika_master.css`

**Purpose**
- Site-wide base UI for the plugin
- Shared layout tokens, typography, colors
- Quiz Hub / Archive UI (quiz cards, status chips, rails)

**Enqueue**
- Always enqueued on every front-end page

**Notes**
- Does NOT contain Flight Deck or quiz single styling
- Should remain stable and low-churn

---

### 2. `assets/css/ika_watupro_quiz.css`

**Purpose**
- WatuPRO **single quiz page** theme:
  - Quiz page wrapper
  - Hero styling (quiz pages only)
  - Question blocks
  - Answer pill styling
  - Buttons and mobile behavior

**Enqueue**
- Only on quiz single pages:
  - `is_singular('quiz')`

**Dependency Order**
- Loaded after `ika_master.css`

---

### 3. `assets/css/ika_watupro_results.css`

**Purpose**
- WatuPRO **results / final screen** styling:
  - Correct vs selected answer highlighting
  - Results layout alignment
  - Cleanup fixes (blank paragraphs, top spacing)
  - "Take another quiz" CTA styling

**Enqueue**
- Only on quiz single pages:
  - `is_singular('quiz')`

**Dependency Order**
- Loaded after `ika_watupro_quiz.css`

---

### 4. `assets/css/ika_watuproplay_modal.css`

**Purpose**
- Watu Play **achievement / level-up modal** styling:
  - jQuery UI dialog theme
  - Modal header + close button
  - Badge list layout
  - Gold ring / emphasis styles

**Enqueue**
- Only on quiz single pages:
  - `is_singular('quiz')`

**Notes**
- Intentionally isolated to avoid quiz CSS regressions

---

### 5. `assets/css/ika_flightdeck.css`

**Purpose**
- Flight Deck / Profile Hub styling only:
  - Profile hero
  - Metrics strip
  - Cards grid
  - Leaderboard
  - Avatar level ring + badge positioning

**Enqueue**
- Conditionally via helper `ika_gam_is_flightdeck_page()`:
  - Direct match: `is_page('flight-deck')`
  - Fallback: searches for wrapper markers in:
    - `$post->post_content`
    - Elementor `_elementor_data`

**Wrapper Markers**
- `ika-scope-flightdeck`
- `ika-profile-hub`
- `ika-hub-hero`

---

### 6. `assets/css/ika_quiz.DEPRECATED.css`

**Purpose**
- Rollback / historical reference only

**Enqueue**
- Not enqueued anywhere

---

## Enqueue Logic (Source of Truth)

All enqueues are defined in:

```
includes/frontend-deps.php
```

### Always Loaded
- `ika_master.css`

### Quiz Single (`is_singular('quiz')`)
- `ika_watupro_quiz.css`
- `ika_watupro_results.css`
- `ika_watuproplay_modal.css`

### Flight Deck Only
- `ika_flightdeck.css`

### Logged-In Users Only
- jQuery UI Dialog (required for modal behavior)

---

## Design Principles (Why This Structure Exists)

- **Single responsibility per file**
- **Conditional loading for performance**
- **Scoped CSS to prevent bleed**
- **Plugin-owned styling (no theme dependency)**
- **Future-ready for Flight Deck sub-pages and new quiz types**

---

## Future Notes

- New Flight Deck sub-pages should reuse:
  - `.ika-scope-flightdeck`
  - `.ika-profile-*` patterns
- Additional UI areas should follow the same extraction pattern:
  - Identify scope
  - Create dedicated CSS file
  - Conditionally enqueue

---

_End of document_
