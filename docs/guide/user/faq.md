# FAQ and troubleshooting

Answers to the questions people ask most, and what to do when something does
not work. If your problem is not here, ask an admin of your workspace, or
whoever runs your installation.

## Signing in

**The confirmation email did not arrive.** Check your spam folder. Then sign
in and choose **Resend confirmation email** in the bar at the top. If nothing
arrives at all, the installation's email may not be set up; tell whoever runs
it.

**The confirmation link says it is invalid or has expired.** Sign in and use
**Resend confirmation email** for a fresh link.

**I forgot my password.** Use **Forgot your password?** on the sign-in page.
See [Your account](account.md#forgotten-password).

**Sign-in says too many attempts.** Wait a minute and try again. Yadeit slows
down repeated attempts to protect your account.

## Invitations

**The invitation says "Invitation not available".** It was used, withdrawn or
has expired. Ask for a new one.

**It says I am signed in with a different account.** Invitations work only for
the address they were sent to. Sign out, then sign in or create an account with
that address.

**I cannot invite anyone.** Confirm your email address first, and check that you
are an admin or owner of the workspace. Your plan may also have reached its
limit of people; open invitations count towards it.

## Items and projects

**I cannot change an item.** You are probably a guest in this workspace.
Guests can read and comment. Ask an admin for the **Member** role.

**My change was undone and a message says the item was changed by someone
else.** Two people changed it at almost the same moment. Yadeit kept their
change and reloaded the item; make yours again.

**How do I add a new state or label?** This version of the web app has no
screen for it; states and labels are added through the API. Ask whoever set up
your workspace.

**How do I rename or delete a project?** The same: through the API for now.

**Can an item have two assignees?** No, on purpose: one person is responsible.
Split the work into sub-items for each person.

**An item shows "Blocked".** It has a **Blocked by** relation to an item that
is not finished. Finish that item or remove the relation.

**My item moved to the backlog by itself.** The project returns items nobody
touched for a while to the backlog. The item's activity says so. See
[Projects and planning](projects-and-planning.md#return-untouched-items-to-the-backlog).

**My item moved to another cycle by itself.** It was not finished when its
cycle ended, so it moved to the next one.

**The project key is wrong.** Keys cannot be changed. Create a new project with
the right key.

**A file will not upload.** Files can be up to 20 MB each.

## Views and search

**Search does not find a word I know is in a comment.** The search box on the
**Items** page looks at titles and keys only. Use `Ctrl+K` to search
descriptions, comments and docs as well.

**My colleague cannot see my saved view.** Views are personal until you tick
**Share with everyone in the workspace**.

**Shortcuts do not work.** They are off while the cursor is in a text field.
Click an empty part of the page or press `Escape`, then try again.

**The list does not update when others change things.** Your installation may
not have live updates switched on. Use **Refresh** or reload the page.

## Agents

**My agent gets "not allowed" or "forbidden" answers.** Check its token: is it
**Read only**, limited to other projects, or expired? Agents also cannot close,
delete or reassign items; that is by design.

**My agent stopped working.** Its token may be revoked or expired, or you may
no longer be a member (or only a guest) of the workspace. An agent stops when
its owner does.

**I lost the token.** It cannot be shown again. **Revoke** it and create a new
one.

**The agent asked a question and is still waiting.** Answer in a comment and
choose **Mark as answered**; the agent looks for that.

## AI help

**I do not see Draft with AI, Summarise or Suggest.** AI help is off for your
workspace, you are a guest, or no AI key is set. An admin can check under
**Settings** → **AI help**.

**AI help stopped working mid-month.** The workspace reached its monthly token
limit. An admin can raise it, or it resets next month.

## Workspaces and plans

**"Workspace not found".** The address is wrong, or you are not a member.
Ask an admin to invite you.

**A message says the workspace is suspended.** Whoever runs the installation
paused it. Nothing is deleted. Contact them.

**Something is refused because of a plan limit.** The message names the limit.
An owner can move to a bigger plan under **Settings** → **Plan**, or free up
room (for example by withdrawing open invitations).

**I cannot delete my account.** You are the only owner of a workspace other
people use. Make someone else an owner, then try again.
