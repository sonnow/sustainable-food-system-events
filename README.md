# Sustainable Food System Events

A WordPress plugin that automatically discovers, extracts, and publishes sustainable food system events via a weekly AI agent. Built for [Leftover Lucy](https://leftoverlucy.com).

---

## How it works

A Python agent runs weekly (via GitHub Actions) and:

1. Reads known source URLs and manual event URLs from WordPress settings
2. Uses Claude (Anthropic) with web search to find upcoming events matching sustainable food system criteria
3. Extracts structured event data — title, dates, location, topics, cost, organiser, and promotional banner image
4. Posts new events to WordPress via the REST API, updating existing ones if data has changed
5. Records source quality scores and the last run timestamp back to WordPress

Events are published as a custom post type (`sfse_event`) and displayed via a Gutenberg block or shortcode with client-side filtering.

---

## Repository structure

```
sustainable-food-system-events/
├── .github/
│   └── workflows/
│       └── agent.yml          # GitHub Actions weekly schedule
├── sfse/                      # WordPress plugin
│   ├── assets/
│   │   ├── sfse-frontend.css
│   │   └── sfse-frontend.js
│   ├── block/                 # Gutenberg block source
│   ├── build/                 # Compiled block assets
│   ├── includes/
│   │   ├── sfs-events-acf.php         # ACF field group registration
│   │   ├── sfs-events-cpt.php         # Custom post type + meta + cron
│   │   ├── sfs-events-settings.php    # Admin settings page
│   │   └── sfs-events-shortcodes.php  # [sfse_events] and [sfse_single_event]
│   ├── templates/
│   │   ├── archive-sfse_event.php
│   │   └── single-sfse_event.php
│   └── sustainable-food-system-events.php  # Plugin entry point
├── SFSEventAgent-1.0/         # Python agent
│   ├── agent.py
│   ├── requirements.txt
│   ├── .env.example
│   └── source_scores.json     # Local backup (primary copy in WP options)
├── .gitattributes
├── .gitignore
└── README.md
```

---

## Requirements

### WordPress plugin
- WordPress 6.0+
- PHP 8.0+
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (free or Pro)
- [Polylang Pro](https://polylang.pro/) *(optional — for multilingual support)*
- [LiteSpeed Cache](https://www.litespeedtech.com/products/cache-plugins/wordpress-acceleration) *(optional — exclusion filters included)*

### Python agent
- Python 3.11+
- Anthropic API key (claude-haiku-4-5)
- WordPress application password

---

## Plugin installation

1. Upload the `sfse/` folder to `wp-content/plugins/sustainable-food-system-events/`
2. Activate the plugin in WP Admin → Plugins
3. Go to **SFS Events → Settings** and configure:
   - **Events Page** — select the page containing the SFS Events block
   - **Known Sources** — add event listing URLs to monitor
   - **Run Interval** — set to match your GitHub Actions schedule (default: 7 days)

---

## Agent setup (local)

```bash
cd SFSEventAgent-1.0
cp .env.example .env
# Edit .env with your credentials
pip install -r requirements.txt
python agent.py
```

### `.env` variables

| Variable | Description |
|---|---|
| `ANTHROPIC_API_KEY` | From [console.anthropic.com](https://console.anthropic.com/) |
| `WP_BASE_URL` | Your WordPress site URL, no trailing slash |
| `WP_USERNAME` | WordPress username |
| `WP_APP_PASSWORD` | WordPress application password (WP Admin → Users → Application Passwords) |

---

## GitHub Actions setup

The agent runs automatically every Sunday at 5am UTC (6am CET).

### Add repository secrets

Go to **GitHub repo → Settings → Secrets and variables → Actions** and add:

| Secret | Value |
|---|---|
| `ANTHROPIC_API_KEY` | Your Anthropic API key |
| `WP_BASE_URL` | Your WordPress site URL |
| `WP_USERNAME` | WordPress username |
| `WP_APP_PASSWORD` | WordPress application password |

### Manual trigger

Go to **Actions → SFS Events Agent → Run workflow** to trigger a run on demand without waiting for the schedule.

### Run logs

Each run uploads `agent.log` as a workflow artifact, retained for 30 days. Download it from the run summary page to inspect extraction results, image fetch outcomes, and any errors.

---

## WordPress settings

### SFS Events → Settings

| Setting | Description |
|---|---|
| **Events Page** | Page containing the SFS Events block — used for "back to all events" links |
| **Known Sources** | URLs the agent visits on every run |
| **Manual Event URLs** | Paste a direct event URL for the agent to process on its next run; cleared automatically after processing |
| **Rejected Events Retention** | Days to keep rejected events before permanent deletion (0 = keep forever) |
| **Agent — Last Run** | Timestamp of the last completed agent run (set automatically) |
| **Agent — Run Interval** | Days between source checks — set to match the GitHub Actions cron schedule |

---

## Event meta fields

Each event stores the following meta:

| Field | Description |
|---|---|
| `sfse_date_start` | Start date/time (YYYY-MM-DD HH:MM) |
| `sfse_date_end` | End date/time |
| `sfse_organiser` | Organising body |
| `sfse_event_type` | conference / festival / workshop / webinar / summit / community_event / other |
| `sfse_topics` | Array: agroecology / food_sovereignty / circular_economy / regenerative_agri / food_policy / nutrition / consumer_behaviour / other |
| `sfse_event_languages` | Array of ISO 639-1 language codes |
| `sfse_location_name` | Venue name |
| `sfse_city` | City |
| `sfse_country` | ISO 3166-1 alpha-2 country code, or `ONLINE` |
| `sfse_continent` | Derived automatically from country |
| `sfse_format` | in-person / online / hybrid |
| `sfse_cost` | Free-text cost string (e.g. "Free", "€250") |
| `sfse_registration_deadline` | Registration deadline date/time |
| `sfse_event_link` | Organiser's own event page URL — **write-once**, never overwritten by the agent |
| `sfse_source_url` | URL where the agent discovered the event — **write-once** |
| `sfse_image_url` | Promotional banner image URL (og:image from event page) — displayed with attribution |
| `sfse_verified` | Boolean — set manually by admin after review |
| `sfse_rejection_reason` | If set, event is automatically drafted |
| `sfse_duplicate_of` | Post ID of the original if this is a duplicate |
| `sfse_date_added` | Timestamp set by agent on first publish |
| `sfse_last_updated` | Timestamp updated by agent when changes are detected |

---

## Shortcodes

### `[sfse_events]`
Full events grid with client-side filters. Used on the events archive page and via the Gutenberg block.

Filters available: country, date range (presets + custom), format, topic, continent, event type, cost (free/paid), event language, organiser search.

### `[sfse_single_event]`
Single event detail view. Used automatically via the block template registered by the plugin — no manual placement needed.

---

## Automated maintenance

The plugin registers a daily cron (`sfse_daily_cleanup`) that runs two jobs:

- **Reject cleanup** — permanently deletes rejected events older than the configured retention period
- **Past events cleanup** — sets published events to draft once their end date (or start date) has passed, with a 1-day grace period. Override the grace period in `wp-config.php`: `define( 'SFSE_PAST_EVENT_GRACE_DAYS', 3 );`

---

## Agent behaviour notes

- **Source freshness** — known sources are skipped if checked within the configured run interval, preventing redundant API calls
- **Source quality scoring** — sources with a rejection rate above 75% over 3+ runs are deprioritised automatically; scores are stored in WordPress options and persist across GitHub Actions runners
- **event_link is write-once** — once set on creation, the agent never overwrites `sfse_event_link` or `sfse_source_url`. This prevents cross-contamination between events
- **Image fetching** — the agent reads the `og:image` meta tag from each event's own page (the tag the organiser sets for social sharing). Images are displayed by URL with attribution, never copied to your server. Site logos and icons are filtered out automatically
- **Image backfill** — at the start of each run, up to 20 existing events without a banner image are checked and updated, so images are retrofitted even for events posted before image support was added
- **Rejection feedback** — events rejected in WP admin (via the Rejection Reason field) are fed back to the agent as negative examples on the next run, improving relevance filtering over time

---

## Multilingual (Polylang Pro)

The `sfse_event` post type is registered with Polylang. The Events Page setting should point to the default-language page — translated versions are resolved automatically at runtime via `pll_get_post()`.

---

## Compatibility

| Component | Version |
|---|---|
| WordPress | 6.0 – 6.7 |
| PHP | 8.0+ |
| Python | 3.11+ |
| Claude model | claude-haiku-4-5-20251001 |
| Theme | Tested with Twenty Twenty-Four (FSE block theme) |

---

## Changelog

### 1.2.1 (current)
- Added promotional banner image support via `sfse_image_url` meta field — fetches `og:image` from each event's own page, displayed with source attribution
- Added image backfill — existing events without images are updated incrementally on each run
- Made `sfse_event_link` and `sfse_source_url` write-once to prevent cross-event data contamination
- Added logo/icon filter to reject site branding images
- Improved extraction prompt with explicit title and event_link integrity rules
- Added daily cron to auto-draft events whose end date has passed (1-day grace period, configurable)
- Source quality scores now stored in WordPress options — persist across GitHub Actions runner replacements
- Agent run interval and last run timestamp visible and configurable in WP Settings
- Improved description quality prompt: 3-5 sentences covering what, who, format, and why attend
- Increased `MAX_TOKENS_PER_CALL` to 4000 to prevent JSON truncation with longer descriptions
- Removed local `seen_events.json` dedup — WP-side `event_link` meta lookup handles all deduplication
- Added GitHub Actions workflow for weekly scheduled runs
- LiteSpeed Cache exclusion filters for plugin CSS/JS

### 1.2.0
- Block template registration via `get_block_templates` filter (no database writes)
- Polylang Pro integration for multilingual event pages
- Source freshness tracking and low-quality source deprioritisation
- Rejection reason feedback loop from WP admin to agent prompt
- Manual event URL processing via WP Settings

### 1.1.0
- Custom post type `sfse_event` with full ACF field group
- REST API meta registration for agent read/write access
- `[sfse_events]` shortcode with client-side filtering (country, date, format, topic, continent, type, cost, language, organiser)
- `[sfse_single_event]` shortcode for individual event pages
- Daily cron for rejected event cleanup

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
