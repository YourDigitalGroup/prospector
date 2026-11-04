#!/usr/bin/env python3
"""
A stand-in for Mailgun's messages API — just enough to prove Prospector sends
a correct request, and reports Mailgun's refusals in words.

    python3 tests/mock_mailgun.py 8793

Then point the app at it, which only an environment variable can do:

    PROSPECTOR_MAILGUN_BASE=http://127.0.0.1:8793

What it checks, the way Mailgun does:

  * HTTP basic auth as user "api" with the key as the password. Any key with
    "bad" in it is refused 401 with Mailgun's plain "Forbidden" body.
  * The sending domain in the path is one the account has — fourge.com or
    mg.fourge.com. Anything else is a 404 "Domain not found".
  * from, to and subject are present.

    GET /__messages     what has been accepted, oldest first
    GET /__reset        forget them
"""

import base64
import json
import re
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs

DOMAINS = {"fourge.com", "mg.fourge.com"}
MESSAGES = []


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, status, payload, plain=False):
        raw = payload.encode() if plain else json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "text/plain" if plain else "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        if self.path.startswith("/__messages"):
            return self._send(200, MESSAGES)
        if self.path.startswith("/__reset"):
            MESSAGES.clear()
            return self._send(200, {"ok": True})
        return self._send(404, {"message": "not found"})

    def do_POST(self):
        match = re.fullmatch(r"/v3/([^/]+)/messages", self.path)
        if not match:
            return self._send(404, {"message": "not found"})

        auth = self.headers.get("Authorization", "")
        try:
            user, key = base64.b64decode(auth.removeprefix("Basic ")).decode().split(":", 1)
        except Exception:
            user, key = "", ""
        if user != "api" or not key or "bad" in key:
            return self._send(401, "Forbidden", plain=True)

        domain = match.group(1)
        if domain not in DOMAINS:
            return self._send(404, {"message": f"Domain not found: {domain}"})

        length = int(self.headers.get("Content-Length") or 0)
        form = {k: v[0] for k, v in parse_qs(self.rfile.read(length).decode()).items()}

        missing = [f for f in ("from", "to", "subject") if not form.get(f)]
        if missing:
            return self._send(400, {"message": "'" + missing[0] + "' parameter is missing"})

        MESSAGES.append({"domain": domain, "key": key, **form})
        return self._send(200, {"id": f"<{len(MESSAGES)}@{domain}>", "message": "Queued. Thank you."})


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8793
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
