"""
Sustainable Food System Events Agent
=====================================
Discovers, extracts, and publishes sustainability + food events to WordPress.
Run weekly via cron or GitHub Actions.

Requirements:
    pip install anthropic requests python-dotenv

Setup:
    Copy .env.example to .env and fill in your credentials.

Files:
    agent.py            — this file (main agent)
    source_scores.json  — local backup of source quality tracker
                          (primary copy stored in WordPress options)
    .env                — your credentials (never commit this)
"""

import os
import json
import re
import time
import logging
import urllib.parse
from datetime import datetime, timedelta
from typing import Optional
from dotenv import load_dotenv
import anthropic
import requests

load_dotenv()

# ── Windows Unicode fix for terminal output ──────────────────────────────────
import sys
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler("agent.log", encoding="utf-8"),
    ]
)
log = logging.getLogger(__name__)


# ─── Configuration ─────────────────────────────────────────────────────────────

ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")
WP_BASE_URL       = os.getenv("WP_BASE_URL", "").rstrip("/")
WP_USERNAME       = os.getenv("WP_USERNAME")
WP_APP_PASSWORD   = os.getenv("WP_APP_PASSWORD")

# Model — Haiku for cost efficiency; Sonnet reserved for future ambiguous relevance escalation
EXTRACTION_MODEL = "claude-haiku-4-5-20251001"

# Search window
TODAY           = datetime.today()
SEARCH_FROM     = TODAY.strftime("%Y-%m-%d")
SEARCH_TO       = (TODAY + timedelta(days=365)).strftime("%Y-%m-%d")

# Agent limits
MAX_CANDIDATES              = 50    # stop searching once this many raw events are found
MAX_TOKENS_PER_CALL         = 4000  # cap output tokens per API call — 3000 caused truncation with longer descriptions
MAX_EVENTS_PER_CALL         = 10    # cap events per response to avoid truncation
SOURCE_MIN_RUNS             = 3     # minimum runs before a source can be deprioritised
SOURCE_LOW_QUALITY_RATE     = 0.75  # deprioritise if rejection rate exceeds this
SOURCE_FRESHNESS_DAYS       = 14    # skip sources checked successfully within this many days
API_CALL_DELAY              = 65    # seconds between API calls (token budget reset)
API_CALL_DELAY_LARGE        = 90    # longer pause after responses with 3+ events

# Image fetch settings
IMAGE_FETCH_TIMEOUT = 8             # seconds — don't hang on slow event sites
IMAGE_FETCH_UA      = (             # identify ourselves politely to event sites
    "SustainableFoodSystemEvents-Bot/1.2 "
    "(+https://github.com/sonnow/sustainable-food-system-events)"
)
IMAGE_HEAD_MAX_BYTES = 51200        # 50 KB — enough to always capture <head>

# Local cache file (source scores only — seen_events dedup handled by WP)
SOURCE_SCORES_FILE = "source_scores.json"

# Priority regions — combined into one call where possible
REGION_BATCHES = [
    "Europe",
    "North America and South America",
    "Asia, Africa, and Oceania",
]

# Valid fixed-list values (must match ACF field choices exactly)
VALID_CONTINENTS = [
    "Africa", "Asia", "Europe", "North America",
    "Oceania", "Online", "South America"
]
VALID_FORMATS = ["in-person", "online", "hybrid"]
VALID_EVENT_TYPES = [
    "conference", "festival", "workshop",
    "webinar", "summit", "community_event", "other"
]
VALID_TOPICS = [
    "agroecology", "food_sovereignty", "circular_economy",
    "regenerative_agri", "food_policy", "nutrition",
    "consumer_behaviour", "other"
]
VALID_LANGUAGES = [
    "ar", "da", "de", "en", "es", "fi", "fr", "hi",
    "it", "ja", "ko", "nl", "no", "pl", "pt", "sv",
    "tr", "zh", "other"
]

# Country ISO alpha-2 to continent mapping
COUNTRY_TO_CONTINENT = {
    # Europe
    "AD": "Europe", "AL": "Europe", "AT": "Europe", "BA": "Europe",
    "BE": "Europe", "BG": "Europe", "BY": "Europe", "CH": "Europe",
    "CY": "Europe", "CZ": "Europe", "DE": "Europe", "DK": "Europe",
    "EE": "Europe", "ES": "Europe", "FI": "Europe", "FR": "Europe",
    "GB": "Europe", "GR": "Europe", "HR": "Europe", "HU": "Europe",
    "IE": "Europe", "IS": "Europe", "IT": "Europe", "LI": "Europe",
    "LT": "Europe", "LU": "Europe", "LV": "Europe", "MC": "Europe",
    "MD": "Europe", "ME": "Europe", "MK": "Europe", "MT": "Europe",
    "NL": "Europe", "NO": "Europe", "PL": "Europe", "PT": "Europe",
    "RO": "Europe", "RS": "Europe", "RU": "Europe", "SE": "Europe",
    "SI": "Europe", "SK": "Europe", "SM": "Europe", "UA": "Europe",
    "VA": "Europe", "XK": "Europe",
    # North America
    "AG": "North America", "BB": "North America", "BL": "North America",
    "BM": "North America", "BS": "North America", "BZ": "North America",
    "CA": "North America", "CR": "North America", "CU": "North America",
    "DM": "North America", "DO": "North America", "GD": "North America",
    "GT": "North America", "HN": "North America", "HT": "North America",
    "JM": "North America", "KN": "North America", "LC": "North America",
    "MQ": "North America", "MX": "North America", "NI": "North America",
    "PA": "North America", "PR": "North America", "SV": "North America",
    "TT": "North America", "US": "North America", "VC": "North America",
    # South America
    "AR": "South America", "BO": "South America", "BR": "South America",
    "CL": "South America", "CO": "South America", "EC": "South America",
    "FK": "South America", "GF": "South America", "GY": "South America",
    "PE": "South America", "PY": "South America", "SR": "South America",
    "UY": "South America", "VE": "South America",
    # Asia
    "AE": "Asia", "AF": "Asia", "AM": "Asia", "AZ": "Asia",
    "BD": "Asia", "BH": "Asia", "BN": "Asia", "BT": "Asia",
    "CN": "Asia", "GE": "Asia", "HK": "Asia", "ID": "Asia",
    "IL": "Asia", "IN": "Asia", "IQ": "Asia", "IR": "Asia",
    "JO": "Asia", "JP": "Asia", "KG": "Asia", "KH": "Asia",
    "KP": "Asia", "KR": "Asia", "KW": "Asia", "KZ": "Asia",
    "LA": "Asia", "LB": "Asia", "LK": "Asia", "MM": "Asia",
    "MN": "Asia", "MO": "Asia", "MV": "Asia", "MY": "Asia",
    "NP": "Asia", "OM": "Asia", "PH": "Asia", "PK": "Asia",
    "PS": "Asia", "QA": "Asia", "SA": "Asia", "SG": "Asia",
    "SY": "Asia", "TH": "Asia", "TJ": "Asia", "TL": "Asia",
    "TM": "Asia", "TR": "Asia", "TW": "Asia", "UZ": "Asia",
    "VN": "Asia", "YE": "Asia",
    # Africa
    "AO": "Africa", "BF": "Africa", "BI": "Africa", "BJ": "Africa",
    "BW": "Africa", "CD": "Africa", "CF": "Africa", "CG": "Africa",
    "CI": "Africa", "CM": "Africa", "CV": "Africa", "DJ": "Africa",
    "DZ": "Africa", "EG": "Africa", "ER": "Africa", "ET": "Africa",
    "GA": "Africa", "GH": "Africa", "GM": "Africa", "GN": "Africa",
    "GQ": "Africa", "GW": "Africa", "KE": "Africa", "KM": "Africa",
    "LR": "Africa", "LS": "Africa", "LY": "Africa", "MA": "Africa",
    "MG": "Africa", "ML": "Africa", "MR": "Africa", "MU": "Africa",
    "MW": "Africa", "MZ": "Africa", "NA": "Africa", "NE": "Africa",
    "NG": "Africa", "RW": "Africa", "SC": "Africa", "SD": "Africa",
    "SL": "Africa", "SN": "Africa", "SO": "Africa", "SS": "Africa",
    "ST": "Africa", "SZ": "Africa", "TD": "Africa", "TG": "Africa",
    "TN": "Africa", "TZ": "Africa", "UG": "Africa", "ZA": "Africa",
    "ZM": "Africa", "ZW": "Africa",
    # Oceania
    "AU": "Oceania", "FJ": "Oceania", "FM": "Oceania", "GU": "Oceania",
    "KI": "Oceania", "MH": "Oceania", "MP": "Oceania", "NC": "Oceania",
    "NR": "Oceania", "NZ": "Oceania", "PF": "Oceania", "PG": "Oceania",
    "PW": "Oceania", "SB": "Oceania", "TO": "Oceania", "TV": "Oceania",
    "VU": "Oceania", "WS": "Oceania",
}


# ─── Claude client ──────────────────────────────────────────────────────────────

client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)


# ─── Cache helpers ──────────────────────────────────────────────────────────────

def load_json_file(path: str, default) -> any:
    if os.path.exists(path):
        try:
            with open(path) as f:
                return json.load(f)
        except Exception:
            return default
    return default

def save_json_file(path: str, data: any):
    with open(path, "w") as f:
        json.dump(data, f, indent=2)

# seen_events.json local dedup removed — WP-side event_link lookup handles dedup.
# See wp_get_existing_event() which checks sfse_event_link meta before creating.


# ─── Source quality tracker ─────────────────────────────────────────────────────

def load_source_scores() -> dict:
    """
    Load source quality scores from WordPress options (primary) with local
    JSON file as fallback for the very first run before WP has any data.

    Structure:
    {
      "https://example.com/events": {
        "runs": 3,
        "published": 10,
        "rejected": 2,
        "last_checked": "2026-03-12"
      }
    }
    """
    # Primary: WordPress option (survives GitHub Actions runner replacements)
    try:
        r = requests.get(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            raw = r.json().get("sfse_source_scores", "{}")
            if raw and raw != "{}":
                scores = json.loads(raw) if isinstance(raw, str) else raw
                if isinstance(scores, dict):
                    log.info("   Source scores loaded from WordPress")
                    return scores
    except Exception as e:
        log.warning(f"Could not load source scores from WP: {e}")

    # Fallback: local JSON (migration path or offline use)
    local = load_json_file(SOURCE_SCORES_FILE, {})
    if local:
        log.info("   Source scores loaded from local file (will sync to WP on save)")
    return local


def save_source_scores(scores: dict):
    """
    Persist source quality scores to WordPress options (primary) and local
    JSON file (backup). Both are always written so either can be used as
    the source of truth.
    """
    # Primary: WordPress option
    try:
        r = requests.post(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            json={"sfse_source_scores": json.dumps(scores)},
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            log.info("   Source scores saved to WordPress")
        else:
            log.warning(f"   Could not save source scores to WP ({r.status_code})")
    except Exception as e:
        log.warning(f"   Could not save source scores to WP: {e}")

    # Backup: local JSON
    save_json_file(SOURCE_SCORES_FILE, scores)

def is_source_fresh(url: str, scores: dict, interval_days: int = SOURCE_FRESHNESS_DAYS) -> bool:
    """Returns True if this source was successfully checked within interval_days."""
    s = scores.get(url)
    if not s or not s.get("last_checked"):
        return False
    try:
        last = datetime.strptime(s["last_checked"], "%Y-%m-%d")
        return (TODAY - last).days < interval_days
    except ValueError:
        return False

def is_source_low_quality(url: str, scores: dict) -> bool:
    """Returns True if a source has a high rejection rate over enough runs."""
    s = scores.get(url)
    if not s or s.get("runs", 0) < SOURCE_MIN_RUNS:
        return False
    total = s.get("published", 0) + s.get("rejected", 0)
    if total == 0:
        return False
    rejection_rate = s.get("rejected", 0) / total
    return rejection_rate >= SOURCE_LOW_QUALITY_RATE

def record_source_outcome(url: str, scores: dict, published: int, rejected: int):
    if url not in scores:
        scores[url] = {"runs": 0, "published": 0, "rejected": 0, "last_checked": None}
    scores[url]["runs"]         += 1
    scores[url]["published"]    += published
    scores[url]["rejected"]     += rejected
    scores[url]["last_checked"]  = TODAY.strftime("%Y-%m-%d")


# ─── Feedback loader ────────────────────────────────────────────────────────────

def load_rejection_examples() -> list[dict]:
    """
    Fetch recently rejected events from WordPress to use as negative
    examples in the agent prompt. Helps the agent learn what to avoid.
    """
    url = (
        f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events"
        f"?meta_key=sfse_rejection_reason&per_page=20&status=publish"
    )
    try:
        r = requests.get(url, auth=(WP_USERNAME, WP_APP_PASSWORD), timeout=10)
        if r.status_code != 200:
            return []
        posts = r.json()
        examples = []
        for post in posts:
            meta = post.get("meta", {})
            reason = meta.get("sfse_rejection_reason")
            if reason:
                examples.append({
                    "title":            post.get("title", {}).get("rendered", ""),
                    "rejection_reason": reason,
                    "organiser":        meta.get("sfse_organiser", ""),
                    "event_type":       meta.get("sfse_event_type", ""),
                })
        return examples
    except Exception as e:
        log.warning(f"Could not load rejection examples from WP: {e}")
        return []


# ─── Prompt builder ─────────────────────────────────────────────────────────────

def build_system_prompt(rejection_examples: list[dict]) -> str:
    base = f"""You are an event research assistant for sustainable food systems.

TASK: Find events related to BOTH sustainability AND food. Both criteria must be met.
- Sustainability = environment, climate, regenerative agriculture, circular economy, food policy
- Food = food systems, agriculture, nutrition, culinary arts
- Exclude: general gardening, general environmental, general food/culinary without the other criterion.

WINDOW: {SEARCH_FROM} to {SEARCH_TO} only. Ignore past events.
LANGUAGE: Do NOT translate. Keep all text in original language. source_language = ISO 639-1 code (ar/da/de/en/es/fi/fr/hi/it/ja/ko/nl/no/pl/pt/sv/tr/zh/other).
OUTPUT: Valid JSON array only. No markdown, no explanation.
LIMIT: Max {MAX_EVENTS_PER_CALL} events per response.

Each event object (use null if unknown):
{{"title":string,"date_start":"YYYY-MM-DD HH:MM","date_end":"YYYY-MM-DD HH:MM","description":"3-5 sentences in original language. Cover: (1) what the event is about, (2) who it is aimed at, (3) what format or activities to expect, (4) what makes it worth attending. Do NOT just restate the title. Do NOT begin with This event is about.","organiser":string,"event_type":"conference|festival|workshop|webinar|summit|community_event|other","topics":["agroecology|food_sovereignty|circular_economy|regenerative_agri|food_policy|nutrition|consumer_behaviour|other"],"event_languages":["ISO-639-1"],"source_language":"ISO-639-1","location_name":string,"city":string,"country":"ISO-3166-1-alpha-2 or ONLINE","format":"in-person|online|hybrid","cost":string,"registration_deadline":"YYYY-MM-DD HH:MM","event_link":string,"source_url":string}}

TITLE RULES — critical, read carefully:
- Use the official name exactly as the organiser uses it on their own website.
- Do NOT use titles from aggregator sites, SEO pages, or conference listing directories.
- If the listing page and the organiser's own page have different titles, always use the organiser's page title.
- Include edition/year qualifiers when the organiser uses them (e.g. "17th North American Sustainable Foods Summit", not "Sustainable Foods Summit").
- Never mix the title of one event with the description or content of another. Each JSON object must describe one single coherent event — title, description, organiser, date, and event_link must all refer to the same event.

EVENT_LINK RULES — critical, read carefully:
- event_link must be the URL of the organiser's own event page, not the listing or aggregator page where you found it.
- source_url is where you found the event (the listing/aggregator page). event_link is where the event actually lives.
- If you only have a listing page URL, search for the event by name to find the organiser's own URL and use that as event_link.
- Never set event_link to the same domain as source_url unless the organiser genuinely hosts their events on that platform (e.g. Eventbrite, Meetup)."""

    if rejection_examples:
        base += "\n\nEXCLUDE events similar to:\n"
        for ex in rejection_examples[:5]:  # cap at 5 to save tokens
            base += (
                f"- \"{ex['title']}\" ({ex['rejection_reason']})\n"
            )

    return base.strip()


def clean_json_string(text: str) -> str:
    """
    Clean common JSON formatting issues from Claude responses.
    Preserves content values including currency symbols and special characters.
    Only fixes structural JSON problems.
    """
    # Replace curly/smart quotes with straight quotes
    text = text.replace('\u201c', '"').replace('\u201d', '"')  # curly double quotes
    text = text.replace('\u2018', "'").replace('\u2019', "'")  # curly single quotes

    # Remove control characters that break JSON
    text = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', '', text)

    # Fix unescaped literal newlines inside JSON string values.
    # JSON strings cannot contain raw newlines — they must be \n.
    # This replaces any literal newline that appears inside a quoted string
    # (i.e. not at the top level of the JSON structure) with a space.
    def fix_newlines_in_strings(s):
        result = []
        in_string = False
        escape_next = False
        for ch in s:
            if escape_next:
                result.append(ch)
                escape_next = False
                continue
            if ch == '\\' and in_string:
                escape_next = True
                result.append(ch)
                continue
            if ch == '"':
                in_string = not in_string
                result.append(ch)
                continue
            if in_string and ch == '\n':
                result.append(' ')  # replace literal newline with space
                continue
            if in_string and ch == '\r':
                continue  # strip carriage returns inside strings
            result.append(ch)
        return ''.join(result)

    text = fix_newlines_in_strings(text)

    # Remove trailing commas before ] or } (common Claude JSON mistake)
    text = re.sub(r',\s*([}\]])', r'\1', text)

    return text


# ─── Event extraction via Claude ────────────────────────────────────────────────

def search_and_extract(query: str, system_prompt: str) -> list[dict]:
    """
    Send a search query to Claude and return structured event list.
    Returns [] on any failure. Sets _large_response flag on truncation.
    """
    log.info(f"  🔍 {query[:100]}...")
    result = _search_and_extract_inner(query, system_prompt)
    if result == "large_failure":
        search_and_extract.last_was_large = True
        return []
    if result == "failure":
        search_and_extract.last_was_large = False
        return []
    search_and_extract.last_was_large = len(result) >= 3
    return result

search_and_extract.last_was_large = False


def _search_and_extract_inner(query: str, system_prompt: str):
    """Inner extraction — returns list, 'large_failure', or 'failure'."""
    try:
        response = client.messages.create(
            model=EXTRACTION_MODEL,
            max_tokens=MAX_TOKENS_PER_CALL,
            system=system_prompt,
            tools=[{"type": "web_search_20250305", "name": "web_search"}],
            messages=[{"role": "user", "content": query}]
        )
        # Collect all text blocks
        text = "".join(
            block.text for block in response.content if hasattr(block, "text")
        ).strip()

        # Extract JSON array
        start = text.find("[")
        end   = text.rfind("]") + 1
        if start == -1 or end == 0:
            log.warning("  ⚠️  No JSON array in response.")
            return []

        raw_json = text[start:end]

        # Attempt 1: parse as-is
        try:
            events = json.loads(raw_json)
            log.info(f"  → {len(events)} events extracted")
            return events if isinstance(events, list) else []
        except json.JSONDecodeError:
            pass

        # Attempt 2: clean structural issues then retry
        cleaned = clean_json_string(raw_json)
        try:
            events = json.loads(cleaned)
            log.info(f"  → {len(events)} events extracted (after JSON cleaning)")
            return events if isinstance(events, list) else []
        except json.JSONDecodeError as e:
            pos = e.pos
            snippet = cleaned[max(0, pos - 40):pos + 40]
            problem_char = cleaned[pos] if pos < len(cleaned) else "EOF"
            log.error(f"  ❌ JSON parse error after cleaning: {e}")
            log.error(f"     Position {pos}, char: {repr(problem_char)}")
            log.error(f"     Context: {repr(snippet)}")
            # EOF = truncation = large response
            return "large_failure" if problem_char == "EOF" else "failure"

    except Exception as e:
        log.error(f"  ❌ Claude API error: {e}")
        return "failure"


# ─── Image fetch ────────────────────────────────────────────────────────────────

def fetch_event_image_url(event_url: str) -> Optional[str]:
    """
    Read the og:image (or twitter:image fallback) from an event page's <head>.

    Why og:image?
      The organiser deliberately sets this tag so their promotional banner
      appears when someone shares the event link on social media. It is the
      image they want seen — we display it with attribution, never copy it.

    Why no Claude call?
      This is pure HTTP + regex on the first 50 KB of HTML. Zero API tokens.

    Returns the absolute image URL, or None on any failure.
    Never raises — all errors are logged at DEBUG level so a missing image
    never blocks an event from being published.
    """
    if not event_url:
        return None

    try:
        headers = {
            "User-Agent": IMAGE_FETCH_UA,
            "Accept":     "text/html,application/xhtml+xml",
            "Accept-Language": "en",
        }
        r = requests.get(
            event_url,
            headers=headers,
            timeout=IMAGE_FETCH_TIMEOUT,
            allow_redirects=True,
            stream=True,      # streaming so we can stop early
        )

        if r.status_code != 200:
            log.debug(f"  🖼  HTTP {r.status_code} fetching {event_url}")
            return None

        content_type = r.headers.get("Content-Type", "")
        if "html" not in content_type:
            log.debug(f"  🖼  Not HTML ({content_type}): {event_url}")
            return None

        # Read only what we need — <head> is always within the first 50 KB
        partial = b""
        for chunk in r.iter_content(chunk_size=4096):
            partial += chunk
            if len(partial) >= IMAGE_HEAD_MAX_BYTES:
                break
            if b"</head>" in partial or b"<body" in partial:
                break  # stop as soon as we're past the head

        html = partial.decode("utf-8", errors="replace")

        # ── Try og:image ──────────────────────────────────────────────────────
        # Two patterns: property before content, and content before property
        for pattern in (
            r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']',
            r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']',
        ):
            m = re.search(pattern, html, re.IGNORECASE)
            if m:
                img = _make_absolute(m.group(1).strip(), event_url)
                if not _is_logo_url(img):
                    return img

        # ── Fallback: twitter:image ───────────────────────────────────────────
        for pattern in (
            r'<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']',
            r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']',
        ):
            m = re.search(pattern, html, re.IGNORECASE)
            if m:
                img = _make_absolute(m.group(1).strip(), event_url)
                if not _is_logo_url(img):
                    return img

        log.debug(f"  🖼  No og:image or twitter:image at {event_url}")
        return None

    except requests.exceptions.Timeout:
        log.debug(f"  🖼  Timeout fetching image from {event_url}")
        return None
    except Exception as e:
        log.debug(f"  🖼  Image fetch error ({event_url}): {e}")
        return None


# Filename fragments that indicate a site logo or icon rather than an event banner.
# og:image is sometimes set to a site-wide logo — these are useless as event images.
_LOGO_URL_FRAGMENTS = (
    "logo", "favicon", "icon", "avatar",
    "cropped-logo", "site-logo", "brand",
)

def _is_logo_url(url: str) -> bool:
    """Return True if the image URL looks like a site logo rather than an event banner."""
    path = urllib.parse.urlparse(url).path.lower()
    filename = path.rsplit("/", 1)[-1]   # just the filename portion
    return any(frag in filename for frag in _LOGO_URL_FRAGMENTS)


def _make_absolute(img_url: str, base_url: str) -> str:
    """Resolve a potentially relative image URL against the page base URL."""
    if img_url.startswith(("http://", "https://")):
        return img_url
    return urllib.parse.urljoin(base_url, img_url)


def enrich_events_with_images(events: list[dict]) -> None:
    """
    Attempt to fetch og:image for every event that has an event_link.
    Mutates the event dicts in-place — adds 'image_url' key.
    Non-fatal: events without an image get image_url = None and are still published.

    Fetch order:
      1. event_link  — the organiser's own event page (best source for the banner)
      2. source_url  — the listing page where the agent found the event, but only
                       if it is on a different domain to event_link. Same-domain
                       pages share the source site's own og:image branding and
                       would not give us the event-specific banner.
    """
    for event in events:
        event_link = event.get("event_link")
        source_url = event.get("source_url")
        title      = event.get("title", "")[:50]
        img_url    = None

        # 1. Try event_link first
        if event_link:
            img_url = fetch_event_image_url(event_link)

        # 2. Fallback: source_url if event_link yielded nothing.
        # The same-domain guard was removed because with event_link now write-once
        # and pointing to the organiser's own page, source_url is typically a
        # different domain anyway. The logo filter in fetch_event_image_url
        # handles the case where source_url returns a site-wide branding image.
        if not img_url and source_url and source_url != event_link:
            img_url = fetch_event_image_url(source_url)
            if img_url:
                log.info(f"  🖼  Image via source_url fallback: '{title}'")

        event["image_url"] = img_url

        if img_url:
            log.info(f"  🖼  Image: '{title}' → {img_url[:80]}")

        # Small pause between site requests — polite and avoids rate-limits
        time.sleep(0.5)


# ─── Data validation & normalisation ───────────────────────────────────────────

def normalise_event(event: dict) -> Optional[dict]:
    """
    Validate and clean a raw event dict.
    Returns None if the event should be discarded.
    """
    # Must have title and start date
    if not event.get("title") or not event.get("date_start"):
        return None

    # Must be within search window
    try:
        start = datetime.strptime(event["date_start"][:10], "%Y-%m-%d")
        end_window = TODAY + timedelta(days=365)
        if start < TODAY or start > end_window:
            return None
    except ValueError:
        return None

    # Normalise datetime defaults
    if len(event.get("date_start", "")) == 10:
        event["date_start"] += " 00:00"
    if event.get("date_end") and len(event["date_end"]) == 10:
        event["date_end"] += " 23:59"
    if event.get("registration_deadline") and len(event["registration_deadline"]) == 10:
        event["registration_deadline"] += " 23:59"

    # Derive continent from country
    country = event.get("country")
    if country == "ONLINE" or event.get("format") == "online":
        event["continent"] = "Online"
    elif country and country.upper() in COUNTRY_TO_CONTINENT:
        event["continent"] = COUNTRY_TO_CONTINENT[country.upper()]
    else:
        event["continent"] = None

    # Clamp fixed-list fields
    if event.get("format") not in VALID_FORMATS:
        event["format"] = "in-person"
    if event.get("event_type") not in VALID_EVENT_TYPES:
        event["event_type"] = None
    if event.get("source_language") not in VALID_LANGUAGES:
        event["source_language"] = "other"

    # Clamp topics array
    raw_topics = event.get("topics") or []
    event["topics"] = [t for t in raw_topics if t in VALID_TOPICS]

    # Clamp event_languages array
    raw_langs = event.get("event_languages") or []
    event["event_languages"] = [l for l in raw_langs if l in VALID_LANGUAGES]

    return event


def wp_auth():
    return (WP_USERNAME, WP_APP_PASSWORD)


def wp_get_existing_event(title: str, date_start: str, event_link: str = '') -> Optional[dict]:
    """
    Check WordPress for an existing event.
    Primary: match by event_link URL (most reliable).
    Fallback: match by title + date_start.
    Returns the WP post dict or None.
    """
    try:
        # Primary: search by event_link meta
        if event_link:
            r = requests.get(
                f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events",
                params={"meta_key": "sfse_event_link", "meta_value": event_link, "per_page": 2},
                auth=wp_auth(),
                timeout=10,
            )
            if r.status_code == 200:
                posts = r.json()
                if posts:
                    return posts[0]

        # Fallback: search by title + date
        r = requests.get(
            f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events",
            params={"search": title[:50], "per_page": 5},
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code != 200:
            return None
        posts = r.json()
        date_prefix = date_start[:10]
        for post in posts:
            existing_title = post.get("title", {}).get("rendered", "").strip()
            existing_start = post.get("meta", {}).get("sfse_date_start", "")[:10]
            if (
                existing_title.lower() == title.lower().strip()
                and existing_start == date_prefix
            ):
                return post
        return None
    except Exception as e:
        log.warning(f"WP existence check failed: {e}")
        return None


def build_wp_payload(event: dict, status: str = "publish") -> dict:
    """Build the WordPress REST API payload for a single event."""
    now = datetime.now().strftime("%Y-%m-%d %H:%M")
    description = event.get("description", "")

    return {
        "title":   event.get("title", ""),
        "content": description,
        "status":  status,
        "meta": {
            "sfse_date_start":            event.get("date_start"),
            "sfse_date_end":              event.get("date_end"),
            "sfse_organiser":             event.get("organiser"),
            "sfse_event_type":            event.get("event_type"),
            "sfse_topics":                event.get("topics", []),
            "sfse_event_languages":       event.get("event_languages", []),
            "sfse_language":              event.get("source_language"),
            "sfse_description":           description,
            "sfse_location_name":         event.get("location_name"),
            "sfse_city":                  event.get("city"),
            "sfse_country":               event.get("country"),
            "sfse_continent":             event.get("continent"),
            "sfse_format":                event.get("format"),
            "sfse_cost":                  event.get("cost"),
            "sfse_registration_deadline": event.get("registration_deadline"),
            "sfse_event_link":            event.get("event_link"),
            "sfse_source_url":            event.get("source_url"),
            "sfse_image_url":             event.get("image_url"),   # og:image URL (may be None)
            "sfse_verified":              False,
            "sfse_date_added":            now,
            "sfse_last_updated":          now,
        }
    }


def build_wp_update_payload(event: dict, existing_meta: dict) -> Optional[dict]:
    """
    Compare incoming event data against existing WP post meta.
    Returns a payload only if something has changed, otherwise None.

    Fields never overwritten by the agent on update:
      sfse_verified, sfse_rejection_reason, sfse_duplicate_of, sfse_date_added
      sfse_event_link  — write-once: set on creation, the canonical identifier
      sfse_source_url  — write-once: records where the event was first found

    Treating event_link as write-once means the URL that identified this post
    can never be replaced by a different event's URL, preventing cross-
    contamination where one event's data silently overwrites another's record.

    sfse_image_url: only written if a new image was found AND the field is
    currently empty — we never silently replace a human-curated image.
    """
    description = event.get("description", "")

    agent_fields = {
        "sfse_date_start":            event.get("date_start"),
        "sfse_date_end":              event.get("date_end"),
        "sfse_organiser":             event.get("organiser"),
        "sfse_event_type":            event.get("event_type"),
        "sfse_topics":                event.get("topics", []),
        "sfse_event_languages":       event.get("event_languages", []),
        "sfse_language":              event.get("source_language"),
        "sfse_description":           description,
        "sfse_location_name":         event.get("location_name"),
        "sfse_city":                  event.get("city"),
        "sfse_country":               event.get("country"),
        "sfse_continent":             event.get("continent"),
        "sfse_format":                event.get("format"),
        "sfse_cost":                  event.get("cost"),
        "sfse_registration_deadline": event.get("registration_deadline"),
        # sfse_event_link and sfse_source_url intentionally omitted — write-once
    }

    # Only fill image_url if we found one AND the field is currently empty
    new_image = event.get("image_url")
    if new_image and not existing_meta.get("sfse_image_url"):
        agent_fields["sfse_image_url"] = new_image

    changed = {}
    for key, new_val in agent_fields.items():
        existing_val = existing_meta.get(key)
        if isinstance(new_val, list):
            if sorted(new_val) != sorted(existing_val or []):
                changed[key] = new_val
        else:
            if str(new_val or "") != str(existing_val or ""):
                changed[key] = new_val

    if not changed:
        return None  # nothing to update

    changed["sfse_last_updated"] = datetime.now().strftime("%Y-%m-%d %H:%M")
    payload = {"meta": changed}

    # Update post content if description changed
    if "sfse_description" in changed:
        payload["content"] = description

    return payload


def post_event(event: dict) -> str:
    """
    Create or update a WordPress event post.
    Returns: 'created' | 'updated' | 'unchanged' | 'failed'
    """
    title      = event.get("title", "")
    date_start = event.get("date_start", "")
    event_link = event.get("event_link", "")

    # Check for existing post — use event_link as primary key
    existing = wp_get_existing_event(title, date_start, event_link)

    if existing:
        existing_meta  = existing.get("meta", {})
        update_payload = build_wp_update_payload(event, existing_meta)
        if update_payload is None:
            log.info(f"  ↔️  Unchanged: {title}")
            return "unchanged"
        try:
            post_id = existing["id"]
            r = requests.post(
                f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events/{post_id}",
                json=update_payload,
                auth=wp_auth(),
                timeout=15,
            )
            if r.status_code in (200, 201):
                log.info(f"  🔄 Updated: {title}")
                return "updated"
            else:
                log.error(f"  ❌ Update failed ({r.status_code}): {title} — {r.text[:150]}")
                return "failed"
        except Exception as e:
            log.error(f"  ❌ Update error for {title}: {e}")
            return "failed"

    else:
        try:
            payload = build_wp_payload(event)
            r = requests.post(
                f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events",
                json=payload,
                auth=wp_auth(),
                timeout=15,
            )
            if r.status_code in (200, 201):
                log.info(f"  ✅ Created: {title}")
                return "created"
            else:
                log.error(f"  ❌ Create failed ({r.status_code}): {title} — {r.text[:150]}")
                return "failed"
        except Exception as e:
            log.error(f"  ❌ Create error for {title}: {e}")
            return "failed"


def adaptive_delay(event_count: int):
    """Wait longer after large or failed-large responses to avoid rate limits."""
    is_large = search_and_extract.last_was_large or event_count >= 5
    delay = API_CALL_DELAY_LARGE if is_large else API_CALL_DELAY
    log.info(f"  ⏳ Waiting {delay}s before next API call...")
    time.sleep(delay)


# ─── WordPress options reader ───────────────────────────────────────────────────

def fetch_wp_known_sources() -> list[str]:
    try:
        r = requests.get(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            sources = r.json().get("sfse_known_sources", [])
            if isinstance(sources, list) and sources:
                return [s for s in sources if s]
    except Exception as e:
        log.warning(f"Could not read known sources from WP: {e}")
    return []

def fetch_wp_manual_urls() -> list[str]:
    try:
        r = requests.get(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            urls = r.json().get("sfse_manual_event_urls", [])
            if isinstance(urls, list):
                return [u for u in urls if u]
    except Exception as e:
        log.warning(f"Could not read manual URLs from WP: {e}")
    return []

def fetch_wp_run_interval() -> int:
    """
    Read the configured agent run interval from WordPress settings.
    Falls back to SOURCE_FRESHNESS_DAYS if unavailable.
    """
    try:
        r = requests.get(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            val = r.json().get("sfse_agent_run_interval_days")
            if val and int(val) >= 1:
                return int(val)
    except Exception as e:
        log.warning(f"Could not read run interval from WP: {e}")
    return SOURCE_FRESHNESS_DAYS


def record_last_agent_run():
    """Write the current UTC timestamp to sfse_last_agent_run in WordPress."""
    timestamp = datetime.utcnow().strftime("%Y-%m-%d %H:%M UTC")
    try:
        r = requests.post(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            json={"sfse_last_agent_run": timestamp},
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            log.info(f"   Last run recorded: {timestamp}")
        else:
            log.warning(f"   Could not record last run ({r.status_code})")
    except Exception as e:
        log.warning(f"   Could not record last run: {e}")


def clear_wp_manual_urls():
    try:
        r = requests.post(
            f"{WP_BASE_URL}/wp-json/wp/v2/settings",
            json={"sfse_manual_event_urls": []},
            auth=wp_auth(),
            timeout=10,
        )
        if r.status_code == 200:
            log.info("  ✅ Manual URLs cleared from WP settings")
        else:
            log.warning(f"  ⚠️  Could not clear manual URLs ({r.status_code})")
    except Exception as e:
        log.warning(f"  ⚠️  Could not clear manual URLs: {e}")



def backfill_missing_images(max_events: int = 20) -> None:
    """
    Retrofit og:image banners onto existing published WP events that have
    an event_link but no sfse_image_url.

    Called once at the start of each run. Uses the same fetch logic as new
    events. Capped at max_events per run so it does not dominate the runtime
    — remaining events are picked up on subsequent runs until all are filled.

    This is necessary because image enrichment only runs on events extracted
    in the current run. Existing posts created before image support was added,
    or whose image fetch previously failed, need this separate pass.
    """
    log.info(f"\n🖼  Backfilling images for existing events (cap: {max_events})...")

    try:
        # Fetch published events that have event_link but empty image_url.
        # WP REST does not support "meta field is empty" natively, so we
        # fetch in pages and filter client-side.
        candidates = []
        page = 1
        while len(candidates) < max_events:
            r = requests.get(
                f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events",
                params={
                    "status":   "publish",
                    "per_page": 50,
                    "page":     page,
                    "_fields":  "id,meta",
                },
                auth=wp_auth(),
                timeout=15,
            )
            if r.status_code == 400:
                break  # past last page
            if r.status_code != 200:
                log.warning(f"  ⚠️  Backfill fetch failed ({r.status_code})")
                break

            posts = r.json()
            if not posts:
                break

            for post in posts:
                meta       = post.get("meta", {})
                event_link = meta.get("sfse_event_link", "")
                image_url  = meta.get("sfse_image_url",  "")
                if event_link and not image_url:
                    candidates.append({
                        "id":         post["id"],
                        "event_link": event_link,
                    })
                    if len(candidates) >= max_events:
                        break

            # Stop paging if we got a partial page — no more pages exist
            if len(posts) < 50:
                break
            page += 1

    except Exception as e:
        log.warning(f"  ⚠️  Backfill query error: {e}")
        return

    if not candidates:
        log.info("   No events need image backfill")
        return

    log.info(f"   Found {len(candidates)} event(s) without image — fetching...")

    filled = 0
    for item in candidates:
        post_id    = item["id"]
        event_link = item["event_link"]

        img_url = fetch_event_image_url(event_link)
        if not img_url:
            log.debug(f"  🖼  No image found for post {post_id} ({event_link[:60]})")
            time.sleep(0.5)
            continue

        # Patch just sfse_image_url — nothing else touched
        try:
            r = requests.post(
                f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events/{post_id}",
                json={"meta": {"sfse_image_url": img_url}},
                auth=wp_auth(),
                timeout=15,
            )
            if r.status_code in (200, 201):
                log.info(f"  🖼  Backfilled post {post_id}: {img_url[:80]}")
                filled += 1
            else:
                log.warning(f"  ⚠️  Backfill patch failed for post {post_id} ({r.status_code})")
        except Exception as e:
            log.warning(f"  ⚠️  Backfill patch error for post {post_id}: {e}")

        time.sleep(0.5)  # polite pace

    log.info(f"   Backfill complete: {filled}/{len(candidates)} images added")


# ─── Main agent run ─────────────────────────────────────────────────────────────

def run_agent():
    log.info("=" * 65)
    log.info("🌱 Sustainable Food System Events Agent")
    log.info(f"   Run date : {TODAY.strftime('%Y-%m-%d')}")
    log.info(f"   Window   : {SEARCH_FROM} → {SEARCH_TO}")
    log.info("=" * 65)

    source_scores = load_source_scores()
    run_interval  = fetch_wp_run_interval()
    log.info(f"   Run interval : {run_interval} days")
    all_events: list[dict] = []

    # ── 0. Backfill images onto existing events that have none ──────────────
    backfill_missing_images(max_events=20)

    # ── 1. Load rejection feedback from WordPress ────────────────────────────
    log.info("\n📋 Loading rejection feedback from WordPress...")
    rejection_examples = load_rejection_examples()
    log.info(f"   {len(rejection_examples)} rejection examples loaded")
    system_prompt = build_system_prompt(rejection_examples)

    # ── 2. Process manual event URLs from WP settings ───────────────────────
    manual_urls = fetch_wp_manual_urls()
    if manual_urls:
        log.info(f"\n📌 Processing {len(manual_urls)} manual event URL(s)...")
        manual_results = []
        for url in manual_urls:
            if len(all_events) >= MAX_CANDIDATES:
                break
            query = (
                f"Go to {url} and extract the event details. "
                f"Return the event as a JSON array with one object. "
                f"Apply the same relevance rules as normal — if the event does not clearly relate "
                f"to BOTH sustainability AND food systems, still return it but set "
                f"a field 'manual_rejected' to true and 'rejection_reason' to a brief explanation."
            )
            events = search_and_extract(query, system_prompt)
            for event in events:
                event["_manual"] = True
                event["_source_url"] = url
            manual_results.extend(events)
            adaptive_delay(len(events))

        for event in manual_results:
            if event.get("manual_rejected"):
                log.info(f"  ⚠️  Manual event failed relevance: {event.get('title', url)}")
                payload = build_wp_payload(event, status="pending")
                payload["meta"]["sfse_rejection_reason"] = event.get(
                    "rejection_reason", "Did not meet dual sustainability + food criteria"
                )
                try:
                    r = requests.post(
                        f"{WP_BASE_URL}/wp-json/wp/v2/sustainable-food-events",
                        json=payload,
                        auth=wp_auth(),
                        timeout=15,
                    )
                    if r.status_code in (200, 201):
                        log.info(f"  📝 Saved as pending draft: {event.get('title', url)}")
                    else:
                        log.error(f"  ❌ Could not save draft ({r.status_code})")
                except Exception as e:
                    log.error(f"  ❌ Draft save error: {e}")
            else:
                all_events.append(event)

        clear_wp_manual_urls()

    # ── 3. Monitor known sources ─────────────────────────────────────────────
    known_sources = fetch_wp_known_sources()
    if not known_sources:
        log.warning("  ⚠️  No known sources in WP settings — check SFS Events > Settings")
    log.info(f"\n📡 Monitoring {len(known_sources)} known sources...")

    for source_url in known_sources:

        if is_source_fresh(source_url, source_scores, run_interval):
            log.info(f"  ⏭️  Skipping fresh source (checked within {run_interval}d): {source_url}")
            continue

        if is_source_low_quality(source_url, source_scores):
            log.info(f"  ⏭️  Skipping low-quality source: {source_url}")
            continue

        if len(all_events) >= MAX_CANDIDATES:
            log.info(f"  🛑 Candidate limit ({MAX_CANDIDATES}) reached — skipping remaining sources")
            break

        query = (
            f"Go to {source_url} and extract all upcoming events about sustainable food systems, "
            f"food sovereignty, agroecology, regenerative agriculture, food policy, or circular food economy. "
            f"Only include events between {SEARCH_FROM} and {SEARCH_TO}. "
            f"For each event, if no direct event_link is visible on the page, search for the event "
            f"by name to find the organiser's own website URL and use that as event_link. "
            f"Only set event_link to null if no URL can be found after searching."
        )
        events = search_and_extract(query, system_prompt)
        all_events.extend(events)
        record_source_outcome(source_url, source_scores, published=0, rejected=0)
        adaptive_delay(len(events))

    # ── 4. Discovery searches ────────────────────────────────────────────────
    if len(all_events) < MAX_CANDIDATES:
        log.info(f"\n🌍 Running discovery searches across priority regions...")
        for region_batch in REGION_BATCHES:

            if len(all_events) >= MAX_CANDIDATES:
                log.info(f"  🛑 Candidate limit reached — skipping remaining regions")
                break

            query = (
                f"Find upcoming events in {region_batch} related to sustainable food systems, "
                f"food sovereignty, agroecology, regenerative agriculture, food policy, "
                f"circular food economy, or sustainable nutrition. "
                f"Include conferences, workshops, festivals, webinars, summits, and community events. "
                f"Only include events between {SEARCH_FROM} and {SEARCH_TO}. "
                f"For each event found, if no direct event_link is available, search for the event "
                f"by name to find the organiser's own website URL and use that as event_link. "
                f"Only set event_link to null if no URL can be found after searching."
            )
            events = search_and_extract(query, system_prompt)
            all_events.extend(events)
            adaptive_delay(len(events))

    log.info(f"\n📦 Total raw candidates: {len(all_events)}")

    # ── 5. Validate and normalise ────────────────────────────────────────────
    valid_events = []
    for event in all_events:
        normalised = normalise_event(event)
        if normalised:
            valid_events.append(normalised)

    log.info(f"✔️  Valid after normalisation: {len(valid_events)}")

    # ── 6. WP-side dedup (via event_link meta lookup in post_event) ──────────
    # Local seen_events.json dedup removed — wp_get_existing_event() checks
    # sfse_event_link in WordPress before creating, catching all true duplicates.
    new_events = valid_events
    log.info(f"📋 Candidates to check against WordPress: {len(new_events)}")

    # ── 7. Fetch promotional banner images ───────────────────────────────────
    if new_events:
        log.info(f"\n🖼  Fetching og:image banners for {len(new_events)} event(s)...")
        enrich_events_with_images(new_events)
        found = sum(1 for e in new_events if e.get("image_url"))
        log.info(f"   Banners found: {found}/{len(new_events)}")

    # ── 8. Post to WordPress ─────────────────────────────────────────────────
    log.info(f"\n🚀 Posting to WordPress...")
    results = {"created": 0, "updated": 0, "unchanged": 0, "failed": 0}

    for event in new_events:
        outcome = post_event(event)
        results[outcome] += 1

        source_url = event.get("source_url", "discovery")
        if outcome in ("created", "updated"):
            record_source_outcome(source_url, source_scores, published=1, rejected=0)
        elif outcome == "failed":
            record_source_outcome(source_url, source_scores, published=0, rejected=1)

        time.sleep(1)

    # ── 9. Persist state ─────────────────────────────────────────────────────
    save_source_scores(source_scores)
    record_last_agent_run()

    # ── 10. Summary ──────────────────────────────────────────────────────────
    log.info("\n" + "=" * 65)
    log.info("✅ Run complete")
    log.info(f"   Created   : {results['created']}")
    log.info(f"   Updated   : {results['updated']}")
    log.info(f"   Unchanged : {results['unchanged']}")
    log.info(f"   Failed    : {results['failed']}")
    log.info("=" * 65)


# ─── Entry point ────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    run_agent()
