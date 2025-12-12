# iKnowAviation – Gamification Plugin

A custom WordPress gamification engine powering quizzes, XP, ranks, badges, leaderboards, and user progression for **iKnowAviation**.

This plugin integrates with:
- **WATU PRO** (quiz engine)
- **WATU Play** (badges & levels)
- **UsersWP** (profiles, avatars, account data)
- Custom UI (Flight Deck, leaderboards, modals)

The goal of this plugin is to provide a clean, extensible gamification layer without bloated third-party gamification frameworks.

---

## 🧠 Core Features

- XP tracking from quiz completions
- Rank ladder with human-readable titles
- Badge + level handling via WATU Play
- Custom achievement modal styling
- User profile (“Flight Deck”) integration
- Leaderboards (XP-based)
- Shortcodes for rank titles, XP, progress
- Clean separation of logic (plugin) and UI (CSS)

---

## 📁 Repository Structure

# iKnowAviation – Gamification Plugin

A custom WordPress gamification engine powering quizzes, XP, ranks, badges, leaderboards, and user progression for **iKnowAviation**.

This plugin integrates with:
- **WATU PRO** (quiz engine)
- **WATU Play** (badges & levels)
- **UsersWP** (profiles, avatars, account data)
- Custom UI (Flight Deck, leaderboards, modals)

The goal of this plugin is to provide a clean, extensible gamification layer without bloated third-party gamification frameworks.

---

## 🧠 Core Features

- XP tracking from quiz completions
- Rank ladder with human-readable titles
- Badge + level handling via WATU Play
- Custom achievement modal styling
- User profile (“Flight Deck”) integration
- Leaderboards (XP-based)
- Shortcodes for rank titles, XP, progress
- Clean separation of logic (plugin) and UI (CSS)

---

Only the contents of `/plugin` are installed in WordPress.

---

## 🚀 Deployment Overview

**Source of truth:** GitHub  
**Deployment target:** WordPress plugin directory

Production / staging path:

wp-content/plugins/iknowaviation-gamification/

Deployment flow:
1. Make changes in GitHub
2. Build ZIP from `/plugin` contents
3. Upload to **staging**
4. Test using checklist
5. Push staging → production (**files only**)
6. Tag a release in GitHub

⚠️ Do not hot-edit plugin files directly on production.

---

## 🎨 UI & CSS Responsibilities

This plugin **does not own global UI CSS**.

CSS responsibilities:
- **Global UI:** `ika_master.css` (loaded via UI loader plugin)
- **Quiz UI:** `ika_quiz.css` (loaded via WATU quiz theme)
- **Flight Deck / profile UI:** scoped styles inside `ika_master.css`

The plugin:
- Outputs markup
- Adds classes
- Triggers modals
- Does not embed large CSS blocks

---

## 🧩 Dependencies

Required:
- WordPress 6.x+
- WATU PRO
- WATU Play
- UsersWP

Recommended:
- Staging environment
- Cloudflare (CDN)
- GitHub Desktop or GitHub Web UI

---

## 🏷️ Versioning

- Semantic-ish versioning (ex: `0.9.2`)
- Versions are tagged in GitHub
- Changelog maintained in `CHANGELOG.md`

---

## 🛠️ Development Principles

- No hot edits on production
- Logic stays in PHP, visuals in CSS
- Prefer explicit hooks over global filters
- Avoid overloading WATU internals
- Keep everything reversible

---

## 📌 Maintainer

**iKnowAviation**  
Custom development by internal team  
Not intended for public distribution
