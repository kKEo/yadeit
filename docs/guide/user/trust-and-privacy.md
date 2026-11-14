# Trust and privacy

This page says what you can rely on: who can see your work, what leaves the
installation and when, what agents are allowed to do, and what is recorded.
The legal details are in the installation's **Privacy policy** and **Terms of
service**, linked at the bottom of the sign-in and landing pages.

## Who can see your work

- **Only members of a workspace see it.** People who are not members cannot
  even tell a workspace exists; its address answers "not found" to them.
- **Guests see less**: no draft docs, only their own feedback, and they cannot
  change items.
- **Agents see only what their token allows**: read only or read and write,
  all projects or a few.
- **Nothing is public unless someone makes it so.** Only two things work
  without signing in: a doc page someone shared by link, and a project's
  public feedback form. Both can be switched off at once, and agents cannot
  switch them on.
- **Attachments are private**: only members of the workspace can download
  them.

## What leaves the installation

- **Email** for confirmations, invitations, password resets, notifications
  (if you switched them on) and announcements from whoever runs the
  installation.
- **AI help**, only when an admin switched it on for the workspace, and only
  the text needed for the suggestion you asked for, sent to Anthropic at that
  moment.
- **Webhooks**, only to addresses an admin added, signed so the receiver can
  check them.
- **Payments**, only if the workspace buys a paid plan; card details go to
  Stripe and never pass through Yadeit.

Nothing is sold, nothing is used for advertising, and there are no tracking
cookies.

## What agents can and cannot do

Agents cannot close, delete or reassign items, cannot change who is in the
workspace, cannot share pages publicly and cannot use AI help. They stop when
their owner leaves the workspace. Everything they do is in the item's
history. Text inside items is treated as a description of work, not as
instructions the agent must follow. See [Working with AI agents](agents.md).

## What is recorded

- Each item's **Activity** records every change to it.
- The workspace's **Audit log** records who changed access, sharing and
  settings, and who deleted what. Admins and owners see it.
- Sign-ins and failed sign-ins are recorded with the address they came from,
  for the people who run the installation.
- Emails are logged with recipient, subject and time, not their text, and the
  log is kept for 90 days.

## Your control over your data

- [Download your data](account.md#download-your-data) at any time.
- [Delete your account](account.md#delete-your-account) yourself.
- Owners can [export](settings-and-plan.md#export-the-workspace) or
  [delete](settings-and-plan.md#delete-the-workspace) the whole workspace.

Deleted content leaves the live service at once; copies in backups disappear as
the backups expire.
