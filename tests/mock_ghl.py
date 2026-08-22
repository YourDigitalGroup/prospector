#!/usr/bin/env python3
"""
A stand-in for the GoHighLevel API v2.

There is no GoHighLevel token in this environment, so every workspace screen
would otherwise be untested. This speaks the response shapes the client reads
and holds state in memory, which is enough to prove the round trips: moving a
card really does change the stage the next page load renders.

    python3 tests/mock_ghl.py --port 8788

Then point the app at it:

    PROSPECTOR_GHL_BASE=http://127.0.0.1:8788 php -S 127.0.0.1:8402 ...

Failure injection, for the paths that matter more when they break than when
they work:

    ?fail=workflows        that endpoint 401s, as an unscoped token would
    ?fail=move             moving a card is refused
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

LOCATION_ID = "loc_44i_demo"
TOKEN = "pit-mock-token"

LOCK = threading.Lock()

# Endpoints told to fail, by keyword. Set through /__control.
FAILING: set[str] = set()

STATE: dict = {
    "pipelines": [
        {
            "id": "pipe_1",
            "name": "Whitelabel partners",
            "stages": [
                {"id": "stage_new", "name": "New"},
                {"id": "stage_contacted", "name": "Contacted"},
                {"id": "stage_meeting", "name": "Meeting booked"},
                {"id": "stage_signed", "name": "Signed"},
            ],
        },
        {
            "id": "pipe_2",
            "name": "Direct clients",
            "stages": [
                {"id": "stage_d1", "name": "Qualifying"},
                {"id": "stage_d2", "name": "Proposal out"},
            ],
        },
    ],
    "contacts": [
        {
            "id": "c1",
            "contactName": "Marla Weston",
            "firstName": "Marla",
            "lastName": "Weston",
            "companyName": "Prairie Sky Radio",
            "email": "mweston@prairieskyradio.com",
            "phone": "+16055550142",
            "website": "https://prairieskyradio.com",
            "tags": ["prospector", "radio", "fit 88"],
        },
        {
            "id": "c2",
            "contactName": "Dale Hutchins",
            "firstName": "Dale",
            "lastName": "Hutchins",
            "companyName": "Hutchins Outdoor",
            "email": "dale@hutchinsoutdoor.com",
            "phone": "+17015550188",
            "website": "https://hutchinsoutdoor.com",
            "tags": ["prospector", "ooh"],
        },
        {
            "id": "c3",
            "contactName": "Priya Raman",
            "firstName": "Priya",
            "lastName": "Raman",
            "companyName": "Northern Lakes Health",
            "email": "",
            "phone": "",
            "website": "https://northernlakeshealth.org",
            "tags": ["prospector", "healthcare"],
        },
    ],
    "opportunities": [
        {
            "id": "o1", "name": "Prairie Sky Radio", "pipelineId": "pipe_1",
            "pipelineStageId": "stage_new", "status": "open", "monetaryValue": 4800,
            "contactId": "c1", "contact": {"id": "c1", "name": "Marla Weston"},
        },
        {
            "id": "o2", "name": "Hutchins Outdoor", "pipelineId": "pipe_1",
            "pipelineStageId": "stage_contacted", "status": "open", "monetaryValue": 12000,
            "contactId": "c2", "contact": {"id": "c2", "name": "Dale Hutchins"},
        },
        {
            "id": "o3", "name": "Dakota Sound Group", "pipelineId": "pipe_1",
            "pipelineStageId": "stage_contacted", "status": "open", "monetaryValue": 0,
            "contactId": "c1", "contact": {"id": "c1", "name": "Marla Weston"},
        },
        {
            "id": "o4", "name": "Lakeside Media", "pipelineId": "pipe_1",
            "pipelineStageId": "stage_signed", "status": "won", "monetaryValue": 26400,
            "contactId": "c2", "contact": {"id": "c2", "name": "Dale Hutchins"},
        },
        {
            "id": "o5", "name": "Northern Lakes Health", "pipelineId": "pipe_2",
            "pipelineStageId": "stage_d1", "status": "open", "monetaryValue": 60000,
            "contactId": "c3", "contact": {"id": "c3", "name": "Priya Raman"},
        },
    ],
    "notes": {
        "c1": [
            {"id": "n1", "body": "Prospector lead — fit score 88\nVertical: Radio\nBuyer door: Gap Filler",
             "dateAdded": "2026-08-01T14:04:00Z"},
        ],
    },
    "tasks": {
        "c1": [
            {"id": "t1", "title": "Call about Q4 digital gap", "dueDate": "2026-08-14T17:00:00Z", "completed": False},
            {"id": "t2", "title": "Send the audit", "dueDate": "2026-08-05T17:00:00Z", "completed": True},
        ],
    },
    "conversations": [
        {
            "id": "conv1", "contactId": "c1", "fullName": "Marla Weston",
            "email": "mweston@prairieskyradio.com", "unreadCount": 1,
            "lastMessageBody": "Sure — Thursday morning works for a call.",
            "lastMessageDate": "2026-08-11T15:22:00Z",
        },
        {
            "id": "conv2", "contactId": "c2", "fullName": "Dale Hutchins",
            "email": "dale@hutchinsoutdoor.com", "unreadCount": 0,
            "lastMessageBody": "Thanks for sending that over.",
            "lastMessageDate": "2026-08-09T19:40:00Z",
        },
    ],
    "messages": {
        "conv1": [
            {"id": "m2", "direction": "inbound", "messageType": "Email",
             "body": "Sure — Thursday morning works for a call.", "dateAdded": "2026-08-11T15:22:00Z"},
            {"id": "m1", "direction": "outbound", "messageType": "Email",
             "body": "Marla — noticed Prairie Sky is sending digital work out of market. Worth a short call?",
             "dateAdded": "2026-08-10T13:05:00Z"},
        ],
    },
    "workflows": [
        {"id": "w1", "name": "Partner nurture — 5 touch", "status": "published"},
        {"id": "w2", "name": "Audit follow-up", "status": "published"},
        {"id": "w3", "name": "Cold revive (draft)", "status": "draft"},
    ],
    "agents": [
        {"id": "a1", "name": "Inbound qualifier", "status": "active",
         "actions": [{"id": "ac1"}, {"id": "ac2"}]},
        {"id": "a2", "name": "After-hours responder", "status": "paused", "actions": []},
    ],
}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):  # noqa: D102 - quiet by default
        if VERBOSE:
            sys.stderr.write("mock-ghl: " + (args[0] % args[1:]) + "\n")

    # -------------------------------------------------------------- helpers

    def send_json(self, payload: dict, status: int = 200) -> None:
        raw = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def deny(self, message: str, status: int = 401) -> None:
        self.send_json({"message": message}, status)

    def authorised(self) -> bool:
        header = self.headers.get("Authorization", "")
        return header == f"Bearer {TOKEN}"

    def body(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        if length <= 0:
            return {}
        try:
            return json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return {}

    # ---------------------------------------------------------------- routes

    def do_GET(self) -> None:  # noqa: N802
        url = urlparse(self.path)
        path, query = url.path, parse_qs(url.query)

        if path == "/__control":
            with LOCK:
                FAILING.clear()
                FAILING.update(x for x in query.get("fail", []) if x)
            return self.send_json({"failing": sorted(FAILING)})

        # Read-only dump of everything the stand-in has been told, so a test can
        # assert on what actually reached the API rather than on what the app
        # believes it sent. Before the auth check on purpose: it is test
        # scaffolding, not part of the surface being imitated.
        if path == "/__state":
            with LOCK:
                return self.send_json(STATE)

        if not self.authorised():
            return self.deny("Invalid Private Integration token.")

        if path.startswith("/locations/"):
            return self.send_json({"location": {"id": LOCATION_ID, "name": "44i Digital — Partners"}})

        if path == "/contacts/":
            term = (query.get("query") or [""])[0].lower()
            found = [
                c for c in STATE["contacts"]
                if not term or term in json.dumps(c).lower()
            ]
            return self.send_json({"contacts": found})

        if re.fullmatch(r"/contacts/([^/]+)", path):
            cid = path.rsplit("/", 1)[1]
            for contact in STATE["contacts"]:
                if contact["id"] == cid:
                    return self.send_json({"contact": contact})
            return self.send_json({"message": "Contact not found"}, 404)

        if match := re.fullmatch(r"/contacts/([^/]+)/notes", path):
            return self.send_json({"notes": STATE["notes"].get(match.group(1), [])})

        if match := re.fullmatch(r"/contacts/([^/]+)/tasks", path):
            return self.send_json({"tasks": STATE["tasks"].get(match.group(1), [])})

        if path == "/opportunities/search":
            pipeline = (query.get("pipeline_id") or [""])[0]
            found = [
                o for o in STATE["opportunities"]
                if not pipeline or o["pipelineId"] == pipeline
            ]
            return self.send_json({"opportunities": found})

        if path == "/opportunities/pipelines":
            return self.send_json({"pipelines": STATE["pipelines"]})

        if path == "/conversations/search":
            if "conversations" in FAILING:
                return self.deny("This token is missing the conversations.readonly scope.", 403)
            contact = (query.get("contactId") or [""])[0]
            found = [c for c in STATE["conversations"] if not contact or c["contactId"] == contact]
            return self.send_json({"conversations": found})

        if match := re.fullmatch(r"/conversations/([^/]+)/messages", path):
            # Deliberately the nested shape, which is one of the two GHL returns.
            return self.send_json({"messages": {"messages": STATE["messages"].get(match.group(1), [])}})

        if path == "/workflows/":
            if "workflows" in FAILING:
                return self.deny("This token is missing the workflows.readonly scope.", 403)
            return self.send_json({"workflows": STATE["workflows"]})

        if path == "/conversation-ai/agents/search":
            if "agents" in FAILING:
                return self.deny("Conversation AI is not enabled on this plan.", 403)
            return self.send_json({"agents": STATE["agents"]})

        return self.send_json({"message": f"No mock route for GET {path}"}, 404)

    def do_POST(self) -> None:  # noqa: N802
        url = urlparse(self.path)
        path = url.path

        if not self.authorised():
            return self.deny("Invalid Private Integration token.")

        payload = self.body()

        if path in ("/contacts/upsert", "/contacts/"):
            with LOCK:
                contact = {
                    "id": f"c{len(STATE['contacts']) + 1}",
                    "contactName": payload.get("name", ""),
                    "companyName": payload.get("companyName", ""),
                    "email": payload.get("email", ""),
                    "phone": payload.get("phone", ""),
                    "tags": payload.get("tags", []),
                }
                STATE["contacts"].append(contact)
            return self.send_json({"contact": contact})

        if match := re.fullmatch(r"/contacts/([^/]+)/notes", path):
            cid = match.group(1)
            with LOCK:
                note = {
                    "id": f"n{len(STATE['notes'].get(cid, [])) + 1}",
                    "body": payload.get("body", ""),
                    "dateAdded": "2026-08-12T09:00:00Z",
                }
                STATE["notes"].setdefault(cid, []).insert(0, note)
            return self.send_json({"note": note})

        if match := re.fullmatch(r"/contacts/([^/]+)/tasks", path):
            cid = match.group(1)
            with LOCK:
                task = {
                    "id": f"t{len(STATE['tasks'].get(cid, [])) + 1}",
                    "title": payload.get("title", ""),
                    "dueDate": payload.get("dueDate", ""),
                    "completed": False,
                }
                STATE["tasks"].setdefault(cid, []).append(task)
            return self.send_json({"task": task})

        if match := re.fullmatch(r"/contacts/([^/]+)/workflow/([^/]+)", path):
            return self.send_json({"succeded": True, "contactId": match.group(1)})

        if path == "/conversations/messages":
            if "send" in FAILING:
                return self.deny("This token is missing conversations/message.write.", 403)
            cid = payload.get("contactId", "")
            with LOCK:
                conversation = next((c for c in STATE["conversations"] if c["contactId"] == cid), None)
                if conversation is None:
                    conversation = {
                        "id": f"conv{len(STATE['conversations']) + 1}", "contactId": cid,
                        "fullName": "", "email": "", "unreadCount": 0,
                        "lastMessageBody": "", "lastMessageDate": "2026-08-12T09:00:00Z",
                    }
                    STATE["conversations"].append(conversation)
                STATE["messages"].setdefault(conversation["id"], []).insert(0, {
                    "id": f"m{len(STATE['messages'].get(conversation['id'], [])) + 1}",
                    "direction": "outbound",
                    "messageType": payload.get("type", "SMS"),
                    "body": payload.get("message", ""),
                    # Recorded so a test can assert the subject really reached
                    # the API, not just that the app believed it sent one.
                    "subject": payload.get("subject", ""),
                    "dateAdded": "2026-08-12T09:00:00Z",
                })
                conversation["lastMessageBody"] = payload.get("message", "")
            return self.send_json({"messageId": "sent", "conversationId": conversation["id"]})

        if path == "/opportunities/":
            return self.send_json({"opportunity": {"id": "o_new"}})

        return self.send_json({"message": f"No mock route for POST {path}"}, 404)

    def do_PUT(self) -> None:  # noqa: N802
        path = urlparse(self.path).path

        if not self.authorised():
            return self.deny("Invalid Private Integration token.")

        payload = self.body()

        if match := re.fullmatch(r"/opportunities/([^/]+)/status", path):
            with LOCK:
                for opportunity in STATE["opportunities"]:
                    if opportunity["id"] == match.group(1):
                        opportunity["status"] = payload.get("status", opportunity["status"])
                        return self.send_json({"opportunity": opportunity})
            return self.send_json({"message": "Opportunity not found"}, 404)

        if match := re.fullmatch(r"/opportunities/([^/]+)", path):
            if "move" in FAILING:
                return self.deny("That stage does not belong to this pipeline.", 422)
            with LOCK:
                for opportunity in STATE["opportunities"]:
                    if opportunity["id"] == match.group(1):
                        opportunity["pipelineStageId"] = payload.get(
                            "pipelineStageId", opportunity["pipelineStageId"]
                        )
                        return self.send_json({"opportunity": opportunity})
            return self.send_json({"message": "Opportunity not found"}, 404)

        if match := re.fullmatch(r"/contacts/([^/]+)/tasks/([^/]+)/completed", path):
            cid, tid = match.groups()
            with LOCK:
                for task in STATE["tasks"].get(cid, []):
                    if task["id"] == tid:
                        task["completed"] = bool(payload.get("completed", True))
                        return self.send_json({"task": task})
            return self.send_json({"message": "Task not found"}, 404)

        if re.fullmatch(r"/contacts/([^/]+)", path):
            return self.send_json({"contact": {"id": path.rsplit("/", 1)[1]}})

        return self.send_json({"message": f"No mock route for PUT {path}"}, 404)


VERBOSE = False


def main() -> int:
    global VERBOSE

    parser = argparse.ArgumentParser(description="Mock GoHighLevel API v2")
    parser.add_argument("--port", type=int, default=8788)
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    VERBOSE = args.verbose

    server = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    sys.stderr.write(f"mock GoHighLevel on http://127.0.0.1:{args.port} (token {TOKEN}, location {LOCATION_ID})\n")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
