# Hellforge

The wordmark next to the pickaxe is set in **Hellforge** — a bold industrial
slab serif from the Lost Type Co-op: <https://www.losttype.com/font/?name=hellforge>

**The font is not in this repository, and cannot be.** Hellforge is
pay-what-you-want for personal use and licensed from $20 for commercial use
(1–5 users), and Lost Type does not advertise a webfont licence. Committing the
files here would be redistributing a licensed font, so the CSS is wired up to
find them and the files are left to whoever holds the licence.

## To turn it on

1. Buy the commercial licence at the link above and confirm it covers web
   embedding. If the download is desktop-only (`.otf` / `.ttf`), ask Lost Type
   for the webfont kit rather than converting it yourself — conversion is
   usually outside a desktop licence.
2. Drop the files in this directory, named exactly:

   ```
   assets/fonts/hellforge.woff2
   assets/fonts/hellforge.woff     (optional, only for very old browsers)
   ```

3. That is all. The `@font-face` block in `assets/css/app.css` already points
   here, and the wordmark picks it up on the next page load.

## Until then

The wordmark falls back to a heavy slab stack — Rockwell, which ships with
Microsoft Office on Windows and macOS, then Roboto Slab, then Georgia. It reads
as an industrial slab wordmark rather than as the default UI font, so nothing
looks broken while the licence is being sorted; it is simply not Hellforge.

Nothing is fetched from a third party at runtime. If the files are absent the
browser skips straight to the fallback — no request, no delay, no console error.
