# Architecture: XP → Rank → Badges/Levels

This diagram shows how quiz completion drives XP, rank titles, and WATU Play badges/levels.

```mermaid
flowchart TD
  A[User completes WATU PRO quiz] --> B{Is user logged in?}
  B -- No --> B1[Exit: no XP/rank/badge updates]
  B -- Yes --> C[WATU PRO calculates score/points]

  C --> D[Gamification hook runs on quiz completion]
  D --> E[Read awarded points from WATU PRO result]
  E --> F[Add points to user's XP total (user_meta)]

  F --> G[Compute current Rank from XP ladder]
  G --> H{Did rank change?}
  H -- No --> H1[Keep current rank title/meta]
  H -- Yes --> I[Update stored rank (user_meta)]
  I --> J[Optional: trigger Rank-up UI/modal]

  C --> K[WATU Play evaluates conditions]
  K --> L{New badges earned?}
  L -- Yes --> M[Store earned badge IDs / timestamps]
  L -- No --> N[No badge changes]

  K --> O{New level achieved?}
  O -- Yes --> P[Store new level + level icon URL]
  O -- No --> Q[No level changes]

  P --> R[Optional: set UsersWP avatar to level icon]
  M --> S[Prepare modal payload: badges + level]
  R --> S
  J --> S

  S --> T[Show "Achievement Unlocked" modal]
  T --> U[User continues → Flight Deck / next quiz]

  %% Side outputs
  F --> V[Leaderboard reads XP totals]
  G --> W[Shortcode outputs rank title]

### Notes (keep right below the diagram)
- **XP** is your “single source” for progression. Everything else derives from it.
- **Rank** is computed from XP thresholds (ladder). You can store the current rank for quick access, but always be able to recompute.
- **Badges/Levels** are owned by **WATU Play** (rules & awarding), while your plugin focuses on:
  - reading “what changed”
  - storing lightweight history/meta (optional)
  - showing UI (modal + Flight Deck sections)
  - optionally syncing avatar with the level icon