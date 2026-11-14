# Workspaces and people

This page explains how to create and switch workspaces, invite people, and
choose who may do what. Most of it is for owners and admins; members can read
the section on roles to know what to expect.

## Create or switch a workspace

The name of the current workspace is in the bar at the top of the app. Click
it to see **Your workspaces**, switch to another one, or choose **Create workspace**.
You can also type the workspace's name into the command palette (`Ctrl+K`).

Each workspace has its own web address, like `/w/acme`. Links to items, docs
and views include it, so a link always opens in the right workspace.

## Roles

| Role | Can |
|---|---|
| **Owner** | Everything an admin can, plus delete the workspace, change its plan and make other people owners. A workspace always has at least one owner. |
| **Admin** | Everything a member can, plus invite and remove people, change roles, change workspace settings, switch on AI help, manage webhooks, manage any agent and see the audit log. |
| **Member** | Create and change items, projects, docs and views; triage feedback; create their own agents; use AI help. This is the right role for most of the team. |
| **Guest** | Read items and comment on them, read published docs, and send feedback. A guest cannot change items, create projects or see drafts. Use it for clients and outside reviewers. |

Agents are members too, but they can never be admins or owners. See
[Working with AI agents](agents.md).

## Invite people

Owners and admins invite from **Members**:

1. Under **Invite people**, type the email address, choose a role and choose
   **Send invitation**.
2. The person receives an email with a link that is valid for a few days.
   Open invitations are listed under **Pending invitations** with the date they
   expire. **Withdraw** cancels one.

When the person opens the link they see **Join** with your workspace's name.
If they have no account, they create one with the invited email address; if
they do, they sign in and choose **Accept invitation**.

> **Note.** You must confirm your own email address before you can invite
> anyone. This keeps the invitation emails from being misused.

> **Note.** An invitation works only for the email address it was sent to. If
> the person is signed in with another account, Yadeit says so and asks them to
> sign out first.

## Change a role or remove someone

On **Members**, use the role menu next to a person to change their role, or
**Remove** to take them out of the workspace. Removing takes effect at once:

- They lose access to the workspace immediately.
- They stop receiving notifications about its items.
- Agents they own stop working in it, and their access tokens are revoked.

The same happens to agents when their owner becomes a guest. Their past work
stays in the history.

You can invite a removed person again at any time.

## Leave a workspace

On **Members**, choose **Leave workspace**. You need a new invitation to come
back. The last owner cannot leave; make someone else an owner first, or delete
the workspace.
