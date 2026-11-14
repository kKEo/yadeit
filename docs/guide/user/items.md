# Everyday work with items

Items are the tasks, bugs and ideas your team works on. This page shows how to
create them, fill them in, discuss them and follow them. Everything here is for
members, admins and owners; guests can read items and comment.

## Create an item

Press `C` anywhere in the workspace, or choose **New item** on the **Items**
page. Pick the project, type a title and press `Enter`.

- Only the title is required. Add details now or later.
- Tick **Create more** to keep the form open and add several items in a row.
- If your workspace has AI help switched on, **Draft with AI** turns one
  sentence into a title, a description and acceptance criteria for you to
  check. See [Built-in AI help](ai-help.md).

The new item gets the next number in its project, for example `WEB-14`, and a
short message confirms it.

## Open an item

Click an item in a list or on a board to open it in a **side panel**, so you
keep your place. **Open as full page** gives it the whole window and a link of
its own, like `/w/acme/items/WEB-14`. Press `Escape` to close the panel.

## Change its properties

Every property is changed in place and saved at once; there is no Save button.

| Property | What it is for |
|---|---|
| **State** | Where the work is: Todo, In progress, Done… |
| **Priority** | How urgent: No priority, Low, Medium, High, Urgent |
| **Assignee** | The one person responsible. Leave it empty for **Unassigned**. |
| **Agent** | An AI agent the work is delegated to. See [Working with AI agents](agents.md). |
| **Labels** | Free tags from the project's list |
| **Kind** | Task, Bug, Feature, Design or Research |
| **Due date** | When it should be done |
| **Parent** | The bigger item this one is part of, typed as a key, such as `WEB-12` |
| **Cycle** | The time box it is planned in, if the project uses cycles |

With the keyboard: open or focus an item and press `S` (state), `P`
(priority), `A` (assignee) or `L` (labels); `I` assigns it to you.

> **Note.** If someone else changed the same item a moment before you, Yadeit
> reloads it and tells you: *WEB-14 was changed by someone else.* Check the
> item and make your change again. Nothing is silently overwritten.

## Write a description

Choose **Edit description**. You write in Markdown: `**bold**`, `- lists`,
`[links](https://…)`, code in backticks. **Preview** shows how it will look.
Comments and docs use the same format.

## Checklist

Use the **Checklist** for the steps or the acceptance criteria of the work.
Type into **Add a checklist entry** and press `Enter`; tick entries as they
are done. Agents tick them too when they finish a criterion.

## Links

Under **Links**, paste an address and choose **Add link**. Yadeit recognises
the kind from the address: a pull request, a branch, a design file (for
example Figma) or an ordinary link. Design links have **Show preview**, which
loads the design only when you ask for it.

## Relations

**Relations** connect items with each other:

| Relation | Means |
|---|---|
| **Blocks** / **Blocked by** | One item has to be finished before the other can go on |
| **Relates to** | They are about the same thing |
| **Duplicate of** | The same request twice; keep one |

Type the other item's key, such as `WEB-7`, pick the type and choose **Add**.
An item that waits for an unfinished blocker shows **Blocked** in lists and
boards, with the blocking keys.

## Sub-items

Split big work with **Add sub-item** under **Sub-items**. Each sub-item is an
ordinary item with its own assignee and state; the parent shows how many are
done, for example *2 of 5 sub-items done*. AI help can suggest the split.

## Attachments

Under **Attachments**, choose **Upload files**. Files up to 20 MB each are
accepted. Images show as thumbnails; click one to see them all full size and
step through with **Previous image** and **Next image**. Only people in the
workspace can download attachments.

## Comments

Write under **Add a comment** and choose **Comment**. You can edit your own
comments afterwards; they are marked *(edited)*. You can delete your own
comments, and admins can delete anyone's.

## Activity

**Activity** lists every change to the item: who changed what, from what, to
what, and when. Changes made by an agent or through a token say so, for
example *via token "CI server"*.

## Follow an item

You follow items you created, are assigned to or commented on. Choose
**Subscribe** to follow any other item, and **Unsubscribe** to stop. Followed
items send news to your [inbox](inbox.md).

## Delete an item

**Delete item** removes it from all lists. Members, admins and owners can
delete items (agents and guests cannot), and every deletion is recorded in the
[audit log](settings-and-plan.md#audit-log).
If you just want an item out of the way, set it to **Cancelled** instead: it
stays findable.
