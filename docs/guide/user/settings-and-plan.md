# Workspace settings and plan

This page is for workspace owners and admins. It covers everything under
**Settings** in the sidebar: the workspace's name and address, its plan, AI
help, webhooks, the audit log, export and deletion. Members can open
**Settings** too but cannot change anything there.

## Name and web address

Under **General**, change the **Workspace name** or its **Web address** and
**Save**.

> **Warning.** Changing the web address breaks every link anyone saved to the
> workspace, its items and its views. Shared doc links and public feedback
> forms keep working.

## Plan

**Plan** shows what the workspace's plan allows and how much is used:

- **People**: members, admins, owners and guests. Open invitations count too.
  Agents never count.
- **Projects**.
- Built-in AI tokens a month.

When a limit is reached, Yadeit refuses the one thing that would go over it
(for example a fourth project on a plan with three) and says which limit it
was.

If your installation offers paid plans, an owner sees **Move to** a bigger plan
and is taken to a secure payment page. **Manage billing** opens the billing
portal to change the card, download invoices or cancel. A cancelled plan runs
until the end of the period already paid. Only owners can change the plan.

> **Note.** Many self-hosted installations have no limits and no paid plans.
> Then this section only shows what is used.

## AI help

Switch AI help on or off, see the month's usage and set a limit. See
[Built-in AI help](ai-help.md).

## Webhooks

A webhook makes Yadeit send a message to another service when items change,
for example to start an agent or post to a chat. Setting one up needs someone
who runs that service.

1. Under **Webhooks**, give it a **Name** and the **Address** (it must start
   with `https://`).
2. Tick **Events** to send only some of them, or leave all unticked for every
   event.
3. Choose **Add webhook** and **copy the signing secret now**; it is shown
   only once. The receiving service uses it to check that messages really come
   from your workspace.

**Send test** sends a test event. **Deliveries** lists what was sent and
whether it arrived. A webhook that keeps failing is **Switched off after
repeated failures**; fix the receiving service and choose **Switch on**.

## Audit log

The **Audit log** shows who changed access, sharing and settings, and who
deleted what: invitations, role changes, removed members, new agents and
tokens, webhooks, project and state changes, deleted items and pages, pages
shared by link, AI settings, exports. Use **Show** to narrow it to one area.
Only admins and owners see it.

Day-to-day work on items is not in the audit log; it is in each item's
**Activity**.

## Export the workspace

Under **Export**, choose **Download the workspace**. You get one JSON file with
the members, projects, items with their comments, pages and feedback.
Attachments are listed by name; the files themselves are not included.
Only owners can export, at any time.

## Delete the workspace

Under **Delete workspace**, an owner can remove the workspace with all its
projects, items, docs, files and members. Yadeit asks you to confirm.

> **Warning.** This cannot be undone. Export the workspace first if you might
> need anything from it. A workspace with a running paid plan cannot be
> deleted until the plan is cancelled, so nothing keeps charging.
