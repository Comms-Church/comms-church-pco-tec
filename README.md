# Comms.Church — PCO Events → The Events Calendar

Syncs Planning Center Events (Calendar API v2) into The Events Calendar (TEC) on WordPress. Optionally attaches PCO Registrations signup links and ticket pricing to matched events.

## What it does

- Pulls all upcoming event instances from PCO Calendar v2 on a configurable schedule (hourly by default via WP-Cron)
- Creates, updates, or trashes TEC event posts to match PCO — PCO is the source of truth
- Creates TEC Venues from PCO Locations (matched by PCO ID, so they're never duplicated)
- Optionally matches each calendar event to a PCO Signup of the same name and stores the registration URL, ticket types, and price range as post meta
- Detects unchanged events (via `updated_at`) so unchanged events don't get re-written on every pass
- **Conflict strategy** — choose "PCO wins" (always overwrite) or "Manual wins" (skip events flagged as manually edited in WP)
- Full sync log visible in WP Admin, rolling 200 lines

## Requirements

- WordPress 6.2+
- PHP 8.1+
- **The Events Calendar** plugin (free or Pro) — active before this plugin activates
- A Planning Center account with access to the Calendar module

## Installation

1. Download the latest `comms-church-pco-tec.zip` from [Releases](https://github.com/Comms-Church/comms-church-pco-tec/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin → Install → Activate**.
3. Go to **Settings → PCO Events Sync** and enter your Planning Center API credentials.
4. Click **Sync Now** to run the first sync manually.

After the first install, the plugin checks GitHub for new releases automatically.

## API Credentials

Create a Personal Access Token at [api.planningcenteronline.com/oauth/applications](https://api.planningcenteronline.com/oauth/applications). Both the Calendar and Registrations modules use the same App ID / Secret pair. Required scopes: **Calendar** (read), **Registrations** (read, only if "Pull Registrations" is enabled).

## Settings Reference

| Setting | Default | Description |
|---|---|---|
| Sync Frequency | Hourly | WP-Cron schedule: hourly, every 6h, twice daily, daily |
| Conflict Strategy | PCO wins | When a WP-edited event changes in PCO: overwrite or skip |
| Delete Removed Events | On | Trash TEC posts whose PCO instance disappears from the feed |
| Past Days to Sync | 0 | Pull events this many days into the past (0 = future only) |
| Pull Registrations | Off | Match events to PCO Signups by name; attach URL + pricing |
| API Cache TTL | 300s | How long raw PCO API pages are cached between syncs |

## Post Meta Written to TEC Events

| Key | Value |
|---|---|
| `_pco_event_id` | PCO Calendar event ID |
| `_pco_instance_id` | PCO event_instance ID (used to detect deletes) |
| `_pco_updated_at` | ISO8601 — change detection sentinel |
| `_pco_synced` | Unix timestamp of last successful sync |
| `_pco_registration_url` | Direct PCO registration URL (if matched) |
| `_pco_min_price` | Lowest ticket price in dollars (if matched) |
| `_pco_max_price` | Highest ticket price (if matched) |
| `_pco_ticket_types` | JSON array of `{name, price, capacity}` objects |
| `_pco_manually_edited` | `1` if editor flagged this event as manually edited |

## Repo Structure

```
comms-church-pco-tec/
├── comms-church-pco-tec.php         # Main plugin file, version lives here
├── includes/
│   ├── class-cctec-api.php          # PCO Calendar v2 + Registrations v2 client
│   ├── class-cctec-cache.php        # Transient cache + circuit breaker
│   ├── class-cctec-sync.php         # Core sync engine: PCO → TEC mapper
│   ├── class-cctec-admin.php        # Settings page, sync log, manual trigger
│   └── class-cctec-updater.php      # GitHub-based auto-updater
└── assets/
    ├── admin.css
    └── admin.js
```

## Releasing a New Version

1. Bump `Version:` in the plugin header and `CCTEC_VERSION` constant in `comms-church-pco-tec.php` (both must match the git tag).
2. Commit and push.
3. Create a GitHub Release with a tag like `v1.1.0`.
4. GitHub Actions builds and attaches `comms-church-pco-tec.zip` to the release. Sites will see the update in **Plugins → Updates** within 24 hours (or immediately via the "Check for updates" link).

## Forked From

[Comms.Church — Planning Center Registrations](https://github.com/Comms-Church/comms-church-pco) — the same API credential pattern, cache, and auto-updater, focused on syncing Registrations signups to TEC instead of rendering them directly.
