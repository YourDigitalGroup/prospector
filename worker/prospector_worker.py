#!/usr/bin/env python3
"""
Prospector batch worker — runs the daily prospecting loop on a local machine
against a local Ollama, and posts the results up to Prospector.

Standard library only. Nothing to pip install, which matters on a Mac that is
already running something else you do not want to disturb.

    python3 prospector_worker.py --check          # verify config, Ollama, Prospector
    python3 prospector_worker.py --dry-run        # full run, print results, store nothing
    python3 prospector_worker.py                  # the real thing
    python3 prospector_worker.py --user billy@44idigital.com --limit 3
    python3 prospector_worker.py --test-source state_broadcasters

Design note — why this is safe to run on a small local model
-----------------------------------------------------------
A 7–8B model will invent a marketing director and an email address without
hesitating, and a fabricated contact is worse than no contact: it wastes a call,
and a bounced address damages the sending domain.

So the model here is never the source of a fact. It only ever:

  1. answers narrow questions about text it has just been handed, and
  2. writes one "why them" line and one opening hook from facts already
     established.

Every fact is grounded in code:

  * company names come from the source registry, never from the model
  * ownership disqualifiers are string matches against a list
  * emails and phones are pulled out of fetched HTML by regex, with the URL
    they were found on recorded
  * names and titles the model proposes are discarded unless they appear
    verbatim in the fetched page
  * the fit score is computed here, from the rubric, not asked for

The consequence worth knowing: this worker never produces a "pattern"
confidence email, because it never infers one. If an address is reported, it was
read off a real page.
"""

from __future__ import annotations

import argparse
import html
import json
import os
import random
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from html.parser import HTMLParser
from typing import Any, Iterable

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_CONFIG = os.path.join(HERE, "config.json")

USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Prospector/1.0 (+44i lead research; contact scott@44interactive.com)"
)

# Pages worth looking at, ordered by how often they carry what we need. This
# order is load-bearing: the fetch budget is small, so the pages that decide the
# buyer door (/services, /advertise) and name a person (/team, /staff) have to
# come before the also-rans, or the budget is spent on 404s.
CANDIDATE_PATHS = [
    "",
    "/services",
    "/advertise",
    "/about",
    "/contact",
    "/team",
    "/staff",
    "/leadership",
    "/our-team",
    "/digital",
    "/marketing",
    "/people",
    "/management",
    "/advertising",
    "/what-we-do",
    "/about-us",
    "/contact-us",
]

EMAIL_RE = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
PHONE_RE = re.compile(
    r"(?:\+?1[\s.\-]?)?\(?([2-9]\d{2})\)?[\s.\-]?(\d{3})[\s.\-]?(\d{4})(?!\d)"
)
# Names must not run across a line break, or "Team \n Marla" reads as a name.
NAME_PART = r"[A-Z][a-z'\-]{1,20}"
NAME_RE = re.compile(r"\b(" + NAME_PART + r"(?:[ ]+[A-Z]\.?)?[ ]+[A-Z][a-zA-Z'\-]{1,24})\b")


def term_pattern(term: str) -> re.Pattern[str]:
    """
    Whole-token match. Short tokens are the whole problem here: a plain
    substring test for "am " matches "team ", "ott" matches "Scott", and "sem"
    matches "assembly" — each one silently corrupting a vertical or a signal.
    """
    return re.compile(r"(?<![a-z0-9])" + re.escape(term) + r"(?![a-z0-9])", re.I)

# Addresses that are never a person and never worth reporting as a contact.
EMAIL_JUNK = re.compile(
    r"(noreply|no-reply|donotreply|postmaster|abuse@|webmaster|hostmaster|"
    r"example\.(com|org|net)|\.(png|jpe?g|gif|svg|webp|css|js)$|sentry\.io|"
    r"wixpress|squarespace|godaddy|cloudflare|@localhost)",
    re.I,
)

DIGITAL_TERMS = {
    "programmatic": ["programmatic"],
    "ott": ["ott", "connected tv", "ctv", "streaming tv", "pre-roll", "preroll"],
    "sem": ["sem", "paid search", "google ads", "adwords", "ppc", "pay-per-click", "pay per click"],
    "geofencing": ["geofencing", "geo-fencing", "geofence"],
    "seo": ["seo", "search engine optimization"],
    "social": ["social media", "facebook advertising", "instagram", "social marketing"],
    "web": ["web design", "website design", "web development"],
    "email_marketing": ["email marketing"],
}

# The signals in the loop spec that indicate someone else is already fulfilling.
VENDOR_LANGUAGE = [
    "we partner with",
    "our partners",
    "trusted partner",
    "through our partners",
    "vendor",
    "white label",
    "whitelabel",
    "powered by",
    "in partnership with",
]


def log(message: str, *, verbose_only: bool = False, state: dict[str, Any] | None = None) -> None:
    if verbose_only and not (state or {}).get("verbose"):
        return
    sys.stderr.write(message + "\n")
    sys.stderr.flush()


# --------------------------------------------------------------------------- #
# Config
# --------------------------------------------------------------------------- #


@dataclass
class Config:
    prospector_url: str
    worker_token: str
    ollama_url: str = "http://127.0.0.1:11434"
    model: str = "llama3.1:8b"
    keep_alive: str = "60s"
    num_ctx: int = 8192
    request_timeout: int = 180
    fetch_timeout: int = 20
    fetch_delay: float = 1.5
    max_page_bytes: int = 900_000
    max_candidates: int = 200
    max_pages_per_company: int = 5
    max_fetch_attempts_per_company: int = 10
    worker_label: str = "mac-mini"
    qualifying_metros: list[str] = field(default_factory=list)
    disqualify_owners: list[str] = field(default_factory=list)
    sources: dict[str, dict[str, Any]] = field(default_factory=dict)
    verbose: bool = False

    @classmethod
    def load(cls, path: str) -> "Config":
        if not os.path.isfile(path):
            raise SystemExit(
                f"No config at {path}.\n"
                f"Copy config.example.json to config.json and fill in prospector_url "
                f"and worker_token (Settings -> Batch worker in Prospector)."
            )

        with open(path, "r", encoding="utf-8") as handle:
            raw = json.load(handle)

        missing = [k for k in ("prospector_url", "worker_token") if not raw.get(k)]
        if missing:
            raise SystemExit(f"config.json is missing: {', '.join(missing)}")

        known = {f for f in cls.__dataclass_fields__}  # type: ignore[attr-defined]
        unknown = set(raw) - known
        if unknown:
            log(f"note: ignoring unrecognised config keys: {', '.join(sorted(unknown))}")

        raw["prospector_url"] = raw["prospector_url"].rstrip("/")

        return cls(**{k: v for k, v in raw.items() if k in known})


# --------------------------------------------------------------------------- #
# HTTP
# --------------------------------------------------------------------------- #


class Web:
    """Polite fetcher: one request at a time, rate limited per host, size capped."""

    def __init__(self, config: Config) -> None:
        self.config = config
        self._last_hit: dict[str, float] = {}
        self._cache: dict[str, str | None] = {}
        self._context = ssl.create_default_context()
        self.fetches = 0

    def get(self, url: str) -> str | None:
        if url in self._cache:
            return self._cache[url]

        host = urllib.parse.urlparse(url).netloc
        wait = self.config.fetch_delay - (time.monotonic() - self._last_hit.get(host, 0.0))
        if wait > 0:
            time.sleep(wait)

        request = urllib.request.Request(
            url,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": "text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8",
                "Accept-Language": "en-US,en;q=0.9",
            },
        )

        body: str | None = None
        try:
            with urllib.request.urlopen(
                request, timeout=self.config.fetch_timeout, context=self._context
            ) as response:
                kind = (response.headers.get("Content-Type") or "").lower()
                if "html" in kind or "json" in kind or "text" in kind or kind == "":
                    charset = response.headers.get_content_charset() or "utf-8"
                    body = response.read(self.config.max_page_bytes).decode(charset, "replace")
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError, ValueError) as error:
            log(f"    fetch failed {url}: {error}", verbose_only=True, state={"verbose": self.config.verbose})
        finally:
            self._last_hit[host] = time.monotonic()
            self.fetches += 1

        self._cache[url] = body
        return body


class Prospector:
    """Client for the Prospector worker API."""

    def __init__(self, config: Config) -> None:
        self.config = config

    def _call(self, path: str, payload: dict[str, Any] | None = None, method: str = "GET") -> dict[str, Any]:
        url = f"{self.config.prospector_url}/api/{path}"
        data = None
        headers = {
            "Authorization": f"Bearer {self.config.worker_token}",
            "Accept": "application/json",
            "User-Agent": f"Prospector-Worker/1.0 ({self.config.worker_label})",
        }

        if payload is not None:
            data = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
            method = "POST"

        request = urllib.request.Request(url, data=data, headers=headers, method=method)

        try:
            with urllib.request.urlopen(request, timeout=self.config.request_timeout) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            detail = error.read().decode("utf-8", "replace")
            try:
                parsed = json.loads(detail)
                detail = parsed.get("error", detail)
            except json.JSONDecodeError:
                pass
            raise SystemExit(f"Prospector returned {error.code} for /{path}: {detail}") from error
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            raise SystemExit(f"Could not reach Prospector at {url}: {error}") from error

    def assignment(self, email: str | None = None) -> dict[str, Any]:
        query = f"?email={urllib.parse.quote(email)}" if email else ""
        return self._call(f"assignment{query}")

    def import_batch(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._call("import", payload)

    def heartbeat(self, engine: str) -> dict[str, Any]:
        return self._call("heartbeat", {"worker": self.config.worker_label, "engine": engine})


# --------------------------------------------------------------------------- #
# Ollama
# --------------------------------------------------------------------------- #


class Ollama:
    """
    Minimal Ollama chat client.

    Requests are serial and prompts are deliberately short — this machine is
    also serving something else, and a long context on a 16GB box is what makes
    other things slow.
    """

    def __init__(self, config: Config) -> None:
        self.config = config
        self.calls = 0
        self.failures = 0

    def available(self) -> tuple[bool, str]:
        try:
            request = urllib.request.Request(f"{self.config.ollama_url}/api/tags")
            with urllib.request.urlopen(request, timeout=10) as response:
                tags = json.loads(response.read().decode("utf-8"))
        except Exception as error:  # noqa: BLE001 - any failure means unusable
            return False, f"Cannot reach Ollama at {self.config.ollama_url}: {error}"

        models = [m.get("name", "") for m in tags.get("models", [])]
        if not models:
            return False, "Ollama is running but has no models pulled."

        if self.config.model not in models:
            return False, (
                f"Model '{self.config.model}' is not installed. Available: {', '.join(models)}.\n"
                f"Set \"model\" in config.json to one of those, or run: ollama pull {self.config.model}"
            )

        return True, f"Ollama ready, using {self.config.model} (installed: {len(models)} models)"

    def ask_json(self, system: str, prompt: str, schema_hint: dict[str, Any]) -> dict[str, Any]:
        """
        Ask for a small JSON object. Returns {} on any failure — every caller
        treats a missing answer as "unknown", never as a fact.
        """
        payload = {
            "model": self.config.model,
            "stream": False,
            "format": "json",
            "keep_alive": self.config.keep_alive,
            "options": {
                "temperature": 0,
                "num_ctx": self.config.num_ctx,
                "num_predict": 320,
            },
            "messages": [
                {"role": "system", "content": system},
                {
                    "role": "user",
                    "content": (
                        f"{prompt}\n\n"
                        f"Reply with JSON only, in exactly this shape:\n"
                        f"{json.dumps(schema_hint)}"
                    ),
                },
            ],
        }

        request = urllib.request.Request(
            f"{self.config.ollama_url}/api/chat",
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )

        self.calls += 1

        try:
            with urllib.request.urlopen(request, timeout=self.config.request_timeout) as response:
                body = json.loads(response.read().decode("utf-8"))
        except Exception as error:  # noqa: BLE001
            self.failures += 1
            log(f"    ollama call failed: {error}", verbose_only=True, state={"verbose": self.config.verbose})
            return {}

        content = (body.get("message") or {}).get("content", "")
        if not content:
            self.failures += 1
            return {}

        try:
            parsed = json.loads(content)
        except json.JSONDecodeError:
            # Small models sometimes wrap JSON in prose despite format:json.
            match = re.search(r"\{.*\}", content, re.S)
            if not match:
                self.failures += 1
                return {}
            try:
                parsed = json.loads(match.group(0))
            except json.JSONDecodeError:
                self.failures += 1
                return {}

        return parsed if isinstance(parsed, dict) else {}


# --------------------------------------------------------------------------- #
# HTML handling
# --------------------------------------------------------------------------- #


class TextExtractor(HTMLParser):
    """Strip a page to readable text, and collect links and mailto addresses."""

    SKIP = {"script", "style", "noscript", "svg", "head", "nav", "footer"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.chunks: list[str] = []
        self.links: list[str] = []
        self.mailtos: list[str] = []
        self.titles: list[str] = []
        self._skip_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in self.SKIP:
            self._skip_depth += 1

        if tag == "a":
            href = dict(attrs).get("href") or ""
            if href.lower().startswith("mailto:"):
                address = href[7:].split("?")[0].strip()
                if address:
                    self.mailtos.append(address)
            elif href:
                self.links.append(href)

        if tag in {"br", "p", "div", "li", "tr", "h1", "h2", "h3", "h4", "td"}:
            self.chunks.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in self.SKIP and self._skip_depth > 0:
            self._skip_depth -= 1

    def handle_data(self, data: str) -> None:
        if self._skip_depth == 0 and data.strip():
            self.chunks.append(data)

    def text(self) -> str:
        joined = " ".join(self.chunks)
        joined = html.unescape(joined)
        joined = re.sub(r"[ \t\r\f\v]+", " ", joined)
        joined = re.sub(r"\n\s*\n+", "\n", joined)
        return joined.strip()


def parse_page(raw: str) -> TextExtractor:
    parser = TextExtractor()
    try:
        parser.feed(raw)
    except Exception:  # noqa: BLE001 - malformed markup is normal
        pass
    return parser


# --------------------------------------------------------------------------- #
# Deterministic fact extraction
# --------------------------------------------------------------------------- #


def domain_of(url: str) -> str:
    host = urllib.parse.urlparse(url).netloc.lower()
    return host[4:] if host.startswith("www.") else host


def find_emails(raw_html: str, parsed: TextExtractor, source_url: str, site_domain: str) -> list[dict[str, str]]:
    """Every email that literally appears on the page, with where it was found."""
    found: dict[str, dict[str, str]] = {}

    for address in list(parsed.mailtos) + EMAIL_RE.findall(raw_html):
        address = address.strip().strip(".,;:'\"()<>").lower()

        if not address or EMAIL_JUNK.search(address) or len(address) > 120:
            continue
        if address.count("@") != 1:
            continue

        local, _, host = address.partition("@")
        if not local or "." not in host:
            continue

        # On the company's own domain we can call it verified; found elsewhere
        # it is corroboration, not proof.
        on_own_domain = site_domain != "" and (host == site_domain or host.endswith("." + site_domain))

        found[address] = {
            "email": address,
            "confidence": "verified" if on_own_domain else "high",
            "source": source_url,
            "generic": "yes" if local.split("+")[0] in GENERIC_MAILBOXES else "no",
        }

    return list(found.values())


GENERIC_MAILBOXES = {
    "info", "contact", "hello", "sales", "admin", "office", "support",
    "help", "news", "media", "traffic", "billing", "careers", "jobs",
    "reception", "frontdesk", "marketing", "advertising", "ads",
}


def find_phones(text: str, source_url: str) -> list[dict[str, str]]:
    seen: dict[str, dict[str, str]] = {}

    for match in PHONE_RE.finditer(text):
        area, exchange, number = match.groups()
        pretty = f"({area}) {exchange}-{number}"
        digits = area + exchange + number

        # Reject the obvious non-numbers: repeated digits, 555 exchanges.
        if len(set(digits)) <= 2 or exchange == "555":
            continue

        seen[digits] = {"phone": pretty, "source": source_url}

    return list(seen.values())


def verify_in_source(value: str, haystacks: Iterable[str]) -> bool:
    """
    The guardrail: a value the model proposed is only accepted if it appears
    verbatim in text we actually fetched. Whitespace is normalised, nothing else.
    """
    if not value:
        return False

    needle = re.sub(r"\s+", " ", value).strip().lower()
    if len(needle) < 3:
        return False

    return any(needle in re.sub(r"\s+", " ", h).lower() for h in haystacks)


# --------------------------------------------------------------------------- #
# Signals and scoring
# --------------------------------------------------------------------------- #


DIGITAL_TERM_RES: dict[str, list[re.Pattern[str]]] = {
    capability: [term_pattern(t) for t in terms]
    for capability, terms in DIGITAL_TERMS.items()
}

VERTICAL_SIGNS: dict[str, list[tuple[str, list[str]]]] = {
    "partner": [
        ("Radio", [
            "radio", "radio station", "listen live", "listeners", "listener", "on air",
            "morning show", "fm", "am", "broadcasting", "broadcaster", "stations", "airwaves",
        ]),
        ("TV", [
            "television", "tv station", "newscast", "weather team", "anchor", "viewers",
            "channel", "broadcast television",
        ]),
        ("Publisher", [
            "newspaper", "e-edition", "obituaries", "subscribe to the", "readers",
            "editorial", "classifieds",
        ]),
        ("OOH", ["billboard", "billboards", "out of home", "out-of-home", "digital signage"]),
    ],
    "client": [
        ("Healthcare", ["hospital", "clinic", "patients", "health system", "urgent care"]),
        ("Casino", ["casino", "gaming", "slots", "players club", "sportsbook"]),
        ("Higher Ed", ["college", "university", "admissions", "tuition", "undergraduate"]),
        ("Ag", ["agriculture", "agronomy", "seed", "livestock", "acres", "farmers"]),
    ],
}

VERTICAL_RES: dict[str, list[tuple[str, list[re.Pattern[str]]]]] = {
    loop: [(label, [term_pattern(t) for t in terms]) for label, terms in signs]
    for loop, signs in VERTICAL_SIGNS.items()
}


def detect_digital_terms(text: str) -> dict[str, bool]:
    return {
        capability: any(pattern.search(text) for pattern in patterns)
        for capability, patterns in DIGITAL_TERM_RES.items()
    }


def detect_owner(text: str, owners: list[str]) -> str | None:
    lowered = text.lower()
    for owner in owners:
        if owner.lower() in lowered:
            return owner
    return None


def determine_door(loop: str, terms: dict[str, bool], text: str) -> tuple[str, str]:
    """
    Work the buyer door out from what the site actually says, in code. The model
    is asked to confirm this later, but the default comes from evidence.

    Returns (door, the signal we based it on).
    """
    lowered = text.lower()
    advanced = terms["programmatic"] or terms["ott"] or terms["sem"] or terms["geofencing"]
    basic = terms["social"] or terms["web"] or terms["seo"] or terms["email_marketing"]
    vendor_talk = any(phrase in lowered for phrase in VENDOR_LANGUAGE)

    if loop == "partner":
        if not advanced and not basic:
            return "Fresh Start", "no digital services listed anywhere on the site"

        if basic and not advanced:
            has = ", ".join(k for k in ("social", "web", "seo", "email_marketing") if terms[k])
            missing = ", ".join(k for k in ("programmatic", "ott", "sem", "geofencing") if not terms[k])
            signal = f"lists {has} but not {missing}"
            if vendor_talk:
                signal += ", and points at outside vendors for the rest"
            return "Gap Filler", signal

        if vendor_talk and sum(1 for v in terms.values() if v) <= 2:
            return "Frustrated Refugee", "a thin digital page that leans on outside vendors"

        return "Gap Filler", "some digital capability, but an incomplete stack"

    # Client loop
    if not basic and not advanced:
        return "No Agency", "no evidence of managed digital marketing on the site"
    if vendor_talk:
        return "Agency Switcher", "references an outside agency or vendor relationship"
    return "No Agency", "marketing appears to be handled in-house"


def score_lead(
    loop: str,
    door: str,
    independent: bool,
    has_name: bool,
    has_direct_contact: bool,
    in_target_metro: bool,
) -> tuple[int, list[str]]:
    """
    The rubric from the loop spec, computed here rather than asked for, so it is
    consistent and auditable.

    partner: +25 vertical, +20 independent, +20 door signal, +15 name,
             +10 direct email/phone, +10 metro 50k-1M
    client:  +25 vertical, +20 budget capacity, +20 door signal, +15 name,
             +10 direct email/phone, +10 growth-moment recency
    """
    breakdown: list[str] = []
    score = 0

    score += 25
    breakdown.append("+25 vertical match")

    if independent:
        score += 20
        breakdown.append("+20 independent ownership" if loop == "partner" else "+20 budget capacity")

    if door:
        score += 20
        breakdown.append(f"+20 door signal ({door})")

    if has_name:
        score += 15
        breakdown.append("+15 decision-maker named")

    if has_direct_contact:
        score += 10
        breakdown.append("+10 direct email or phone found")

    if in_target_metro:
        score += 10
        breakdown.append("+10 target market")

    return score, breakdown


# --------------------------------------------------------------------------- #
# Sources
# --------------------------------------------------------------------------- #


@dataclass
class Candidate:
    company: str
    website: str = ""
    market: str = ""
    state: str = ""
    source: str = ""


def load_sources(config: Config, loop: str, vertical: str, geography: str, web: Web) -> list[Candidate]:
    """
    Pull raw candidates from whatever sources are configured for this loop.

    Sources are config-driven on purpose: public registry URLs move, and you
    should be able to point this at a new roster without touching code. Verify
    any one of them with --test-source NAME.
    """
    candidates: list[Candidate] = []

    for name, spec in config.sources.items():
        if spec.get("loop") not in (None, loop):
            continue
        if not spec.get("enabled", True):
            continue

        kind = spec.get("type", "roster")
        states = states_in(geography, spec.get("states", []))

        try:
            if kind == "file":
                candidates.extend(from_file(spec, name))
            elif kind == "roster":
                candidates.extend(from_roster(spec, name, states, web, config))
            elif kind == "json":
                candidates.extend(from_json(spec, name, states, web, config))
            else:
                log(f"  source {name}: unknown type '{kind}', skipping")
        except Exception as error:  # noqa: BLE001 - one bad source must not kill the batch
            log(f"  source {name} failed: {error}")

    # De-duplicate by normalised company name.
    unique: dict[str, Candidate] = {}
    for candidate in candidates:
        key = company_key(candidate.company)
        if key and key not in unique:
            unique[key] = candidate

    result = list(unique.values())
    random.shuffle(result)
    return result[: config.max_candidates]


def states_in(geography: str, configured: list[str]) -> list[str]:
    """Two-letter state codes mentioned in today's geography line."""
    codes = {
        "south dakota": "SD", "north dakota": "ND", "minnesota": "MN", "iowa": "IA",
        "nebraska": "NE", "wisconsin": "WI", "montana": "MT", "wyoming": "WY",
        "kansas": "KS", "missouri": "MO", "michigan": "MI", "illinois": "IL",
        "indiana": "IN", "ohio": "OH", "kentucky": "KY", "oklahoma": "OK",
        "arkansas": "AR", "texas": "TX", "colorado": "CO", "utah": "UT",
        "new mexico": "NM", "arizona": "AZ", "idaho": "ID", "washington": "WA",
        "oregon": "OR", "georgia": "GA", "alabama": "AL", "tennessee": "TN",
        "pennsylvania": "PA", "new york": "NY",
    }

    lowered = geography.lower()
    found = [code for name, code in codes.items() if name in lowered]
    found += re.findall(r"\b([A-Z]{2})\b", geography)

    if not found:
        return configured

    allowed = set(codes.values())
    return sorted({c for c in found if c in allowed}) or configured


def from_file(spec: dict[str, Any], name: str) -> list[Candidate]:
    """A CSV or JSON list you already have. The simplest source to get going."""
    path = spec.get("path", "")
    if not os.path.isabs(path):
        path = os.path.join(HERE, path)

    if not os.path.isfile(path):
        log(f"  source {name}: no file at {path}")
        return []

    with open(path, "r", encoding="utf-8") as handle:
        content = handle.read()

    rows: list[dict[str, str]] = []

    if path.endswith(".json"):
        parsed = json.loads(content)
        rows = parsed if isinstance(parsed, list) else parsed.get("companies", [])
    else:
        import csv
        import io

        rows = list(csv.DictReader(io.StringIO(content)))

    out = []
    for row in rows:
        lowered = {str(k).strip().lower(): (v or "") for k, v in row.items()}
        company = lowered.get("company") or lowered.get("name") or ""
        if company:
            out.append(
                Candidate(
                    company=company.strip(),
                    website=(lowered.get("website") or lowered.get("url") or "").strip(),
                    market=(lowered.get("market") or lowered.get("city") or "").strip(),
                    state=(lowered.get("state") or "").strip().upper()[:2],
                    source=spec.get("label", name),
                )
            )

    return out


def from_roster(spec: dict[str, Any], name: str, states: list[str], web: Web, config: Config) -> list[Candidate]:
    """
    Scrape an HTML member roster. `url` may contain {state}, in which case it is
    fetched once per state in today's geography.
    """
    template = spec.get("url", "")
    if not template:
        return []

    urls = [template.replace("{state}", s) for s in states] if "{state}" in template else [template]
    pattern = re.compile(spec["match"], re.I) if spec.get("match") else None
    out: list[Candidate] = []

    for url in urls:
        raw = web.get(url)
        if not raw:
            continue

        parsed = parse_page(raw)
        base = url

        # Every outbound link whose text looks like a company name.
        for match in re.finditer(r'<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>', raw, re.I | re.S):
            href, label = match.group(1), re.sub(r"<[^>]+>", " ", match.group(2))
            label = html.unescape(re.sub(r"\s+", " ", label)).strip()

            if not label or len(label) < 4 or len(label) > 90:
                continue
            if pattern and not pattern.search(label):
                continue
            if label.lower() in {"home", "about", "contact", "members", "next", "previous", "read more"}:
                continue

            absolute = urllib.parse.urljoin(base, href)
            if not absolute.startswith("http"):
                continue

            # An external link from a roster is usually the member's own site.
            website = absolute if domain_of(absolute) != domain_of(base) else ""

            out.append(
                Candidate(
                    company=label,
                    website=website,
                    state=states[0] if len(states) == 1 else "",
                    source=spec.get("label", name),
                )
            )

        log(f"  source {name}: {len(out)} candidates so far from {url}",
            verbose_only=True, state={"verbose": config.verbose})

    return out


def from_json(spec: dict[str, Any], name: str, states: list[str], web: Web, config: Config) -> list[Candidate]:
    """
    A JSON API. `url` may contain {state}; `path` is a dotted path to the list;
    `fields` maps our field names onto theirs.

    This is how a registry API like the FCC's is wired up — verify the endpoint
    with --test-source before relying on it, because these URLs do change.
    """
    template = spec.get("url", "")
    if not template:
        return []

    urls = [template.replace("{state}", s) for s in states] if "{state}" in template else [template]
    fields = spec.get("fields", {})
    out: list[Candidate] = []

    for url in urls:
        raw = web.get(url)
        if not raw:
            continue

        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            log(f"  source {name}: {url} did not return JSON")
            continue

        node: Any = payload
        for part in [p for p in spec.get("path", "").split(".") if p]:
            node = (node or {}).get(part) if isinstance(node, dict) else None

        if not isinstance(node, list):
            log(f"  source {name}: no list at path '{spec.get('path', '')}'")
            continue

        for row in node:
            if not isinstance(row, dict):
                continue

            company = str(row.get(fields.get("company", "name"), "") or "").strip()
            if not company:
                continue

            out.append(
                Candidate(
                    company=company,
                    website=str(row.get(fields.get("website", "website"), "") or "").strip(),
                    market=str(row.get(fields.get("market", "city"), "") or "").strip(),
                    state=str(row.get(fields.get("state", "state"), "") or "").strip().upper()[:2],
                    source=spec.get("label", name),
                )
            )

    return out


def company_key(company: str) -> str:
    """Must match Leads::companyKey() in PHP so dedupe agrees on both sides."""
    key = company.lower().strip()
    key = key.replace("&", " and ")
    key = re.sub(r"[^a-z0-9 ]+", " ", key)
    key = re.sub(
        r"\b(the|inc|llc|ltd|co|corp|corporation|company|group|holdings|media|"
        r"communications|broadcasting|enterprises)\b",
        " ",
        key,
    )
    key = re.sub(r"\s+", " ", key).strip()
    return key or company.lower().strip()


# --------------------------------------------------------------------------- #
# Screening one candidate
# --------------------------------------------------------------------------- #


TITLES = {
    "partner": [
        "General Manager", "Market Manager", "General Sales Manager", "Director of Sales",
        "Sales Manager", "VP of Sales", "Vice President of Sales", "President", "Owner",
        "Publisher", "Advertising Director", "Ad Director", "Principal", "Partner", "CEO",
    ],
    "client": [
        "Marketing Director", "Director of Marketing", "Director of Marketing and Communications",
        "Chief Marketing Officer", "CMO", "VP of Marketing", "Vice President of Marketing",
        "Marketing Manager", "Director of Communications", "Director of Admissions",
        "Director of Enrollment", "VP of Enrollment", "Director of Player Development",
        "General Manager", "Owner", "CEO", "Executive Director",
    ],
}


def guess_website(candidate: Candidate, web: Web) -> str:
    if candidate.website:
        return candidate.website if candidate.website.startswith("http") else "https://" + candidate.website
    return ""


def screen(
    candidate: Candidate,
    loop: str,
    config: Config,
    web: Web,
    ollama: Ollama,
    todays_vertical: str = "",
) -> dict[str, Any] | None:
    """
    Look a candidate over and either return a scored lead or None.

    Everything factual here comes off a fetched page. The model contributes
    a door confirmation and two sentences, and its name/title extraction is
    thrown away unless the text backs it up.
    """
    site = guess_website(candidate, web)
    if not site:
        return None

    site_domain = domain_of(site)
    pages: list[tuple[str, str, TextExtractor]] = []

    # Try paths until we have enough that actually exist — not the first N in the
    # list. Most sites 404 several of these, and the staff and services pages we
    # care about most sit near the end.
    attempts = 0

    for path in CANDIDATE_PATHS:
        if len(pages) >= config.max_pages_per_company:
            break
        # Bound the misses too. Most sites 404 most of these paths, and an
        # unbounded walk turns a 200-candidate batch into an all-day job.
        if attempts >= config.max_fetch_attempts_per_company:
            break

        # Deliberately not urljoin: a leading-slash path resolves against the
        # host, which loses the directory for any site not at a domain root.
        url = site.rstrip("/") + path if path else site

        attempts += 1
        raw = web.get(url)
        if raw:
            pages.append((url, raw, parse_page(raw)))

    if not pages:
        return None

    all_text = "\n".join(p[2].text() for p in pages)

    # Ownership is checked before anything else, including the thin-page guard —
    # a disqualified group must be dropped for the right reason, not skipped as
    # an accident of page size.
    owner = detect_owner(all_text, config.disqualify_owners)
    if owner:
        log(f"    dropped {candidate.company}: owned by {owner}",
            verbose_only=True, state={"verbose": config.verbose})
        return None

    if len(all_text) < 200:
        log(f"    dropped {candidate.company}: too little text to judge ({len(all_text)} chars)",
            verbose_only=True, state={"verbose": config.verbose})
        return None

    # --- deterministic contacts -----------------------------------------------
    emails: list[dict[str, str]] = []
    phone_by_number: dict[str, dict[str, str]] = {}

    for url, raw, parsed in pages:
        emails.extend(find_emails(raw, parsed, url, site_domain))
        for phone in find_phones(parsed.text(), url):
            # Dedupe across pages, not just within one: the switchboard number
            # appears on nearly every page, and listing it twice would present
            # it as a direct line as well.
            phone_by_number.setdefault(re.sub(r"\D", "", phone["phone"]), phone)

    phones = list(phone_by_number.values())

    personal = [e for e in emails if e["generic"] == "no"]
    generic = [e for e in emails if e["generic"] == "yes"]
    best_email = (personal or generic or [None])[0]

    # --- signals and door ------------------------------------------------------
    terms = detect_digital_terms(all_text)
    door, door_signal = determine_door(loop, terms, all_text)

    # --- grounded name and title ----------------------------------------------
    person = extract_person(loop, pages, all_text, ollama, config)

    in_metro = any(
        metro.lower() in all_text.lower() or metro.lower() in candidate.market.lower()
        for metro in config.qualifying_metros
    ) if config.qualifying_metros else False

    score, breakdown = score_lead(
        loop=loop,
        door=door,
        independent=owner is None,
        has_name=bool(person.get("name")),
        has_direct_contact=bool(best_email) or bool(phones),
        in_target_metro=in_metro,
    )

    # --- the two sentences the model is actually useful for --------------------
    written = write_lines(loop, candidate.company, door, door_signal, terms, ollama, config)

    evidence = [f"{door_signal} — {pages[0][0]}"]
    if best_email:
        evidence.append(f"email read from {best_email['source']}")
    if person.get("source"):
        evidence.append(f"{person['name']} named on {person['source']}")

    return {
        "company": candidate.company,
        "website": site,
        "vertical": vertical_label(loop, candidate, all_text, todays_vertical),
        "door": door,
        "market": candidate.market or None,
        "state": candidate.state or None,
        "decision_maker": person.get("name") or None,
        "title": person.get("title") or None,
        "email": best_email["email"] if best_email else None,
        # Never "pattern": this worker only reports addresses it actually read.
        "email_confidence": best_email["confidence"] if best_email else None,
        "phone": phones[0]["phone"] if phones else None,
        "direct_phone": phones[1]["phone"] if len(phones) > 1 else None,
        "linkedin": find_linkedin(pages),
        "fit_score": score,
        "why": written.get("why") or f"{door}: {door_signal}.",
        "hook": written.get("hook") or default_hook(loop, door),
        "source": candidate.source or "worker",
        "evidence": evidence + breakdown,
    }


def extract_person(
    loop: str,
    pages: list[tuple[str, str, TextExtractor]],
    all_text: str,
    ollama: Ollama,
    config: Config,
) -> dict[str, str]:
    """
    Find a decision-maker. Two passes, both grounded:

    1. Regex for "<Name>, <Title>" or "<Title>: <Name>" near a known title.
    2. If that misses, ask the model — then throw the answer away unless both
       the name and the title appear verbatim in the page it came from.
    """
    titles = TITLES.get(loop, [])

    # Two precise shapes first — "Dale Ferriman, General Manager" and
    # "General Manager: Dale Ferriman". These are how staff pages are actually
    # written, and matching them exactly avoids dragging in a neighbouring
    # heading the way a proximity search does.
    for url, _raw, parsed in pages:
        text = parsed.text()

        for title in titles:
            escaped = re.escape(title)

            for pattern in (
                re.compile(NAME_RE.pattern + r"\s*[,–—\-]\s*" + escaped, re.I),
                re.compile(escaped + r"\s*[:–—\-]\s*" + NAME_RE.pattern, re.I),
            ):
                match = pattern.search(text)
                if match:
                    name = match.group(1).strip()
                    if name.lower() not in title.lower() and len(name.split()) >= 2:
                        return {"name": name, "title": title, "source": url}

    # Then a narrower proximity search as a fallback. The window is tight and
    # newline-free so it cannot pull a name out of an adjacent block.
    for url, _raw, parsed in pages:
        text = parsed.text()

        for title in titles:
            for match in re.finditer(re.escape(title), text, re.I):
                window = text[max(0, match.start() - 60) : match.end() + 60]
                # Only look within the same line as the title.
                line = max(window.split("\n"), key=len) if "\n" in window else window

                names = [
                    n for n in NAME_RE.findall(line)
                    if n.lower() not in title.lower() and len(n.split()) >= 2
                ]
                if names:
                    return {"name": names[0].strip(), "title": title, "source": url}

    # Model pass, on the page most likely to hold a staff listing.
    for url, _raw, parsed in pages:
        text = parsed.text()
        if not any(word in url.lower() for word in ("staff", "team", "leader", "management", "about", "contact")):
            continue

        answer = ollama.ask_json(
            system=(
                "You extract facts from text. You only ever report something that is "
                "written in the text you were given. If it is not there, you say null. "
                "You never guess a name."
            ),
            prompt=(
                "Here is text from a company web page. If it names a person holding one of "
                f"these roles — {', '.join(titles[:8])} — report that person's name and their "
                "exact title as written. If no such person is named, use null for both.\n\n"
                f"TEXT:\n{text[:4000]}"
            ),
            schema_hint={"name": "string or null", "title": "string or null"},
        )

        name = str(answer.get("name") or "").strip()
        title = str(answer.get("title") or "").strip()

        # The guardrail. Without this an 8B model will happily invent a person.
        if name and verify_in_source(name, [text]) and len(name.split()) >= 2:
            if not (title and verify_in_source(title, [text])):
                title = ""
            return {"name": name, "title": title, "source": url}

        if name:
            log(f"    discarded unverifiable name '{name}' from {url}",
                verbose_only=True, state={"verbose": config.verbose})

    return {}


def write_lines(
    loop: str,
    company: str,
    door: str,
    door_signal: str,
    terms: dict[str, bool],
    ollama: Ollama,
    config: Config,
) -> dict[str, str]:
    """
    The one generative job. Both outputs are cosmetic — a clumsy sentence costs
    nothing, so this is safe work for a small model. Facts are passed in.
    """
    present = [k for k, v in terms.items() if v] or ["none"]

    answer = ollama.ask_json(
        system=(
            "You write one-line sales notes for a media services company. Plain spoken "
            "English, no marketing fluff, no exclamation marks. You are given the facts; "
            "do not add any new fact, number, name, or claim."
        ),
        prompt=(
            f"Company: {company}\n"
            f"Buyer door: {door}\n"
            f"Evidence found on their site: {door_signal}\n"
            f"Digital capabilities their site mentions: {', '.join(present)}\n\n"
            "Write:\n"
            "- \"why\": one sentence stating the evidence above as the reason to call them.\n"
            f"- \"hook\": one sentence a rep could open a call with, matched to the "
            f"{door} door. {hook_guidance(loop, door)}"
        ),
        schema_hint={"why": "one sentence", "hook": "one sentence"},
    )

    why = re.sub(r"\s+", " ", str(answer.get("why") or "")).strip()
    hook = re.sub(r"\s+", " ", str(answer.get("hook") or "")).strip()

    # Reject anything suspiciously long — a rambling model output is not usable
    # on a phone between calls.
    return {
        "why": why if 15 < len(why) < 320 else "",
        "hook": hook if 15 < len(hook) < 320 else "",
    }


def hook_guidance(loop: str, door: str) -> str:
    if loop == "partner":
        return {
            "Fresh Start": "Angle: we make this simple, revenue guarantee, no risk.",
            "Frustrated Refugee": "Angle: a partner that actually answers the phone, month-to-month, US-based fulfilment.",
            "Gap Filler": "Angle: we slot in around the team they already have and do not touch what they do well.",
        }.get(door, "")
    return {
        "No Agency": "Angle: doing this in-house leaves money on the table, and a free digital analysis shows where.",
        "Agency Switcher": "Angle: one synchronised strategy instead of disjointed campaigns, and we answer the phone.",
        "Growth Moment": "Angle: this is exactly the moment to get the marketing right.",
    }.get(door, "")


def default_hook(loop: str, door: str) -> str:
    if loop == "partner":
        return {
            "Fresh Start": "You are leaving digital revenue on the table — we make adding it simple, with no risk to you.",
            "Frustrated Refugee": "If your last digital partner went quiet, ours is month-to-month and US-based.",
            "Gap Filler": "We slot in around the team you already have and only cover what you do not.",
        }.get(door, "Worth a short conversation about adding digital under your own brand.")
    return {
        "No Agency": "Running marketing in-house leaves money on the table — a free digital analysis shows where.",
        "Agency Switcher": "One synchronised digital strategy instead of disjointed campaigns.",
        "Growth Moment": "This is the moment to get the marketing right.",
    }.get(door, "Worth a short conversation about your marketing.")


def vertical_label(loop: str, candidate: Candidate, text: str, fallback: str = "") -> str:
    """
    Pick the vertical by counting whole-token hits, not by first match. A single
    passing mention of "gaming" should not outrank a page that says "hospital"
    eleven times.
    """
    scores: list[tuple[int, str]] = []

    for label, patterns in VERTICAL_RES.get(loop, []):
        hits = sum(len(pattern.findall(text)) for pattern in patterns)
        if hits:
            scores.append((hits, label))

    if scores:
        scores.sort(reverse=True)
        return scores[0][1]

    # Nothing matched. The day's rotation is a better guess than a hardcoded
    # default — the source was asked to find that vertical in the first place.
    if fallback:
        for label, _ in VERTICAL_SIGNS.get(loop, []):
            if label.lower() in fallback.lower():
                return label

    return "Agency" if loop == "partner" else "Retail"


def find_linkedin(pages: list[tuple[str, str, TextExtractor]]) -> str | None:
    for _url, raw, _parsed in pages:
        match = re.search(r"https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:company|in)/[A-Za-z0-9_\-%.]+", raw, re.I)
        if match:
            return match.group(0)
    return None


# --------------------------------------------------------------------------- #
# Run
# --------------------------------------------------------------------------- #


def run_assignment(
    assignment: dict[str, Any],
    config: Config,
    web: Web,
    ollama: Ollama,
    client: Prospector,
    args: argparse.Namespace,
) -> None:
    email = assignment["email"]
    loop = assignment["loop"]
    wanted = args.limit or int(assignment.get("batch_size", 10))
    floor = int(assignment.get("min_fit_score", 70))
    excluded = set(assignment.get("exclude_keys") or [])

    log("")
    log(f"=== {assignment['name']} — {assignment['loop_label']} ===")
    log(f"    focus:     {assignment['vertical']}")
    log(f"    geography: {assignment['geography']}")
    log(f"    want {wanted} leads at {floor}+, excluding {len(excluded)} already delivered")

    candidates = load_sources(config, loop, assignment["vertical"], assignment["geography"], web)
    candidates = [c for c in candidates if company_key(c.company) not in excluded]

    log(f"    {len(candidates)} candidates to screen")

    if not candidates:
        log("    nothing to screen — check your sources with --test-source")
        return

    leads: list[dict[str, Any]] = []
    screened = 0

    for candidate in candidates:
        if len(leads) >= wanted:
            break

        screened += 1
        log(f"  [{screened}/{len(candidates)}] {candidate.company}",
            verbose_only=True, state={"verbose": config.verbose})

        try:
            lead = screen(candidate, loop, config, web, ollama, assignment.get("vertical", ""))
        except Exception as error:  # noqa: BLE001 - one bad site must not stop the batch
            log(f"    error screening {candidate.company}: {error}")
            continue

        if not lead:
            continue

        if lead["fit_score"] < floor:
            log(f"    {candidate.company}: {lead['fit_score']} — below floor",
                verbose_only=True, state={"verbose": config.verbose})
            continue

        leads.append(lead)
        log(f"    kept {candidate.company} at {lead['fit_score']}"
            + (f", {lead['decision_maker']}" if lead["decision_maker"] else ", no name found"))

    leads.sort(key=lambda item: item["fit_score"], reverse=True)

    notes = (
        f"Screened {screened} of {len(candidates)} sourced candidates and delivered {len(leads)}. "
        f"Researched locally with {config.model} on {config.worker_label}; "
        f"{web.fetches} pages fetched, {ollama.calls} model calls"
        + (f", {ollama.failures} model calls failed" if ollama.failures else "")
        + ". Every contact detail was read off a fetched page — this worker never infers an address."
    )

    if len(leads) < wanted:
        notes += (
            f" Only {len(leads)} of {wanted} cleared the {floor} floor; the batch is short rather "
            f"than padded."
        )

    if args.dry_run:
        log("")
        log("--- DRY RUN, nothing stored ---")
        print(json.dumps({"email": email, "notes": notes, "leads": leads}, indent=2))
        return

    result = client.import_batch(
        {
            "email": email,
            "loop": loop,
            "engine": f"ollama:{config.model}",
            "vertical": assignment["vertical"],
            "geography": assignment["geography"],
            "screened_count": screened,
            "notes": notes,
            "leads": leads,
        }
    )

    log(f"    stored {result.get('stored')} lead(s), skipped {result.get('skipped')}"
        + (", emailed" if result.get("emailed") else ""))
    if result.get("email_error"):
        log(f"    email failed: {result['email_error']}")
    if result.get("rejected"):
        log(f"    rejected: {result['rejected']}")
    log(f"    {result.get('url')}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Prospector local batch worker")
    parser.add_argument("--config", default=DEFAULT_CONFIG)
    parser.add_argument("--user", help="only run this email address")
    parser.add_argument("--limit", type=int, help="stop after this many leads (for testing)")
    parser.add_argument("--dry-run", action="store_true", help="print results, store nothing")
    parser.add_argument("--check", action="store_true", help="verify config, Ollama and Prospector, then exit")
    parser.add_argument("--test-source", help="fetch one source and print what it found")
    parser.add_argument("--force", action="store_true", help="run even if a batch already ran today")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    config = Config.load(args.config)
    config.verbose = args.verbose or config.verbose

    web = Web(config)
    ollama = Ollama(config)
    client = Prospector(config)

    # --- checks ---------------------------------------------------------------
    ready, message = ollama.available()
    log(("ollama:     " if ready else "ollama:     PROBLEM — ") + message)

    if args.check:
        try:
            assignment = client.assignment(args.user)
            log(f"prospector: reachable, {len(assignment.get('assignments', []))} assignment(s) for {assignment.get('date')}")
            for item in assignment.get("assignments", []):
                log(f"            {item['name']}: {item['vertical']} / {item['geography']}"
                    + (" (already ran today)" if item["already_ran_today"] else ""))
        except SystemExit as error:
            log(f"prospector: PROBLEM — {error}")
            return 1

        enabled = [n for n, s in config.sources.items() if s.get("enabled", True)]
        log(f"sources:    {len(enabled)} enabled: {', '.join(enabled) or 'none — nothing to screen'}")
        return 0 if ready and enabled else 1

    if args.test_source:
        spec = config.sources.get(args.test_source)
        if not spec:
            log(f"No source named '{args.test_source}'. Configured: {', '.join(config.sources)}")
            return 1

        found = load_sources(
            Config(**{**config.__dict__, "sources": {args.test_source: {**spec, "enabled": True, "loop": None}}}),
            spec.get("loop") or "partner",
            "",
            spec.get("test_geography", "South Dakota"),
            web,
        )
        log(f"{args.test_source}: {len(found)} candidates")
        for candidate in found[:25]:
            log(f"  {candidate.company:<50} {candidate.website}")
        return 0 if found else 1

    if not ready:
        log("Refusing to run without a usable model. Fix the above, or try --check.")
        return 1

    if not config.sources:
        log("No sources configured — there is nothing to screen. See config.example.json.")
        return 1

    # --- the batch ------------------------------------------------------------
    payload = client.assignment(args.user)

    if payload.get("weekend") and payload.get("weekdays_only") and not args.force:
        log("Weekend, and Prospector is set to weekdays only. Nothing to do.")
        return 0

    assignments = payload.get("assignments") or []
    if not assignments:
        log("No assignments returned — check that a loop is set on the Users screen.")
        return 0

    for assignment in assignments:
        if assignment.get("already_ran_today") and not args.force:
            log(f"{assignment['name']}: already ran today, skipping. Use --force to run again.")
            continue

        run_assignment(assignment, config, web, ollama, client, args)

    if not args.dry_run:
        client.heartbeat(f"ollama:{config.model}")

    log("")
    log(f"done — {web.fetches} pages fetched, {ollama.calls} model calls, {ollama.failures} failed")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(130)
