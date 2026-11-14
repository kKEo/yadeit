# Use cases

Four short stories of how teams use Yadeit, to help you decide how to set up
your own. Each one names the features it relies on, with links to the pages
that explain them.

## A small product team running two-week cycles

A team of five builds a web app. They have one project, `APP`, with two-week
[cycles](projects-and-planning.md#cycles) and stale items returning to the
backlog after 30 days.

- On Monday the lead filters the list by **Cycle** → **Current cycle**, groups
  by assignee, and saves it as a shared view called *This cycle*.
- During the cycle everyone works from the board, dragging cards to **In
  progress** and **In review**.
- When the cycle ends, unfinished items move to the next one by themselves.
  Nobody has to tidy the board.

Uses: [saved views](lists-boards-views.md#save-your-own-view),
[board](lists-boards-views.md#list-or-board), [cycles](projects-and-planning.md#cycles).

## A developer with a coding agent

A developer owns an agent called *Code assistant* with a read-and-write token
limited to the `API` project, connected to Claude Code.

- They delegate small, well-described bugs to the agent with the **Agent**
  field, with acceptance criteria in the **Checklist**.
- The agent claims each item, posts its plan, links its pull request and
  submits for review. Once it asked which error message to show; the
  developer answered in a comment and chose **Mark as answered**.
- The developer reviews the pull request and moves the item to **Done**.

Uses: [Working with AI agents](agents.md).

## A design team collecting UX feedback

A design team runs a public feedback form for their product, linked from its
help menu.

- Feedback lands in the **Feedback** inbox. Twice a week a designer triages
  it with the keyboard: `1` to accept, `3` for duplicates, `2` to decline.
- Repeated requests are added to existing items, so each item shows how many
  people asked for it.
- Research and design items carry the kinds **Research** and **Design**, Figma
  links with previews, and screenshots as attachments. The team sorts their
  backlog by **Demand**.

Uses: [Feedback and design work](feedback.md).

## An agency sharing progress with a client

An agency keeps one workspace per client. The client's contact is a
**Guest**: they can read items, comment and send feedback, but not change the
plan.

- Decisions and specifications are docs, published when agreed.
- A release note is shared with the client's wider team by link, without
  giving them accounts.

Uses: [roles](workspaces-and-people.md#roles), [Docs](docs.md).
