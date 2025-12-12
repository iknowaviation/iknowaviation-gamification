# iKnowAviation Gamification – Troubleshooting Guide

This document covers common issues encountered when developing or deploying the gamification plugin.

---

## 🟥 Plugin Causes a Critical Error on Activation

### Symptoms
- White screen
- “Critical error on this website”
- Plugin auto-deactivates

### Checklist
- Confirm PHP version compatibility
- Check for syntax errors (missing `;`, unmatched braces)
- Ensure file paths are correct after repo restructuring
- Confirm required plugins are active (WATU PRO, UsersWP)

### Recovery
- Deactivate plugin via WP Admin or FTP
- Roll back to previous GitHub commit
- Re-deploy known working version

---

## 🟧 XP or Rank Not Updating After Quiz

### Possible Causes
- Quiz completion hook not firing
- Quiz is not a WATU PRO quiz
- Quiz is set to “Practice mode” or no results page
- User is not logged in

### Things to Verify
- Quiz awards points in WATU PRO
- Plugin hook is attached to quiz completion action
- User meta for XP exists and is numeric

---

## 🟨 Badges or Levels Not Appearing

### Possible Causes
- WATU Play not active
- Badge images missing or invalid URLs
- Modal CSS not loading
- AJAX request blocked

### Debug Steps
- Confirm WATU Play tables exist
- Inspect modal HTML output in browser
- Check DevTools → Network → AJAX responses
- Verify badge image paths are publicly accessible

---

## 🟦 Achievement Modal Styling Looks Broken

### Common Reasons
- CSS not loaded (plugin or UI loader disabled)
- Duplicate CSS rules applied (Elementor + plugin)
- Cached CSS (Cloudflare or host)

### Fixes
- Confirm `ika_master.css` is loading
- Clear cache (browser + CDN)
- Confirm modal markup matches expected structure

---

## 🟪 Flight Deck UI Broken or Overlapping

### Causes
- Missing scope class (`ika-scope-flightdeck`)
- Elementor container not wrapping page
- Legacy absolute positioning from other plugins

### Fix
- Ensure main page container has:

ika-scope-flightdeck

- Verify CSS is coming from master CSS file
- Check for conflicting theme styles

---

## 🟫 Leaderboard Not Showing Data

### Possible Causes
- No XP data exists yet
- Query returning empty results
- Current user has no rank/XP

### Debug
- Check user meta directly in database
- Test with admin account
- Confirm leaderboard shortcode output

---

## 🧯 Emergency Rollback Procedure

1. Deactivate plugin
2. Restore previous GitHub version
3. Re-deploy to staging
4. Test
5. Push staging → production

If production is broken:
- Restore via GoDaddy backup
- Or redeploy last known working plugin ZIP

---

## 🧠 General Debug Tips

- Always test in **staging first**
- Use browser DevTools for UI issues
- Use GitHub diffs to spot regressions
- Change one thing at a time
- Commit frequently

---

## 📞 When All Else Fails

Ask:
- What changed last?
- Was this tested in staging?
- Is the issue logic (PHP) or presentation (CSS)?

Revert first. Debug second.
