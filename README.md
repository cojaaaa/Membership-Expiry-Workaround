# UR Membership Automation

Reliable membership renewal reminders and expiry handling for  
**User Registration Pro (WPEverest)**.

This MU-plugin fixes missing cron/bootstrap issues in UR Membership and adds
a predictable daily automation layer.

---

## What This Does

- Sends renewal reminder emails **X days before expiry** (default: 7 and 1)
- Marks memberships as expired after the billing date
- Optionally sends an expiry email
- Optionally removes a WordPress role on expiry
- Works without server cron
- Adds logging + manual run button in admin

---

## Why This Exists

In some installations, **User Registration Pro does not instantiate**
its `EmailSettings` class, which means:

- `urm_daily_membership_renewal_check` has no callbacks
- Cron runs but nothing happens
- Renewal emails are never sent

This plugin:
- bootstraps the missing class,
- guarantees the cron runs,
- safely automates reminders and expiry.

No core plugin files are modified.

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- User Registration Pro (WPEverest)
- Membership module enabled

---
