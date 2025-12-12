# Staging Checklist — iKnowAviation Gamification

Run this checklist before pushing staging → production (files only).

## A) Basic safety
- [ ] Site loads (no critical errors)
- [ ] WP Admin → Plugins page shows **IKA Version** correctly
- [ ] No new admin warnings/notices related to IKA (unless expected)

## B) Quiz → XP
- [ ] Take a WATU PRO quiz while logged in
- [ ] Confirm quiz completes normally
- [ ] Confirm XP increases (Flight Deck / shortcode / wherever you display XP)
- [ ] Confirm no duplicate XP from refreshing results page (if you track that)

## C) Rank behavior
- [ ] Confirm rank title shortcode displays correctly
- [ ] If near a threshold, confirm rank updates at the correct XP boundary
- [ ] Confirm any rank UI (rank card / progress bar) matches expected values

## D) WATU Play (badges + levels)
- [ ] Earn a badge (or re-run a test quiz that awards one)
- [ ] Confirm modal appears (if enabled)
- [ ] Confirm badge images load (no broken icons)
- [ ] Confirm level icon loads (if applicable)
- [ ] Confirm avatar sync works if enabled (UsersWP avatar → level icon)

## E) Flight Deck / Profile UI
- [ ] Flight Deck page loads cleanly (no overlap, spacing OK)
- [ ] Metrics strip: labels do not overlap values
- [ ] Leaderboard renders and aligns correctly
- [ ] “Me” row highlights properly (if implemented)

## F) Logged-out behavior
- [ ] Logged-out user does not see protected data/actions
- [ ] No console errors from missing user context

## G) Performance sanity
- [ ] Page loads feel normal (no new long delays)
- [ ] No repeated AJAX calls or runaway requests in DevTools

## H) Final step
- [ ] Push staging → production (**files only**)
- [ ] Quick spot-check production: one quiz completion + Flight Deck
