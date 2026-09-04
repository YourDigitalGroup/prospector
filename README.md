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

The mark is the artwork at `assets/img/logo.png`. To change it, overwrite that
file — or drop in an `assets/img/logo.svg`, which wins over the PNG and scales
cleanly at every size. Either way there is no code to change. Then run
`php bin/favicons.php` and commit the two PNGs it writes, so the tab and
home-screen icons follow the logo instead of drifting behind it. With neither
file present the mark falls back to a drawing in `views/partials/logo.php` that
was made by eye from a picture of the artwork — a rendition, not a copy, and
only there so a missing asset degrades to something rather than to nothing.

Because the PNG is a fixed colour it cannot track `--accent` the way the rest of
the interface does, so `--mark-filter` gives it the bloom on dark and the same
step down in depth on light.

The wordmark beside the pickaxe is set in **Hellforge**, an industrial slab from
the Lost Type Co-op, self-hosted from `assets/fonts/`. The file is **caps only** —
26 uppercase letters and nothing else — so the `@font-face` declares a
`unicode-range` of A–Z and nothing but the wordmark uses that stack; the
strapline under it would lose both 4s of "44i". Alfa Slab One sits behind it as a
fallback, under the SIL Open Font License. Nothing is fetched from a third party
either way. See `assets/fonts/README.md`.

---

## On a phone

The sellers work leads between calls, so the phone is a first-class screen
rather than a fallback. Four things make it one.

**No field is under 16px.** iOS Safari zooms the whole page in when you focus
anything smaller and does not zoom back out, so tapping any field used to leave
you pinching to read the rest of the screen. Everything steps up to 16px below
900px; the desktop keeps its 13.5px.

**The lead lists are one line each.** A row on **Leads** or **Outreach** is a checkbox, a fit
score, and the company with the contact's name beside it — both clipping rather than wrapping,
because a wrapped row is not a one-line row. Everything else is one tap away on the lead. The phone
row is written into the markup as its own cell rather than assembled by hiding six of the desktop
ones and digging a name back out of a seventh, which is a set of selectors that breaks the moment a
column is renamed.

**Every other table becomes cards below 720px.** A nine-column table at 390px either scrolls
sideways — burying Status off-screen where nobody finds it — or wraps company
names one word per line. Each row becomes a card with a caption per line, and
`assets/js/app.js` copies those captions off the `<th>` at load, so every table
in the app gets it without touching sixteen templates and a column only admins
see cannot fall out of step with its label. Columns marked `secondary` in the
markup come off entirely: Market, Owner and Delivered are all one tap away on
the lead. Cells whose whole content is the em dash a table prints to hold a
column open are dropped too, rather than becoming a caption with a shrug under
it. Without JavaScript the tables stay tables and still scroll, which is the
behaviour this replaces rather than a new failure.

**Targets are 44px.** A fingertip covers about 9mm. The two chrome buttons were
32px square, the tap-to-call and tap-to-email links on a lead were 15px lines of
type, and "Dig for details" was 17px. The rule keys off width first and
`pointer: coarse` second — pointer alone is unreliable enough across emulators
and desktop touchscreens that it silently does not apply, which is exactly what
happened the first time.

**The filter panel folds away**, with a count on the button so a filter left on
is still obvious while the panel is shut, and the bulk bar sticks to the bottom
of the screen once something is selected — it sits above the list, so otherwise
you would tick six leads, scroll back up past all of them, and only then choose
an action.

### The pipeline board

Cards are dragged between stages with a mouse. HTML5 drag events do not fire on
touch at all, so on a phone the board was read-only while the hint underneath
told you to drag — so every card also carries a stage picker, which posts a
normal form and works from a touchscreen, from a keyboard, and with no
JavaScript. The hint says whichever half applies.

---

## Signing in

Three accounts are created on first boot, and only on first boot — the seed is skipped the moment
the users table has anything in it, so it can never overwrite a live install:

| Account | How | Sees |
|---|---|---|
| `scott@44interactive.com` | email + password | everything, every user's leads, all settings |
| `billy@44idigital.com` | email only | only their own leads |
| `darren@44i.com` | email only | only their own leads |

**Sara is not one of them.** The Home Prospector loop ships fully wired — spec, rotation,
geography, the lot — but with nobody assigned to it, because her address is not something the code
should be guessing at. She is added on the Users screen like any other person, which is where she
was added. A fresh install has the same gap and closes it the same way.

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

### Adding one lead by hand

**Leads → New lead** is for somebody you have actually spoken to — met at a conference, passed on
by a client, phoned in. Every field the uploader accepts is on the form, plus a status, so a lead
you have already had a conversation with can go straight in as *Contacted* or *Meeting booked*
rather than as *New*. Setting it there fires the same automations a disposition would.

Two things differ from an upload, both because a person is sitting there typing:

- **Nothing is silently dropped.** A file with a bad email address keeps the row and loses the
  address; the form points at the field and hands your typing back. A collision reports which
  record it clashed with and its number, rather than counting itself as skipped.
- **An email defaults to verified**, the opposite of the upload default below. Everywhere else an
  address with no stated provenance is a guess, because a model or a scraper supplied it. One typed
  in here came out of a conversation. It is still a dropdown, so an address you worked out rather
  than were given can say so and stay out of bulk sends.

Everything entered on a given day hangs off one "Added by hand" batch for that owner, so a stack
of business cards shows up as a single entry on **Daily batches** and still counts towards the
day's total on the dashboard. **Save and add another** comes straight back to an empty form.

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

### Emailing somebody directly

**Send email**, on every lead screen. It opens a dialog with the address it is going *from*, the
address it is going *to*, a subject and a body; press send and it goes. There is no draft or
approval step, because this is the reply to something they said or the follow-up after a call, not
a sequence. The cadence in **Outreach** is still the other half of the job and is untouched by this.

The **from** address is read-only and comes from the GoHighLevel sub-account, captured when the
connection is tested. It is not ours to change: it is the sending domain their replies come back to.

The **to** address is editable, and editing it *corrects the lead* rather than overriding one send.
That is deliberate. GoHighLevel addresses a message by contact id, so an override its API declined
to honour would send to the old address while the screen said otherwise — wrong recipient, silently,
is the one failure this must not have. So a corrected address is written to the lead, pushed to the
GoHighLevel contact, and passed as `emailTo` as well. All three say the same thing.

The dialog is real `<dialog>` markup that is already on the page. With JavaScript off the button is
a link to `#compose` and the form still posts; it just sits inline instead of over the page.

Three more things are worth knowing about it.

**It needs the owner's GoHighLevel private integration**, and says so plainly when there is not one
rather than hiding. Everything leaves through that seller's own sub-account. Prospector has SMTP
credentials of its own, but they are for the daily brief — an internal mail to a colleague — and
putting cold outreach on the same domain reputation as the tool's own notifications would be a bad
trade. Going through GoHighLevel means the sending domain, the unsubscribe handling and the reply
routing are the ones already set up for that seller, and the message lands in the contact's
timeline where their reply will land too.

**The lead does not have to be in GoHighLevel first.** If there is no contact yet, sending creates
one — the same thing a cadence step does. Being sent off to press another button before you can
answer somebody is not a safeguard.

**A guessed address sends without asking.** An address marked `pattern` was inferred from the
shape of other addresses at the domain and never confirmed. Bulk sends still refuse those, and that
is the guard that matters — nobody reads a hundred addresses before pressing send on all of them.
One-off compose is the opposite situation: somebody opened this lead, opened this dialog, and typed
a message to this person. There was a confirmation tick here at first, asking them to agree to a
decision they had already made three times, which is how people learn to click past warnings and
costs the warning its meaning everywhere else it appears. The lead itself still badges the address
as inferred, and sending to it does not make it verified.

A lead with no address and no phone gets an explanation rather than a box.

### Emailing several at once

Tick leads on the list and press **Send an email**. One message is written and
one message is sent *per person* — each recipient sees only their own address,
and merge variables resolve against their own record. GoHighLevel's conversation
model is per contact anyway, so there is no bulk endpoint to use even if that
were wanted.

Leads that cannot be reached are named rather than counted silently: the flash
says "3 emails are going out now. 1 skipped — no email address on file." A bulk
send that reports success while eight bounced off a missing address is worse
than one that says what happened.

The send is backgrounded. Forty sends are forty round trips, and the request
would time out somewhere in the twenties leaving nobody able to say which of
them went.

### Merge variables

`{{contact.first_name}}`, `{{contact.company_name}}`, `{{user.phone}}` and the
rest — the names are GoHighLevel's, so copy moves between the two without
editing. A picker in the composer inserts them at the cursor.

**They are resolved here, not there.** Passing an unresolved
`{{contact.first_name}}` to `/conversations/messages` and hoping GoHighLevel
fills it in is a bet on undocumented behaviour, and the way that bet loses is a
real prospect receiving "Hi {{contact.first_name}}".

Two deliberate behaviours. A variable with nothing behind it **falls back**
rather than blanking — no first name on file gives "there", so a greeting never
arrives as "Hi ,". A variable **nobody defined stays visible**, so a typo shows
up in the preview instead of silently deleting itself. Leads carry one
`decision_maker` field because that is how they arrive, so the first name is
split out of it — and trailing credentials are dropped, which is why "Cale
Slack, DDS" greets as "Cale".

### Formatting and attachments

Bold, italic, underline, size, bullets and links, in a contenteditable box.
What comes back is **rebuilt against an allow-list** in `Support\RichText`
rather than searched for bad things: stripping `<script>` and `onclick=` is a
game you lose eventually, because the list of dangerous constructs is
open-ended, and keeping only named tags and attributes is closed-ended. The
plain-text part is derived from the cleaned HTML so the two cannot drift apart.

Attachments are uploaded as they are picked, not with the message, so a 10MB
file does not ride along with every failed send. GoHighLevel takes them as URLs,
so they are hosted here and linked. The extension allow-list is the whole
security model — a PDF cannot be re-encoded the way a signature image can — and
two exclusions are worth naming: **SVG is refused**, because it is an image
everywhere except in a browser, where it is a document that can carry script
running on our own origin; and **HTML is refused** for the same reason. Files
land under a random directory rather than a random name, so the recipient saves
"proposal.pdf" rather than "9f2c…e1.pdf".

### Signatures

Each person sets their own under **GoHighLevel → Connection**: name, title, company, phone, email,
website, one free line, and a logo or headshot. Per user, because three sellers sharing one
signature would be worse than none. A text never gets one — it would eat the message — and cadence
copy writes its own sign-off, so this does not touch it.

Structured fields rather than a free-text box. The logo has to render as HTML for it to appear at
all, and letting people paste their own HTML into mail going out over their own sending domain is a
way to end up debugging somebody's stray `</table>`. Fields in, a consistent block out — a
table-based, inline-styled one, because mail clients strip `<style>`, ignore flexbox, and Outlook
renders through Word.

Three things about the image:

- **It is re-encoded, never stored as uploaded.** The file is decoded with GD and written back out
  as a fresh PNG, so an EXIF payload, a polyglot that is also valid script, or a malformed header
  aimed at an image parser does not survive the round trip. The bytes served are bytes this
  application wrote. `assets/uploads/signatures/` also carries an `.htaccess` turning execution off.
- **It is small on purpose** — 260×90 at most, a square headshot landing at 90×90. A signature logo
  sits next to four lines of type in a reading pane that is often 500px wide, and anything bigger
  pushes the name and phone number off the side.
- **It is served by absolute URL**, because the recipient's mail client fetches it from outside. A
  `data:` URI would be easier and is refused by Gmail, Outlook and Apple Mail alike. That is also
  why `Settings::publicUrl()` exists: a cadence email sent by cron has no HTTP request to derive a
  host from, so the last host a real browser used is remembered instead of embedding
  `http://localhost`.

Uploads live in `assets/uploads/`, which is git-ignored. The FTP deploy adds files rather than
mirroring, so what is on the server survives a release.

### The thread

The lead screen shows the real GoHighLevel thread — email and SMS stitched together in one list,
since GoHighLevel keeps them as separate conversations and the question being asked is "what have we
said to this person". Anything sent from the lead screen or from a cadence appears in it.

The Inbox filters by channel and by unread, and links each conversation back to the lead it belongs
to instead of being a dead end.

### The bulk bar

Marking a lead is a choice between seven things, so it stays a dropdown. Everything else — send an
email, push to GoHighLevel, add to an automation, archive, unarchive, delete — is one thing you
either want or do not, and each has its own button. Burying "push to GoHighLevel" as the eighth
option of a select made a one-click job take three.

Delete, push and send carry their own colours (`#ff003c`, `#e400ff`, `#00ff9c`), given explicitly
rather than derived from `--accent` so they are the same in both themes: a destructive button that
changes colour with the theme is one somebody eventually fails to recognise.

Deleting asks first, in a dialog of the app's own rather than `window.confirm` — which cannot be
styled, reads as a browser malfunction on a phone, and is easy to dismiss by reflex. It names how
many are about to go and points at Archive as the reversible alternative. Nothing else confirms:
marking six leads "contacted" being a two-step job is how people learn to click through warnings.

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
  LeadImport.php       parse a pasted or uploaded CSV/JSON list
  LeadForm.php         the fields and rules for a lead typed in by hand
  Outreach.php         the cadence spec and the copywriting call
  Direct.php           one-off and bulk email/SMS to leads, sent on the spot
  Merge.php            {{contact.first_name}} and friends, resolved per lead
  Attachment.php       uploaded files, and the URLs GoHighLevel fetches them by
  Signature.php        the per-user sign-off, its logo upload and its HTML
  LocalModel.php       the OpenAI-compatible client for a local model server
  Automations.php      enrolment rules, the sweep, and who is in what
  Emails.php           email rows, the approve/send state machine, scheduled sends
  Runs.php             batch records and the vertical/geography rotation
  Users.php  Auth.php  accounts and sign-in
  Mailer.php           the daily brief email
  loops/               the loop specifications, one Markdown file per loop
  Support/             database, schema, settings, crypto, clock, views, markdown,
                       and RichText, the allow-list HTML sanitiser
views/                 screens, partials, and the email template
assets/                stylesheet, script, the logo, the Hellforge wordmark face
bin/daily.php          CLI runner for cron
bin/serve.php          local dev server router
bin/favicons.php       rebuild the tab icons from assets/img/logo.png
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
