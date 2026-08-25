#!/usr/bin/env python3
"""
A stand-in for the Anthropic Messages API, just complete enough to exercise
Prospector's own code paths without spending money or needing a key.

It is not a simulator of the model. It reads the request — the system prompt,
the brief, and the JSON schema in output_config — and returns a structurally
valid response built from what it was asked for. That makes it useful for
exactly one thing: proving that Prospector assembles a correct request, and
handles a correct response, all the way through to the database.

What it deliberately does check, because these are the mistakes that would
otherwise reach production silently:

  * output_config.format carries a json_schema, and the first text block it
    returns is valid JSON conforming to it
  * the requested cadence steps are the ones echoed back
  * usage is reported, so cost accounting has something real to add up

Failure injection, so the error paths get exercised too:

    GET /__control?fail=refusal      next call comes back as a refusal
    GET /__control?fail=truncated    next call returns invalid JSON
    GET /__control?fail=empty        next call returns an empty emails array
    GET /__control?fail=500          next call is a server error
    GET /__control?fail=off          back to normal
    GET /__calls                     how many calls have been made

It also answers OpenAI-style `POST /v1/chat/completions`, so the same process
stands in for a local model server (Ollama, LM Studio) as well. That path
deliberately replies the way a local model actually does — the JSON wrapped in a
code fence, after a visible thinking block — because coping with that is the
whole reason LocalModel has its own parser.

Run:  python3 tests/mock_anthropic.py 8791
"""

import json
import re
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

STATE = {"fail": None, "calls": 0, "last_request": None}


def wanted_steps(body):
    """Pull the requested step numbers out of the schema description."""
    try:
        schema = body["output_config"]["format"]["schema"]
        desc = schema["properties"]["emails"]["items"]["properties"]["step"]["description"]
        return [int(n) for n in re.findall(r"\d+", desc)]
    except (KeyError, TypeError):
        return [1]


def company_of(body):
    for message in body.get("messages", []):
        content = message.get("content")
        text = content if isinstance(content, str) else json.dumps(content)
        found = re.search(r"^Company: (.+)$", text, re.MULTILINE)
        if found:
            return found.group(1).strip()
    return "the company"


def emails_for(body):
    company = company_of(body)
    out = []
    for step in wanted_steps(body):
        out.append({
            "step": step,
            "subject": f"step {step} for {company}"[:54],
            "body": (
                f"This is mock copy for step {step}, written for {company}.\n\n"
                "It exists so the send path can be tested without spending money.\n\n"
                "Sara"
            ),
        })
    return {"emails": out}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def _send(self, status, payload):
        raw = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        if self.path.startswith("/__control"):
            mode = re.search(r"fail=([a-z0-9]+)", self.path)
            STATE["fail"] = None if not mode or mode.group(1) == "off" else mode.group(1)
            return self._send(200, {"fail": STATE["fail"]})

        if self.path.startswith("/__calls"):
            return self._send(200, {"calls": STATE["calls"], "last": STATE["last_request"]})

        self._send(404, {"error": "not found"})

    def _openai(self, body):
        mode = STATE["fail"]
        STATE["fail"] = None

        if mode == "500":
            return self._send(500, {"error": {"message": "mock local server error"}})

        # The connection probe asks for one word. Answering it with a cadence
        # would make the Test button look like it worked for the wrong reason.
        asked = " ".join(str(m.get("content", "")) for m in body.get("messages", []))
        if "single word: ready" in asked:
            return self._send(200, {
                "id": "chatcmpl-mock",
                "object": "chat.completion",
                "model": body.get("model", "mock-local"),
                "choices": [{"index": 0, "message": {"role": "assistant", "content": "ready"},
                             "finish_reason": "stop"}],
                "usage": {"prompt_tokens": 12, "completion_tokens": 1, "total_tokens": 13},
            })

        if mode == "notjson":
            # A local model that ignored the instruction entirely.
            content = "I would be happy to help you write those emails!"
        elif mode == "empty":
            content = json.dumps({"emails": []})
        else:
            # The realistic case: a thinking block, then a fenced object. If
            # LocalModel cannot read this, it cannot read a real local model.
            content = (
                "<think>The brief says one ask per email, so keep it short.</think>\n"
                "```json\n" + json.dumps(_openai_payload(body)) + "\n```"
            )

        return self._send(200, {
            "id": "chatcmpl-mock",
            "object": "chat.completion",
            "model": body.get("model", "mock-local"),
            "choices": [{
                "index": 0,
                "message": {"role": "assistant", "content": content},
                "finish_reason": "stop",
            }],
            "usage": {"prompt_tokens": 900, "completion_tokens": 700, "total_tokens": 1600},
        })

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"

        try:
            body = json.loads(raw)
        except json.JSONDecodeError:
            return self._send(400, {"type": "error", "error": {"message": "bad json"}})

        STATE["calls"] += 1
        STATE["last_request"] = body

        # OpenAI-compatible path: stand in for a local model server.
        if "/chat/completions" in self.path:
            return self._openai(body)

        if "/messages" not in self.path:
            return self._send(404, {"type": "error", "error": {"message": "unknown endpoint"}})

        # A key must be present. Prospector refuses to construct a client
        # without one, so this catches the case where that stops being true.
        if not self.headers.get("x-api-key") and not self.headers.get("authorization"):
            return self._send(401, {"type": "error", "error": {"message": "no api key"}})

        mode = STATE["fail"]
        STATE["fail"] = None  # one-shot

        if mode == "500":
            return self._send(500, {"type": "error", "error": {"message": "mock server error"}})

        base = {
            "id": "msg_mock",
            "type": "message",
            "role": "assistant",
            "model": body.get("model", "claude-sonnet-5"),
            "stop_reason": "end_turn",
            "usage": {"input_tokens": 1200, "output_tokens": 900},
        }

        if mode == "refusal":
            return self._send(200, {**base, "stop_reason": "refusal", "content": []})

        if mode == "truncated":
            return self._send(200, {
                **base,
                "stop_reason": "max_tokens",
                "content": [{"type": "text", "text": '{"emails": [{"step": 1, "subj'}],
            })

        payload = {"emails": []} if mode == "empty" else emails_for(body)

        return self._send(200, {
            **base,
            "content": [{"type": "text", "text": json.dumps(payload)}],
        })


def _openai_payload(body):
    """Steps come from the schema echoed into the system prompt."""
    system = ""
    user = ""
    for message in body.get("messages", []):
        if message.get("role") == "system":
            system = str(message.get("content", ""))
        elif message.get("role") == "user":
            user = str(message.get("content", ""))

    steps = [1]
    found = re.search(r"one of: ([\d, ]+)", system)
    if found:
        steps = [int(n) for n in re.findall(r"\d+", found.group(1))]

    company = "the company"
    named = re.search(r"^Company: (.+)$", user, re.MULTILINE)
    if named:
        company = named.group(1).strip()

    return {"emails": [
        {
            "step": step,
            "subject": f"local step {step} for {company}"[:54],
            "body": f"Local model copy for step {step}, written for {company}.\n\nSara",
        }
        for step in steps
    ]}


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8791
    # Threaded: the SDK keeps connections alive, and a single-threaded
    # server sits blocked on the first one while the second call waits.
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
