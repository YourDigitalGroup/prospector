# Prospector

Lead generation and lead management for 44i. Every weekday morning at 7:30 Central it runs each
seller's prospecting loop, stores the qualified leads, and emails them a brief they can work from
their phone. Leads are managed in a dashboard, and can be pushed into GoHighLevel.

Three loops ship with it, built from the prospecting specs already in use:

| Loop | Owner | Finds |
|---|---|---|
| **Partner Prospector** | Billy | Whitelabel reseller partners — independent TV/radio broadcasters, traditional ad agencies, local publishers, OOH operators |
| **Client Prospector** | Darren | Direct agency-of-record clients — healthcare, casinos, higher ed, ag, regional retail in the Upper Midwest |
| **Home Prospector** | Sara | Home trades and retail within 100 miles of Sioux Falls — contractors, specialty trades, design, furniture/paint/flooring retail, outdoor |

Darren's and Sara's loops overlap on paper, so the specs draw the line explicitly: home-related
categories inside 100 miles of Sioux Falls are Sara's, home retail beyond that ring stays with
Darren, and Darren's five verticals are his at any distance. A company on the line gets flagged for
Scott rather than called twice.

---

## Deploying

The repository is the document root. Pushing to `main` FTPs the whole tree to the server
(`.github/workflows/deploy.yml`), and `vendor/` is committed so nothing needs installing on the
host.

Requirements: **PHP 8.1+** with `pdo_sqlite`, `curl`, `sodium`, and `mbstring`. Every one of those
is standard on cPanel.

There is nothing to configure to get it running. On first request it creates its own database, its
own encryption key, and the three accounts. Then:

1. Sign in as `scott@44interactive.com` with the password `44i123`.
2. **Settings → What runs the batch** — pick an engine (see below). If you pick *Anthropic API*,
   paste a key from `console.anthropic.com` and press *Test the API key*.
3. **Settings → Email delivery** — set the from address and send yourself a test.
4. **Settings → Scheduling hook** — set up one of the two options below.
5. **Change the passwords.** All three accounts ship with `44i123`.

### What runs the batch

The research can come from three places. The dashboard, the storage, the de-duplication, the
scoring floor, the email and the GoHighLevel push are identical in all three cases — only the brain
changes.

| Engine | What it costs | Where the research happens |
|---|---|---|
| **Anthropic API** | pay per batch, needs an API key | here, with Claude, web search and web fetch |
| **External worker** | nothing | on your own machine, against your own Ollama — see [`worker/README.md`](worker/README.md) |
| **Manual** | nothing | you run the loop yourself and paste the brief in |

An Anthropic Pro or Max subscription does **not** include API access or API credits — those are
separate products, so the API engine needs its own key with its own billing.

With **External worker** selected, the morning cron stands down (exit 0, nothing spent) and the
worker pulls its own assignment instead. The worker authenticates with the token under
**Settings → Batch worker**, and that panel shows when it last checked in — a Mac that went to
sleep shows up there rather than as leads quietly not arriving.

Switching engines is a settings change. Nothing about the stored leads depends on which one
produced them.

### Making it run every morning

Something external has to wake the app. Either of these is enough on its own; the cron job is the
better primary because it does not depend on GitHub Actions being on time.

**cPanel cron job (recommended).** Add one job:

```
30 7 * * 1-5 /usr/local/bin/php /home/USER/public_html/bin/daily.php >> /home/USER/prospector-cron.log 2>&1
```

**Webhook.** Copy the URL from Settings → Scheduling hook, add it as a repository secret named
`PROSPECTOR_CRON_URL`, and `.github/workflows/daily-prospector.yml` will ping it every half hour.

Both paths land in the same guard: the delivery window opens at the configured local time and
stays open three hours, a batch that already ran today is skipped, and a batch that *failed* is
retried by the next firing. That means the schedule never needs adjusting for daylight saving, and
changing the delivery time under Settings needs no change anywhere else.

### Using MySQL instead

SQLite is the default because it needs no setup. To use a cPanel MySQL database instead, create
`config.local.php` on the server (it is git-ignored, so it survives deploys):

```php
<?php

return [
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'database' => 'user_prospector',
        'username' => 'user_prospector',
        'password' => '…',
    ],
];
```

The tables are created on the next request either way.

---

## Branding

The accent is **#c8ff00**, a fluorescent lime, at full chroma on the dark theme
where it can actually glow. On light it steps down to `#a8d900` — `#c8ff00` on
white is barely visible, and the glow reads as a smudge rather than as light, so
`--accent-glow` is transparent there and every glow rule goes inert on its own.

The wordmark beside the pickaxe is set in **Hellforge**, an industrial slab from
the Lost Type Co-op, self-hosted from `assets/fonts/`. The file is **caps only** —
26 uppercase letters and nothing else — so the `@font-face` declares a
`unicode-range` of A–Z and nothing but the wordmark uses that stack; the
strapline under it would lose both 4s of "44i". Alfa Slab One sits behind it as a
fallback, under the SIL Open Font License. Nothing is fetched from a third party
either way. See `assets/fonts/README.md`.

---

## Signing in

| Account | How | Sees |
|---|---|---|
| `scott@44interactive.com` | email + password | everything, every user's leads, all settings |
| `billy@44idigital.com` | email only | only their own leads |
| `darren@44i.com` | email only | only their own leads |

Billy and Darren need no password — their email address alone signs them in. That is convenient,
and it means anyone who knows the address and the site URL can read their leads. Both accounts
already have `44i123` set, so if that trade stops being acceptable, tick **Requires a password**
for them on the Users screen and nothing else has to change.

**A sign-in lasts 30 days**, and every visit pushes that back out to a full month, so opening the
tool once a week means never signing in again. Session files live in `storage/sessions` rather than
the host's shared directory — on shared hosting that directory is swept on whatever lifetime the
*other* site configured, typically 24 minutes, which would silently expire a month-long cookie. The
flip side of a long session is that a signed-in browser stays signed in: on a shared machine, use
**Sign out** rather than closing the tab.

Adding another person is a Users-screen job: name, email, pick a loop, done. A new *loop* needs a
spec in `app/loops/`, an entry in `Users::LOOPS` and `Users::RUNNABLE_LOOPS`, and a rotation in
`Runs` — see the `home` loop for the shape of it.

---

## How a batch works

1. **Rotation.** The day of the week picks the vertical (Billy: Monday radio, Tuesday agencies,
   Wednesday TV, Thursday publishers, Friday OOH; Darren and Sara: mixed with a rotating lean) and
   the day of the year picks the geography, so consecutive batches never hammer the same market. A
   user can be pinned to one region with the geography override on the Users screen — leave it blank
   to let the rotation run, which is what Sara wants, since every entry in her rotation already sits
   inside her radius.
2. **Research.** One Claude call with web search and web fetch, working from the loop spec in
   `app/loops/`. Every company already delivered to that owner is passed in as an exclusion list,
   so a batch never repeats an earlier one. The output is the Markdown brief you see on the batch
   screen.
3. **Extraction.** A second call turns that brief into structured rows against a JSON schema. It is
   told to copy values, never to invent them.
4. **Storage.** Anything below the fit-score floor is dropped rather than padding the batch, and
   any company already on file for that owner is skipped.
5. **Email.** The brief goes to the owner's inbox.

If fewer than ten leads qualify, fewer than ten are delivered and the brief says why. That is
deliberate — the loops are specified never to pad.

Every email address carries a confidence label from the research:

| Label | Means |
|---|---|
| `verified` | Found on the organisation's own site, a filing, or a press release |
| `high` | Two or more independent sources agree |
| `pattern` | Inferred from the company's email format — **must be confirmed before any bulk send** |

`pattern` addresses are deliberately **not** written into the GoHighLevel contact's email field, so
a sequence cannot fire at an unverified address and burn the sending domain. The address is put in
the contact note instead, flagged, for a human to confirm.

Steps 1, 4 and 5 are the same whichever engine is running. With the external worker, steps 2 and 3
happen on your machine and the finished rows arrive over `/api/import`, where the score floor and
the de-duplication are applied again — a buggy or over-eager worker cannot lower the bar. That
worker never emits a `pattern` address at all, because it never infers one.

### Uploading a list by hand

**Leads → Upload leads** takes a CSV or a JSON array. Header names are matched loosely, so
`Company Name`, `company_name` and `Organization` all land in the same field and a list you
already have usually imports as-is; commas, semicolons and tabs all work. A JSON payload prepared
for `/api/import` can be pasted straight in, envelope and all.

Only `company` is required. The rest are optional: `website`, `vertical`, `door`, `market`,
`state`, `decision_maker`, `title`, `email`, `email_confidence`, `phone`, `direct_phone`,
`linkedin`, `fit_score`, `why`, `hook`, `evidence`.

Nothing is stored until the preview is confirmed. Three deliberate choices there:

- **Uploads do not apply the fit-score floor.** That floor stops a research batch padding itself
  to hit a number; someone uploading a file has already made that judgement. Unscored rows land
  at 0.
- **An email with no stated confidence is treated as `pattern`**, i.e. unverified, which keeps it
  out of the GoHighLevel email field until a human confirms it. Assuming otherwise is how an
  unchecked address ends up in a bulk send.
- **De-duplication is per owner**, so re-uploading the same list is safe, and the same company can
  legitimately sit with both Billy and Darren.

### Digging for missing contact details

A lead that arrives without an email or phone gets a **Dig for contact details** button, on the
lead screen and on the list row. It runs a two-pass search — Claude with web search and web fetch,
then a structured extraction — and shows what it found with the URL each value came from. Nothing
is written to the lead until someone ticks it, and every accepted value lands on the timeline with
its source, because "where did this address come from" is the question that matters six weeks later.

**It runs in the background.** A dig takes 30-60 seconds, which outlasts the request timeout on
shared hosting — held open, the connection dies and the browser is left on a POST-only URL, which
answers a GET with a 404. So the response goes out first, the work happens after it, and the lead
page polls until the result lands. Leaving the page is fine; the result is stored on the lead and
will be waiting. A run that dies without finishing is reported as failed after ten minutes rather
than polling forever.

It needs an Anthropic API key (Settings → Anthropic API). **Every dig reports what it cost**, under
the findings, so the bill is never a surprise.

Digs run on their own model — **Settings → Model for "Dig for contact details"**, Sonnet by default
— because a dig is a lookup, not the open-ended research a batch does, and it is paid for per click.
The tool budget is deliberately tight: 3 searches, 5 page fetches, 3,000 tokens of content per page,
3 continuations. That matters more than it looks. Every `pause_turn` re-sends the whole
conversation, and fetched pages live in that conversation, so page content is billed again on each
continuation — an unbounded lookup can cost more than a full batch. On the current budget a dig is
roughly $0.09 on Haiku, $0.21 on Sonnet, $0.33 on Opus.

**It looks for business contact details only**, from the company's own site, regulatory filings,
press releases, trade rosters and public professional profiles. People-search and data-broker sites
(fastpeoplesearch, truepeoplesearch, whitepages, spokeo and the rest) are **blocked at the API
level** via `blocked_domains`, not merely discouraged in the prompt. Three reasons, in `app/Enrich.php`:
they return home addresses and personal mobiles rather than work contacts; a personal mobile in the
phone column is a TCPA problem once a GoHighLevel sequence can text it; and they block automated
access anyway.

The same confidence discipline applies as everywhere else — a `verified` claim that arrives without
a source URL is downgraded to `pattern`, which keeps it out of the GoHighLevel email field.

### Running one by hand

**Batches → Run now**, or from the command line:

```bash
php bin/daily.php --user=billy@44idigital.com --now   # run one, ignoring the clock
php bin/daily.php --now --no-email                    # run everyone, skip the email
php bin/daily.php --dry-run                           # print the exact prompt, call nothing
```

`--dry-run` is the one to reach for when tuning a loop: it shows the full assembled prompt without
spending a token.

---

## Outreach

Every lead can carry a six-email cadence, written from the reason it qualified — the buyer door, the
evidence the researcher read, and the hook it wrote. **Outreach** in the sidebar is where cadences
are built, reviewed and sent; the lead screen carries just the opening email, for when you are
working one company rather than a list.

| Step | Day | What it is for |
|---|---|---|
| 1 | 0 | Opener — the specific thing you noticed, one ask |
| 2 | 3 | Proof — a comparable result in their own vertical |
| 3 | 7 | Second angle — the same problem from a different side |
| 4 | 14 | Nudge — three lines, one question |
| 5 | 21 | Something useful — no ask at all |
| 6 | 30 | Close the loop — permission to stop |

Day offsets count from the day you **approve**, not from when the copy was written, so a cadence
approved a fortnight after it was drafted still spaces itself out properly.

Three rules are load-bearing:

- **Nothing sends that a person has not approved**, and editing an approved email puts it back to
  draft. Approval is of the exact words, and the words just changed. That is what makes approving a
  hundred leads at once a defensible act rather than a leap of faith.
- **`pattern` addresses are held back by the mass send.** They were inferred from a company's email
  format and never confirmed. Sending one on purpose is a judgement call you can make from the lead;
  a hundred at once is how a sending domain gets burned. Tick *Include unverified addresses* in the
  bulk bar to override it deliberately.
- **Archiving a lead stops its cadence.** Archiving means you have stopped working it, and mail that
  kept going out afterwards would make that decision meaningless.

Copy is written by one API call per lead that produces all six emails at once, with no web search —
everything it needs is already on the lead row, and going back to the web would only invite
unchecked facts. A whole cadence costs a fraction of a cent. Pick the model under **Settings →
Model for outreach email copy** on how the writing reads, not on price.

Sending goes through GoHighLevel, so a lead with no contact there is pushed on the way. Steps 2 to 6
go out on their day when the scheduler runs — which happens whatever the batch is doing, since a
missing API key or an external worker engine has nothing to do with mail somebody already approved.
Weekends are honoured when weekday-only delivery is on: cold email on a Sunday is worse than cold
email a day late. **Send due now** on the Outreach screen does the same thing on demand.

---

## The local model

An account-wide connection to any OpenAI-compatible server — Ollama, LM Studio, llama.cpp, vLLM.
**Settings → Local model**, which only an admin can reach: address, model name, and an optional API
key stored encrypted and never shown again. One machine for the whole account rather than a copy per
person, because it is one box on one network and four copies of the address is four ways for it to
be wrong.

Paste the address however you have it — `mac-mini.local:11434`, `http://mac-mini:11434/v1/`, or a
full `/chat/completions` endpoint all normalise to the same thing on save. Most local servers need
no key at all; leaving it blank is a valid setup, not a missing one.

What it is used for today is **outreach copy**: pick it under *Model for outreach email copy* and a
six-email cadence costs nothing instead of a fraction of a cent. Copywriting is the right job for it
— no web search, no research to get wrong, and you can judge the result by reading it. Batches still
go to the hosted API, which is where web search lives.

Local models are messier than a hosted API, so the client copes: it pulls JSON out of a code fence,
out of prose, and out from behind a `<think>` block, and reports what actually went wrong when it
cannot. Selecting the local model and then clearing its settings falls back to Sonnet rather than
failing every build.

---

## Automations

Beyond pushing contacts, Prospector drives GoHighLevel's automations directly.

**By hand** — every lead with a contact has an Automations panel: add to a workflow, and remove from
one. Removing matters as much as adding, because enrolling a hundred people in the wrong workflow is
a mistake somebody will make.

**In bulk** — tick leads on the Leads screen and add them all at once. Workflows belong to a
sub-account, so the list comes from whichever account the screen is scoped to; an admin looking at
everybody's leads is told to filter to one owner rather than being offered a list that would fail
one row at a time.

**Automatically** — rules on the Automations screen, per owner:

| When | What it means |
|---|---|
| Every new lead | Anyone who lands in this account, however they got here |
| Fit score at least *n* | Only the strong ones |
| Marked as *status* | A disposition is set — booked a meeting, for instance |
| First outreach email sent | The moment they have actually been contacted |
| Cadence finished | All six emails have gone and nobody replied |

The status and email rules fire the instant the thing happens. The new-lead and score rules run as a
**sweep** — from the scheduler, or on demand with *Run the rules now* — which is what makes a rule
added today pick up last week's leads without a backfill. The sweep is safe to re-run: a unique
index on (lead, workflow) is what stops it re-adding everybody every half hour.

Every enrolment is written to the lead's history, so nothing joins an automation quietly. An
archived lead is never swept in, and a lead taken out of a workflow by hand is not put back by the
rule that first added it.

---

## Conversations

The lead screen shows the real GoHighLevel thread — email and SMS stitched together in one list,
since GoHighLevel keeps them as separate conversations and the question being asked is "what have we
said to this person". There is a reply box for both channels; a reply is a person answering
something, so it is deliberately kept out of the approved cadence rather than disturbing it.

The Inbox filters by channel and by unread, and links each conversation back to the lead it belongs
to instead of being a dead end.

---

## GoHighLevel

Everyone connects their own sub-account under **GoHighLevel → Connection**: a Location ID and a
Private Integration token, stored encrypted against their user. An account-wide pair in Settings is
the fallback for anyone who has not. Admins can view and set up anyone's with the switcher on the
workspace header; everyone else only ever sees their own, whatever they put in the URL.

Make the token in GoHighLevel under **Settings → Private Integrations**, on the sub-account rather
than the agency view, with these scopes:

| Scope | Needed for |
|---|---|
| `locations.readonly` | The connection test |
| `contacts.readonly`, `contacts.write` | Contacts, notes, tasks, pushing leads |
| `opportunities.readonly`, `opportunities.write` | The pipeline board and moving cards |
| `conversations.readonly`, `conversations.write` | The inbox |
| `conversations/message.readonly` | Reading a thread |
| `conversations/message.write` | Sending email and SMS |
| `workflows.readonly` | Listing automations |

Miss one and only that panel stops working — each fetches separately and says which scope it wanted.

### The workspace

| Screen | What it does |
|---|---|
| **Pipeline board** | Opportunities as columns by stage. Drag a card to move the deal — it saves to GoHighLevel and snaps back if the API refuses. |
| **Contacts** | Search the sub-account, open anyone. |
| **Contact** | Notes, tasks, the conversation thread, and a compose box that sends email or SMS. Also drops the contact into a workflow. |
| **Inbox** | Every conversation, newest activity first. |
| **Automations** | Workflows, and a read-only view of Conversation AI agents. |

Nothing is mirrored into Prospector's database — the workspace reads and writes the live
sub-account, because a stale copy of a CRM is worse than no copy.

**Sending is real.** Email and SMS from the contact screen go to the actual prospect immediately.
The compose box names the address or number before you commit, and the send is rejected server-side
unless the confirmation step was completed.

Conversation AI is read-only on purpose: GoHighLevel's API can create and configure agents, but
nothing documented turns a bot on or off for one contact or conversation, which is the control a rep
would actually want mid-thread.

Pushing a lead from the Leads screen upserts it as a contact — company, name, email, phone, website,
city, tags for vertical, buyer door and fit score — and attaches the evidence and opening hook as a
note. Fill in a Pipeline ID and Stage ID under Settings and each push also opens an opportunity.

### Testing it without a GoHighLevel account

`tests/mock_ghl.py` stands in for the API, holding state in memory so round trips are real — move a
card and the next page load shows it moved. It also injects failures, which is how the
missing-scope behaviour is checked:

```bash
python3 tests/mock_ghl.py --port 8788 &
PROSPECTOR_GHL_BASE=http://127.0.0.1:8788 php -S 127.0.0.1:8402 -t . bin/serve.php
curl "http://127.0.0.1:8788/__control?fail=workflows"   # that endpoint now 401s
```

`PROSPECTOR_GHL_BASE` is an environment variable and deliberately not a setting: nothing anyone
clicks should be able to point live credentials at another host.

---

## Layout

```
index.php              front controller and routes
cron.php               webhook entry point for the daily batch
config.php             defaults; override per-server with config.local.php
app/
  Prospector.php       runs a batch: prompt → research → extract → store → email
  Claude.php           Anthropic SDK wrapper (pause_turn handling, retries)
  Api.php              /api/assignment and /api/import, for the external worker
  GoHighLevel.php      GoHighLevel API v2 client
  Leads.php            lead storage, filtering, dispositions, de-duplication
  Outreach.php         the cadence spec and the copywriting call
  LocalModel.php       the OpenAI-compatible client for a local model server
  Automations.php      enrolment rules, the sweep, and who is in what
  Emails.php           email rows, the approve/send state machine, scheduled sends
  Runs.php             batch records and the vertical/geography rotation
  Users.php  Auth.php  accounts and sign-in
  Mailer.php           the daily brief email
  loops/               the loop specifications, one Markdown file per loop
  Support/             database, schema, settings, crypto, clock, views, markdown
views/                 screens, partials, and the email template
assets/                stylesheet, script, pickaxe mark
bin/daily.php          CLI runner for cron
bin/serve.php          local dev server router
worker/                the external worker — runs on your Mac, talks to local Ollama
storage/               database, encryption key, logs (never served, never committed)
```

Secrets are entered in the UI and stored encrypted with a key generated into
`storage/app_key.php` on first boot. Nothing sensitive is in version control. If that file is ever
lost, the saved API key, SMTP password and GoHighLevel token have to be re-entered — the leads
themselves are unaffected.

### Running it locally

```bash
composer install
php -S 127.0.0.1:8000 -t . bin/serve.php
```
