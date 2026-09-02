# Fonts

## Hellforge — the wordmark

`Hellforge.otf` is the face the PROSPECTOR wordmark is set in: an industrial
slab from the Lost Type Co-op, <https://www.losttype.com/font/?name=hellforge>.
Committed by the repository owner, who holds the licence.

Two things about the file are worth knowing before touching anything that uses
it.

**It is caps only.** 30 glyphs: the 26 uppercase letters and nothing else — no
lowercase, no digits, no punctuation. That is exactly what a wordmark needs and
nothing more. The `@font-face` block declares `unicode-range: U+0041-005A` to
say so out loud, which means the browser only ever reaches for this face for
A–Z; anything else comes from the next font in the stack rather than turning
into blank boxes.

So **do not use this stack for anything but the wordmark.** The strapline under
it reads "44i lead generation" and would silently lose both of its 4s.

**It is OpenType, not WOFF2.** That is a perfectly good webfont format. A WOFF2
would be roughly a third smaller, but the file is 6KB, so the saving is not
worth a format conversion that the licence may not cover. If Lost Type ever
supply a WOFF2, add it ahead of the OTF in the `src` list — browsers take the
first format they understand.

### One caveat on it living here

This repository is **public**, so the font is readable by anyone with the URL.
That is the owner's call to make and it has been made; it is noted here only so
nobody is surprised by it later. Making the repository private would remove the
question entirely.

## Alfa Slab One — the fallback

`alfa-slab-one.woff2` sits behind Hellforge in the stack, for anywhere the OTF
cannot be served. It is a heavy display slab under the SIL Open Font License,
which explicitly permits redistribution, so it is safe to ship here. Licence
text in `alfa-slab-one-OFL.txt`.

Self-hosted rather than pulled from Google Fonts, so nothing is fetched from a
third party at runtime — the same property the rest of the app has.
