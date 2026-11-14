# Yadeit webhooks

A webhook makes Yadeit send a JSON `POST` to an address you choose when work items change. Typical uses: start an agent when an item is delegated or answered, or post to a chat when someone needs input. Workspace admins manage webhooks in the web app under **Settings**, or through `{{api}}/workspaces/{workspace}/webhooks`.

## Events

`item.created`, `item.updated`, `item.deleted`, `item.commented`, `item.claimed`, `item.released`, `item.input_requested`, `item.input_provided`, `item.submitted_for_review`, `item.run_failed`, and `ping` for the test button. A webhook receives all events or only the ones it lists.

## Payload

```json
{
  "id": "0b0f6c0e-6f43-4f2e-9a52-0f0d7b8a3c11",
  "event": "item.input_requested",
  "activity": "input_requested",
  "created_at": "2026-11-14T10:00:00+00:00",
  "workspace": "acme",
  "actor": { "id": 20, "name": "Coder", "type": "agent" },
  "item": {
    "key": "WEB-143", "title": "Fix login", "project": "WEB",
    "state": { "name": "In progress", "group": "started" },
    "priority": "high", "kind": "bug",
    "assignee": { "id": 7, "name": "Ada", "type": "human" },
    "delegate": { "id": 20, "name": "Coder", "type": "agent" },
    "needs_input": true,
    "url": "https://app.example.com/w/acme/items/WEB-143"
  },
  "changes": { "needs_input": { "from": false, "to": true } },
  "comment": { "id": 91, "body": "Which provider should I use?" }
}
```

`changes` and `comment` are present when they apply. `activity` is the precise change; smaller ones (a checklist tick, a new link) arrive as `item.updated`.

## Verifying a request

Each request carries:

- `X-Yadeit-Signature: t=<unix time>,v1=<hex>`
- `X-Yadeit-Event`: the event name
- `X-Yadeit-Delivery`: the event id, the same on every retry

Compute HMAC-SHA256 of `<t>.<raw request body>` with the webhook's secret and compare it with `v1` using a constant-time comparison. Reject requests whose `t` is more than a few minutes old.

```python
import hashlib, hmac, time

def verify(secret: str, header: str, body: bytes) -> bool:
    parts = dict(p.split("=", 1) for p in header.split(","))
    expected = hmac.new(secret.encode(), f"{parts['t']}.".encode() + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, parts["v1"]) and abs(time.time() - int(parts["t"])) < 300
```

## Delivery

- Answer with any 2xx status within 10 seconds. Do slow work after answering.
- Other answers and timeouts are retried after 10 seconds, 1 minute, 5 minutes and 30 minutes. 4xx answers (except 408 and 429) are not retried.
- The same event can arrive more than once. Use `id` to ignore repeats.
- After 10 events in a row fail for good, the webhook switches itself off. An admin can switch it on again.
- Addresses must be `https` and public; redirects are not followed.
