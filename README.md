# Prospector

Lead generation and lead management for 44i. Every weekday morning at 7:30 Central it runs
Billy's and Darren's prospecting loops, stores the qualified leads, and emails each of them a
brief they can work from their phone. Leads are managed in a dashboard, and can be pushed into
GoHighLevel.

Two loops ship with it, both built from the prospecting specs already in use:

| Loop | Owner | Finds |
|---|---|---|
| **Partner Prospector** | Billy | Whitelabel reseller partners — independent TV/radio broadcasters, traditional ad agencies, local publishers, OOH operators |
| **Client Prospector** | Darren | Direct agency-of-record clients — healthcare, casinos, higher ed, ag, regional retail in the Upper Midwest |

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

Adding a fourth person later is a Users-screen job: name, email, pick a loop, done.

---

## How a batch works

1. **Rotation.** The day of the week picks the vertical (Billy: Monday radio, Tuesday agencies,
   Wednesday TV, Thursday publishers, Friday OOH; Darren: mixed with a rotating lean) and the day
   of the year picks the geography, so consecutive batches never hammer the same market. A user can
   be pinned to one region with the geography override on the Users screen.
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
  Runs.php             batch records and the vertical/geography rotation
  Users.php  Auth.php  accounts and sign-in
  Mailer.php           the daily brief email
  loops/               the two loop specifications, as Markdown
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
