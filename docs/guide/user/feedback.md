# Feedback and design work

Feedback is what people tell you they need or what went wrong for them. Yadeit
collects it in one inbox per workspace, lets you decide what becomes work, and
counts how many people asked for each thing. This page also covers the tools
for design and research work.

## Where feedback comes from

| Source | Who sends it |
|---|---|
| **In the app** | Anyone in the workspace, guests included, with **Send feedback** |
| **Public form** | Anyone with the project's form link, without an account |
| **Agent** | An AI agent that noticed something worth reporting |

To send feedback yourself, open **Feedback** and choose **Send feedback**.
Pick the project, say what you need **In one sentence**, add **Details** if
useful, and send. Guests see only the feedback they sent themselves.

## Triage: decide what happens

Open **Feedback**. New entries wait under **Inbox**. For each one, choose:

| Action | Key | What happens |
|---|---|---|
| **Accept as new item** | `1` | A new item is created from it, and the feedback counts for it |
| **Add to an item…** | | You type an existing item's key; the feedback counts as one more voice for that item |
| **Decline** | `2` | It moves to **Declined**, with an optional reason |
| **Duplicate of…** | `3` | You pick the original feedback; if that is linked to an item, this one counts for it too |
| **Snooze** | `H` | It leaves the inbox **Until tomorrow** or **For a week**, then comes back |

The tabs **Snoozed**, **Accepted**, **Declined** and **Duplicates** keep
everything that left the inbox. **Back to inbox** undoes a decision.

With AI help switched on, **Suggest** proposes a decision for an entry: a
likely duplicate, an existing item that already covers it, or a new item with
a kind and priority. You still choose.

## Demand: how many asked

Every piece of feedback linked to an item is one more voice for it. Items show
*Asked for once*, *Asked for 3 times*, and so on. On the **Items** page you can
**Sort by** **Demand** and filter by **Demand** to see what people ask for
most.

## The public form

Owners and admins can open a feedback form for a project that works without an
account, for example to link from your website or your product:

1. Open **Feedback**, find **Public form** and choose **Switch on** for the
   project.
2. **Copy link** and put it wherever your users are.

Visitors see *Feedback for* your project, write what they need, and may leave
a name and an email if they want an answer. The form limits how fast one
visitor can send, and catches simple spam robots.

**Switch off** closes the form for good: the link stops working, and switching
on again creates a new link.

## Design and research work

- Give design and research items the kind **Design** or **Research**, and
  filter by **Kind**. The command palette has **Show design work** for the
  quickest view of it.
- Add design files under **Links**. Yadeit recognises design tools such as
  Figma from the address, and **Show preview** shows the design inside the
  item, loaded only when you ask.
- Attach screenshots and mock-ups as images. They form a gallery you can step
  through full size.
- Use feedback demand to decide which UX problems to tackle first, and link the
  research item to the feedback that prompted it with **Add to an item…**.
