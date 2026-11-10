---
name: github-issue-workflow
description: Manage GitHub issues for Open-Locker: propose tickets in chat, avoid duplicates, create/update issues, add sub-issues, keep labels consistent, omit personal data, write for readers without session context, open with a short summary, and place every issue on the OpenLocker project board in Todo or earlier.
---

## Goal

Provide a consistent workflow for creating and maintaining GitHub issues in this repository with minimal duplicates and high readability.

## When to use

- When the user wants to create, update, or triage GitHub issues/tickets.
- When planning work across multiple components (backend, mobile, IoT, docs).
- When splitting a large topic into sub-issues.

## Workflow (use this order)

### 1) Start with a readable draft in chat

- Always propose the ticket in the chat first, using:
  - Title
  - Summary (short, plain language, first section of the body)
  - Context / problem
  - Scope (high-level)
  - Optional notes / open questions
- Keep it short and scannable. Avoid over-specifying acceptance criteria until the behavior is agreed.
- Write the draft so it stands alone. The reader was not in this session.
- Strip personal data from the draft before showing it. Do not wait until after the issue exists.

### 2) Write for people who were not in this session

The issue must be understandable to a third person who has none of the current session: no chat, no screenshot unless it is attached, no prior messages.

- Do not assume the reader knows the ticket, the conversation, or what was just tried.
- Do not use insider shorthand such as “as discussed”, “the error from earlier”, or “see above” when “above” is this chat.
- State what happened, why it matters, and what should change so the issue stands alone.
- Technical detail belongs after the summary, in its own section, and still has to make sense without the session.

Every issue body starts with a short **Summary** for someone who only wants an overview.

- A few sentences in plain language.
- Say what the ticket is about: the problem and the outcome, not the investigation.
- Someone scanning the board should understand the topic from the summary alone.
- Keep names, paths, and implementation notes out of the summary. Put those in a later section.

### 3) Never put personal data in a ticket

Personenbezogene Daten do not belong in issue titles, bodies, comments, or attached text.

Do not include:
- Email addresses
- Real names of people
- Phone numbers
- Postal addresses
- Account identifiers that identify a person
- Any other data that identifies a natural person

Describe the report without those details (for example “a production account” instead of copying the address from a screenshot). If a draft or an existing issue already contains personal data, remove it before creating or updating the issue.

### 4) Avoid duplicates

- Run a targeted search first (keywords + component names).
  - Prefer searching within the repo.
  - If a similar issue exists, update it instead of creating a new one.

### 5) Prefer updating existing issues over creating new ones

Typical updates:
- Expand the body with missing requirements/notes.
- Add a short “status / verified in code” note.
- Add/adjust labels.
- Add design discussion as a comment (when requirements are not settled yet).

### 6) Use consistent labels

Repository labels used in this project:
- `backend`
- `mobile-app`
- `iot client`
- `docs`

Rules:
- Apply at least one component label.
- Use multiple labels for cross-cutting work (e.g. backend + mobile-app).
- If a needed label doesn’t exist, ask the user to create it (or create it via GitHub UI if available).

### 7) Put every issue on the project board

Every issue created or newly taken up through this workflow goes on the **OpenLocker Project** board (GitHub Projects, organization `Open-Locker`, project number 2).

Place it in **Todo**, or in the column before Todo (**Awesome Stuff**) when it is not ready to be picked up yet. Do not leave it off the board. Do not put a new issue in In Progress, Review, or Done unless the user explicitly asks for that status.

After the issue exists:
- Add the issue URL to the project.
- Set Status to Todo, or to Awesome Stuff when it belongs before Todo.
- Look up the current Status field and option ids when editing. Do not rely on remembered ids.

Sub-issues follow the same rule.

### 8) Split into sub-issues when it’s a multi-track epic

Use sub-issues when:
- The parent issue is a “MVP/epic” and has 3+ independently deliverable parts.
- Different parts can be worked on in parallel (e.g. WiFi provisioning vs OTA).

Sub-issue pattern:
- Create the sub-issues first (with a short body that states “Sub-issue of #X”).
- Then attach them to the parent issue as sub-issues.

### 9) Keep technical decisions auditable

For decisions with trade-offs (protocol contracts, OTA strategy, dedup rules):
- Add a short “Proposed behavior” section
- Add an explicit “Discussion / decisions needed” section
- If a decision is already made elsewhere, link the relevant doc/ADR.

### 10) Prefer minimal churn

- Don’t change issue titles unless it materially improves discoverability.
- Don’t move scopes between issues unless it removes duplication.
- Keep language in issues in English (repo-wide convention).

## GitHub MCP tool usage notes (practical)

Common sequence:
- `search_issues` (dupe check)
- `issue_read` (understand existing scope)
- `issue_write` (create/update)
- `add_issue_comment` (design discussion, status notes)
- `sub_issue_write` (attach sub-issues)
- Add the issue to the OpenLocker Project board and set Status to Todo (or Awesome Stuff)

If a specific GitHub action isn’t available via MCP (e.g. creating labels), use the GitHub UI and document the result in the chat.
