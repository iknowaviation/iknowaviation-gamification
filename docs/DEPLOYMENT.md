# iKnowAviation Gamification – Deployment

## Source of truth
This GitHub repo is the source of truth.
Do not hot-edit plugin files on production.

## Where the plugin lives in WordPress
Production/staging path:
- wp-content/plugins/iknowaviation-gamification/

## Build ZIP for install/update
Zip ONLY the contents of:
- /plugin

The ZIP root should contain:
- iknowaviation-gamification.php
- includes/
- assets/
etc.

## Deployment workflow (recommended)
1) Make changes in a branch
2) Merge to main
3) Deploy to STAGING
4) Test key flows
5) Push staging → production (files only)
6) Tag release in GitHub
