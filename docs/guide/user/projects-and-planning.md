# Projects and planning

This page covers creating projects, the states and labels they come with, and
the two pieces of planning that run by themselves once switched on: cycles and
tidying up stale items.

## Create a project

Choose **Projects** in the sidebar, then **New project**. A project needs:

- a **Name**, such as *Website*;
- a **Key** of 2 to 10 letters or digits, starting with a letter, such as
  `WEB`. Items are numbered under it: `WEB-1`, `WEB-2`. The key cannot be
  changed later.

Members, admins and owners can create projects. Your workspace's plan may
limit how many projects it can have; see
[Workspace settings and plan](settings-and-plan.md).

## States and labels

A new project gets the standard states: **Triage**, **Backlog**, **Todo**,
**In progress**, **In review**, **Done** and **Cancelled**. New items start in
**Todo** unless you choose otherwise. The meaning of each state is on
[The ideas in Yadeit](concepts.md#state).

Labels are picked from the project's list in the item's **Labels** field.

> **Note.** In this version, the web app picks from existing states and labels
> but does not have a screen to add new ones. Adding a state or a label is done
> through the API, for example by a developer or an agent. Ask whoever set up
> your workspace if you need one.

## Cycles

A cycle is a fixed stretch of time, from one to eight weeks, that the team
plans in. Cycles are switched on per project:

1. On **Projects**, open the project's **Planning**.
2. Under **Cycles**, choose a length, for example **2 weeks**, and **Save**.

From then on:

- The first cycle starts on the Monday of the current week.
- When a cycle ends, the next one starts by itself, and items that are not
  finished move into it. The item's history says *Not finished in … : moved
  to …*.
- An item that someone starts working on joins the current cycle.
- **Cycles so far** in the same dialog lists every cycle, marked **Now**,
  **Next** or **Closed**, with how many of its items are done.

To see the current cycle's items, filter the item list by **Cycle** →
**Current cycle**. Choose **Off** to stop making new cycles.

## Return untouched items to the backlog

Boards fill up with items that someone once started and then forgot. Yadeit
can tidy those up:

1. On **Projects**, open the project's **Planning**.
2. Under **Return untouched items to the backlog**, choose a number of days and
   **Save**.

Open items that nobody changed for that long go back to **Backlog**, so the
board shows what is really in progress. The item's history says *Untouched for
N days: returned to the backlog*. Items waiting for someone's answer are left
alone.

## Rename or delete a project

In this version the web app has no screen to rename or delete a project; it is
done through the API. Deleting a project removes all its items, comments and
attachments for everyone, and the workspace's
[audit log](settings-and-plan.md#audit-log) records who did it. Download the
workspace first if you might want the content later.
