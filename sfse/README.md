# 🌱 Sustainable Food System Events — Plugin & Agent

A WordPress plugin and AI-powered Python agent that automatically discovers, extracts, and publishes sustainability + food events to your WordPress site every week.

---

## Overview

The system has two components:

**WordPress Plugin (PHP)**
Registers a custom post type, all ACF fields, front-end display shortcodes and a Gutenberg block to store and display events on your site. Supports multilingual sites via Polylang Pro.

**Python Agent**
Runs weekly, searches the web for relevant events, extracts structured data, and publishes directly to WordPress via the REST API. It learns from your feedback over time.

---

## Event Data Model

Every event is stored with the following fields:

| Field | Type | Notes |
|---|---|---|
| Title | Post title | |
| Description | Textarea | 2–3 sentences in the source language |
| Start Date & Time | DateTime | Defaults to 00:00 if time unknown |
| End Date & Time | DateTime | Defaults to 23:59 if time unknown |
| Registration Deadline | DateTime | Defaults to 23:59 if time unknown |
| Organiser | Text | Organisation or person running the event |
| Event Type | Select | Conference, Festival, Workshop, Webinar, Summit, Community Event, Other |
| Topics | Checkbox | Agroecology, Food Sovereignty, Circular Economy, Regenerative Agriculture, Food Policy, Nutrition, Consumer Behaviour, Other |
| Event Language(s) | Checkbox | Languages the event is held in (19 ISO 639-1 options + Other) |
| Source Language | Select | Language of the source website where the event was found |
| Format | Select | In-Person, Online, Hybrid |
| Location Name | Text | Venue or platform name |
| City | Text | |
| Country | Select | ISO 3166-1 alpha-2 fixed list |
| Continent | Select | Africa, Asia, Europe, North America, Oceania, Online, South America |
| Cost | Text | e.g. Free, €50, Paid – see link |
| Event Link | URL | Direct link to event page |
| Source URL | URL | Where the agent found the event |
| Verified | Toggle | Set to Verified after reviewing |
| Rejection Reason | Select | Not relevant, Wrong language, Duplicate, Poor data quality, Past event |
| Duplicate Of | Post reference | Link to the original if this is a duplicate |
| Date Added | DateTime | Auto-stamped by agent on first publish |
| Last Updated | DateTime | Auto-stamped by agent when a change is detected |

---

## File Structure

```
sfse/
├── sustainable-food-system-events.php   ← main plugin file
├── includes/
│   ├── sfs-events-cpt.php               ← CPT + meta field registration
│   ├── sfs-events-acf.php               ← ACF field group
│   ├── sfs-events-settings.php          ← admin settings page
│   └── sfs-events-shortcodes.php        ← front-end shortcodes + block render
├── assets/
│   ├── sfse-frontend.css                ← front-end styles
│   └── sfse-frontend.js                 ← filter logic (no dependencies)
├── block/
│   └── block.json                       ← Gutenberg block definition
├── src/
│   ├── index.js                         ← block editor preview (React)
│   └── editor.css                       ← block editor styles
├── build/                               ← compiled block assets (git-ignored)
├── agent.py                             ← Python: weekly event discovery agent
├── .env.example                         ← credentials template
├── .github/workflows/weekly_agent.yml   ← GitHub Actions: weekly scheduler
├── seen_events.json                     ← auto-created: local dedup cache
├── source_scores.json                   ← auto-created: source quality tracker
└── agent.log                            ← auto-created: run log
```

---

## WordPress Setup

### 1. Install required plugins

Install this free plugin from the WordPress plugin directory:
- **Advanced Custom Fields (ACF)** — for the event fields

For multilingual sites, **Polylang Pro** is supported out of the box — see the [Multilingual Setup](#multilingual-setup-polylang-pro) section below.

### 2. Upload the plugin

Upload the `sfse/` folder to:
```
/wp-content/plugins/sfse/
```

### 3. Activate

Go to **WordPress Admin → Plugins** and activate **Sustainable Food System Events**.

### 4. Create your events page

Create a new WordPress page (e.g. "Events") and add the **SFS Events** block from the Gutenberg block inserter. Publish the page.

If you are running a multilingual site, create a translated version of this page in each language and add the same block to each.

### 5. Configure the Events Page setting

Go to **SFS Events → Settings** and select your events page under **Events Page**. This is used for "back to all events" links on single event pages.

On multilingual sites, select the default-language version of the page — translated versions are resolved automatically via Polylang at runtime.

### 6. Flush rewrite rules

Go to **Settings → Permalinks** and click **Save Changes**. This ensures event URLs work correctly.

### 7. Create a WordPress Application Password

Go to **Users → Your Profile → Application Passwords**.
Create a new password named `SFSEventsAgent` and copy it — you will need it for the agent.

---

## Multilingual Setup (Polylang Pro)

The plugin integrates with Polylang Pro without any additional configuration.

**How it works:**
- Create a page per language, each containing the SFS Events block
- Set the default-language page in **SFS Events → Settings → Events Page**
- Polylang Pro handles language switching between pages automatically
- The "back to all events" link on single event pages resolves to the correct language version at runtime via `pll_get_post()`
- The plugin also registers the `sfse_event` post type with Polylang so individual events can be translated

**Single event pages:**
Single event pages use standard WordPress pages with the `[sfse_single_event]` shortcode, one per language. No block template or CPT archive is required.

---

## Python Agent Setup

### Requirements

- Python 3.10 or higher
- An Anthropic API key — sign up at [console.anthropic.com](https://console.anthropic.com)

### Install dependencies

```bash
pip install anthropic requests python-dotenv
```

### Configure credentials

```bash
cp .env.example .env
```

Edit `.env` with your values:

```
ANTHROPIC_API_KEY=sk-ant-your-key-here
WP_BASE_URL=https://your-wordpress-site.com
WP_USERNAME=your_wp_username
WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

### Run manually

```bash
python agent.py
```

---

## Scheduling with GitHub Actions (free)

GitHub Actions will run the agent every Monday at 07:00 UTC at no cost.

1. Push this folder to a **private** GitHub repository
2. Go to **Settings → Secrets and variables → Actions**
3. Add four secrets:
   - `ANTHROPIC_API_KEY`
   - `WP_BASE_URL`
   - `WP_USERNAME`
   - `WP_APP_PASSWORD`
4. The workflow in `.github/workflows/weekly_agent.yml` activates automatically

You can also trigger a manual run anytime from the **Actions** tab in GitHub.

---

## Agent Behaviour

### Search window
Each run discovers events between today and 12 months ahead. Past events are ignored.

### Language
Events are kept in their original language — no translation is applied. The agent detects the language and stores the ISO 639-1 code. If the language is not in the supported list, it is stored as `other`.

### Geographic scope
Priority regions are Europe, Americas, and Asia — split across three batched discovery queries. Other regions are included at lower priority.

### Search strategy
1. Known sources (configured in Settings) are checked first
2. Manual event URLs (configured in Settings) are processed individually
3. Discovery searches cover Europe, Americas, and Asia
4. The agent stops once 50 candidate events are found

### Deduplication
Events are deduplicated using a local cache (`seen_events.json`). A secondary WordPress query catches any duplicates that slipped through after a cache reset.

### WordPress writes
The agent compares incoming data against existing posts before writing. It only calls the WordPress REST API if something has actually changed, and only updates the `Last Updated` stamp when a real change is detected.

### Feedback loop
Before each run the agent reads rejected events from WordPress and uses them as negative examples. This teaches the agent what to avoid over time.

### Source quality tracking
The agent tracks how many events from each source are published versus rejected. Sources with a rejection rate above 75% over 3 or more runs are automatically deprioritised.

### Rate limits
The agent uses `claude-haiku` for event extraction. Each run makes several web search calls, which consume input tokens. If you encounter 429 rate limit errors, check your tier at **console.anthropic.com → Settings → Limits** — limits increase automatically as account spend grows. You can also request an increase via [anthropic.com/contact-sales](https://www.anthropic.com/contact-sales).

---

## Quality Control Workflow

1. After each weekly run, review new events in **WordPress Admin → SFS Events**
2. For events you approve: set **Verified** to on
3. For events you reject: select a **Rejection Reason** and optionally trash the post
4. The agent reads these rejections before the next run and adjusts accordingly

Rejected events are automatically moved to draft status and permanently deleted after the number of days configured in **SFS Events → Settings → Rejected Events Retention** (default: 7 days, set to 0 to keep forever).

---

## Customisation

### Add known sources to monitor

Go to **SFS Events → Settings → Known Sources** and add event listing page URLs. These are visited on every agent run.

Alternatively, edit `KNOWN_SOURCES` directly in `agent.py`:

```python
KNOWN_SOURCES = [
    "https://www.slowfood.com/events/",
    "https://your-favourite-org.org/events",
]
```

### Add a one-off event URL

Go to **SFS Events → Settings → Manual Event URLs** and paste a direct link to a specific event page. The agent processes it on its next run and removes it from the list automatically.

### Change the search window

In `agent.py`, edit:

```python
SEARCH_TO = (TODAY + timedelta(days=365)).strftime("%Y-%m-%d")
```

### Change the weekly schedule

In `.github/workflows/weekly_agent.yml`, edit the cron expression:

```yaml
- cron: "0 7 * * 1"   # Every Monday at 07:00 UTC
```

### Reset the dedup cache

Delete `seen_events.json` to allow the agent to rediscover all events from scratch.

### Reset source quality scores

Delete `source_scores.json` to clear all source ratings and start fresh.

---

## WordPress REST API Reference

The agent uses these endpoints:

| Action | Method | Endpoint |
|---|---|---|
| List events | GET | `/wp-json/wp/v2/sustainable-food-events` |
| Create event | POST | `/wp-json/wp/v2/sustainable-food-events` |
| Update event | POST | `/wp-json/wp/v2/sustainable-food-events/{id}` |
| Get rejections | GET | `/wp-json/wp/v2/sustainable-food-events?meta_key=sfse_rejection_reason` |
| Read settings | GET | `/wp-json/wp/v2/settings` |

Authentication uses WordPress Application Passwords (Basic Auth).

---

## Deploying to a New Site

### 1. Upload the plugin

Upload the `sfse/` folder to `/wp-content/plugins/sfse/` on the new site.

### 2. Activate and configure

1. Activate **Sustainable Food System Events** in **WordPress Admin → Plugins**
2. Install and activate **Advanced Custom Fields (ACF)**
3. Create your events page, add the SFS Events block, and publish
4. Go to **SFS Events → Settings**, select the events page, and add your known sources
5. Go to **Settings → Permalinks → Save Changes** to flush rewrite rules

### 3. Create an Application Password

Go to **Users → Your Profile → Application Passwords**, create a password named `SFSEventsAgent`, and copy it.

### 4. Configure the agent

Update `.env` with the new site's credentials:

```
WP_BASE_URL=https://your-new-site.com
WP_USERNAME=your_wp_username
WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

If using GitHub Actions, update the corresponding secrets in the repository settings.

### 5. Run and verify

Run the agent once manually to confirm it connects and posts correctly:

```bash
python agent.py
```

---

## Troubleshooting

**Events are not appearing on the site**
Go to **Settings → Permalinks** and click Save to flush rewrite rules. Confirm the plugin is activated.

**"Back to all events" link goes to the homepage**
Go to **SFS Events → Settings** and select the correct events page under Events Page.

**Agent cannot connect to WordPress**
Verify `WP_BASE_URL` has no trailing slash and that the Application Password was copied correctly including spaces.

**ACF fields are not showing**
Ensure ACF (free) is installed and activated. The SFSE plugin loads ACF fields programmatically — no import is needed.

**429 rate limit errors during agent run**
The agent retries automatically. For persistent errors, check your rate limit tier at **console.anthropic.com → Settings → Limits** or request an increase at [anthropic.com/contact-sales](https://www.anthropic.com/contact-sales).

**Agent log**
Each run appends to `agent.log` in the same folder as `agent.py`. Check this file for detailed output and any errors.
