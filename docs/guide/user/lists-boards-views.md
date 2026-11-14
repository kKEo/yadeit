# Lists, boards and saved views

The **Items** page shows the work of the whole workspace. This page explains
how to choose what you see and how, and how to keep a way of looking for
later.

## List or board

Use **Layout** in the toolbar:

- **List** shows one row per item, grouped into sections. It is the fastest
  way to scan many items and to work with the keyboard.
- **Board** shows one column per group. Drag a card to another column to
  change the property the board is grouped by: on a board grouped by state,
  dragging to **In progress** starts the work; grouped by priority, it changes
  the priority.

Both layouts use the same filters, grouping and sorting, so switching does
not lose anything.

## Group and sort

- **Group by** state, priority, assignee or kind, or choose **No grouping**.
- **Sort by** last updated, created, priority, due date, number or demand,
  ascending or descending. *Demand* is how many people asked for an item
  through [feedback](feedback.md).

## Filter

Choose **Filter** to add a filter chip. You can filter by:

| Filter | Shows |
|---|---|
| Project | Items of the chosen projects |
| State or State group | Items in particular states, or all "in progress" ones whatever they are called |
| Priority, Kind, Label | Items with those values |
| Assignee | Items of a person; **Me** means you |
| Open only | Hides done and cancelled items |
| Cycle | **Current cycle**, a particular cycle, or **No cycle** |
| Needs input | Items where an agent waits for an answer |
| Blocked | Items waiting for an unfinished blocker |
| Demand | Items people asked for in feedback |

Type into **Search…** (or press `/`) to narrow the list to items whose title
contains the text, or to one item by its key, such as `WEB-14`. To search
descriptions, comments and docs as well, use the command palette (`Ctrl+K`). Each chip has a cross to remove it; **Clear all** removes
them all.

> **Tip.** Filters are part of the page address. Copy the address to send a
> colleague exactly what you are looking at.

## Built-in views

The **View** menu always offers:

- **All items**
- **Assigned to me**
- **Open**: everything not done or cancelled
- **Recently updated**

## Save your own view

When you have changed filters, layout, grouping or sorting, the **View** menu
shows **Unsaved changes**.

1. Choose **Save as new view…**.
2. Give it a **Name**, such as *My open bugs*.
3. Tick **Share with everyone in the workspace** if the whole team should see
   it. Unshared views are yours alone. Guests cannot share views.
4. Choose **Save view**.

Saved views appear under **Saved views** in the same menu and in the command
palette. After changing a saved view, choose **Update view** to keep the
change. **Rename or share…** and **Delete view…** do what they say; deleting a
view never touches the items in it.

## Live updates

Lists, boards and open items refresh by themselves when someone else changes
something, if your installation has live updates switched on. If it does not,
use **Refresh** or reload the page.

## Long lists

Lists load in pages. **Load more** at the bottom fetches the next items. The
count at the top says how many are loaded so far.
