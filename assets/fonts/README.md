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

## Why it cannot simply be downloaded here

Two reasons, and the second is the hard one:

1. There is no direct file to fetch — the download sits behind a
   name-your-price checkout.
2. **This repository is public.** A licence for 1–5 users does not cover
   republishing the font to everyone with the URL, which is what committing it
   here would do.

Buying it and dropping it in is entirely fine — that is a licensed copy sitting
in a working tree, not a redistribution. It is only this repository being public
that makes committing it a problem. If the repo were made private, committing it
would be a question for the licence terms rather than an obvious no.

## Until then

`alfa-slab-one.woff2` stands in. It is a heavy display slab under the SIL Open
Font License — which explicitly permits redistribution — so it can live here,
and it is self-hosted rather than pulled from Google, so nothing is fetched from
a third party at runtime. Its licence is in `alfa-slab-one-OFL.txt`.

It is not Hellforge, but it is the same species of face: a heavy industrial slab
that holds the wordmark's shape. Drop Hellforge in and it takes over
immediately, because it sits ahead of Alfa Slab One in the stack.
