# Prospector worker

Runs the daily prospecting batch on your own machine, against your own Ollama, and
posts the finished leads up to Prospector. No Anthropic API key, no API spend.

Standard library Python only — nothing to `pip install`, which matters on a Mac
that is already running something else.

---

## Why it works this way

Prospector lives on the web host. Your Mac Mini is on your office network. The
obvious move — port-forward Ollama so the web host can call it — is a bad idea:
Ollama has no authentication, and exposed instances get found by scanners.

So the direction is inverted. **The Mini pulls its assignment, does the work, and
pushes results back.** Everything is outbound HTTPS. Nothing on your network is
exposed, and no router changes are needed.

```
   Mac Mini                                    Prospector (web host)
   ────────                                    ─────────────────────
   launchd 07:30
      │
      ├── GET  /api/assignment  ───────────▶   today's vertical, geography,
      │                                        and every company already sent
      ├── source candidates from registries
      ├── fetch pages, extract contacts
      ├── classify with local Ollama
      ├── score against the rubric
      │
      └── POST /api/import      ───────────▶   leads stored, deduped, emailed
```

## Why a small local model is safe here

A 7–8B model will invent a marketing director and an email address without
hesitating. A fabricated contact is worse than no contact: it wastes a call, and
a bounced address damages your sending domain.

So the model is never the source of a fact. It only:

1. answers narrow questions about text it was just handed, and
2. writes the "why them" line and the opening hook from facts already established.

Everything factual is grounded in code:

| Fact | Where it comes from |
|---|---|
| Company name | the source registry — never the model |
| Ownership disqualifier | string match against `disqualify_owners` |
| Email, phone | regex over fetched HTML, with the URL recorded |
| Name, title | the model may propose one, but it is **discarded unless it appears verbatim** in the page it came from |
| Fit score | computed here from the rubric |
| Buyer door | keyword signals on the fetched text |

One consequence worth knowing: **this worker never reports a `pattern`
confidence email**, because it never infers one. If an address comes back, it was
read off a real page. That is stricter than the API engine.

---

## Setup

### 1. Copy the worker over

```bash
mkdir -p /Users/Shared/prospector-worker
cd /Users/Shared/prospector-worker
# copy prospector_worker.py, config.example.json and com.44i.prospector.plist here
cp config.example.json config.json
```

### 2. Fill in `config.json`

Two values are required. Both come from Prospector → **Settings → Batch worker**:

```json
{
  "prospector_url": "https://prospector.yourdomain.com",
  "worker_token": "…",
  "model": "llama3.1:8b"
}
```

Set `model` to something you already have — `ollama list` shows them.

### 3. Set the engine in Prospector

**Settings → What runs the batch → External worker.** This stops the morning cron
from calling the paid API, so nothing is spent.

### 4. Check it

```bash
python3 prospector_worker.py --check
```

That verifies Ollama is reachable, the model exists, Prospector answers, and at
least one source is configured. Fix anything it complains about before going on.

### 5. Give it something to screen

Sources are config-driven, because public registry URLs move and you should be
able to point this at a new roster without editing code.

The fastest start is a CSV you already have:

```csv
company,website,market,state
Prairie Sky Radio,https://prairieskyradio.com,"Sioux City, IA",IA
```

Save it as `candidates-partner.csv` — the `my_list` source in the example config
already points at it. Check a source with:

```bash
python3 prospector_worker.py --test-source my_list
```

For rosters and registry APIs, see the `sources` block in
`config.example.json`. The FCC entry there is a **starting point, not a verified
endpoint** — confirm it with `--test-source fcc_facilities` and correct the URL
if the FCC has moved it.

### 6. Dry run, then live

```bash
python3 prospector_worker.py --user billy@44idigital.com --limit 3 --dry-run --verbose
python3 prospector_worker.py --user billy@44idigital.com --limit 3
```

`--dry-run` prints exactly what would be stored without storing anything. Use it
freely; it costs nothing.

### 7. Schedule it

```bash
cp com.44i.prospector.plist ~/Library/LaunchAgents/
# edit the paths inside first
launchctl load ~/Library/LaunchAgents/com.44i.prospector.plist
launchctl start com.44i.prospector      # run once now, to prove it works
```

**The Mac has to be awake at 07:30 or nothing fires.** Either disable sleep, or:

```bash
sudo pmset repeat wakeorpoweron MTWRF 07:25:00
```

Prospector flags a worker that stops checking in, so a sleeping Mac shows up on
the Settings screen rather than as leads quietly not arriving.

---

## Living alongside another Ollama service

This Mini is already serving `ask.fourge.com`, and 16GB does not go far.

- **Use a model that service already has loaded** where you can. Requesting a
  different one makes Ollama evict and reload, which is slow for you and slow for
  it.
- `keep_alive` controls how long Ollama holds *your* model after the batch.
  Short (`60s`, the default) releases the RAM promptly — right when your model is
  a different one. If it is the **same** model the other service uses, set it to
  `5m` or longer so you are not evicting a model that service needs.
- Requests are serial and prompts are deliberately short — one page at a time,
  `num_ctx` 8192. Long contexts are what actually make a 16GB machine thrash.
- The launchd job runs at low priority and low-priority IO.
- 07:30 is a good slot precisely because the other service is probably quiet.

If you see the other service slow down during a batch, the first thing to try is
pointing both at the same model.

---

## Commands

| Command | What it does |
|---|---|
| `--check` | Verify config, Ollama, Prospector and sources, then exit |
| `--dry-run` | Full run, print the leads, store nothing |
| `--user EMAIL` | Only run for one person |
| `--limit N` | Stop after N leads — good for a quick test |
| `--test-source NAME` | Fetch one source and print what it found |
| `--force` | Run even if a batch already ran today |
| `--verbose` | Show per-candidate decisions, including what was discarded |

## Tuning

| Setting | Effect |
|---|---|
| `max_candidates` | How many raw candidates to pull before screening. More coverage, longer run. |
| `max_pages_per_company` | Pages fetched per company. 5 is usually enough for the door signal and a name. |
| `max_fetch_attempts_per_company` | Caps the 404s too, so a site with no `/team` does not burn the budget. |
| `fetch_delay` | Seconds between requests to the same host. Do not drop below 1 — be a good citizen. |
| `qualifying_metros` | Awards the +10 target-market point. Add the markets you actually want. |
| `disqualify_owners` | Candidates whose site mentions one of these are dropped before scoring. |

## When a batch comes back short

That is the design, not a fault — the loops are specified never to pad. The
batch notes on the Prospector batch screen say how many were screened and how
many cleared the floor. If it is consistently short:

1. Add more sources or raise `max_candidates` — usually the real cause.
2. Check `--verbose` output for what is being dropped and why.
3. If lots of leads are missing names, the sites probably do not have staff
   pages. That costs 15 points each, so the floor bites. Either accept a lower
   `min_fit_score` in Prospector, or accept shorter batches.
