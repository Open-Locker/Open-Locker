---
name: github-issue-workflow
description: Manage GitHub issues for Open-Locker: propose tickets in chat, avoid duplicates, create/update issues, add sub-issues, and keep labels consistent.
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
  - Context / problem
  - Scope (high-level)
  - Optional notes / open questions
- Keep it short and scannable. Avoid over-specifying acceptance criteria until the behavior is agreed.

### 2) Avoid duplicates

- Run a targeted search first (keywords + component names).
  - Prefer searching within the repo.
  - If a similar issue exists, update it instead of creating a new one.

### 3) Prefer updating existing issues over creating new ones

Typical updates:
- Expand the body with missing requirements/notes.
- Add a short “status / verified in code” note.
- Add/adjust labels.
- Add design discussion as a comment (when requirements are not settled yet).

### 4) Use consistent labels

Repository labels used in this project:
- `backend`
- `mobile-app`
- `iot client`
- `docs`

Rules:
- Apply at least one component label.
- Use multiple labels for cross-cutting work (e.g. backend + mobile-app).
- If a needed label doesn’t exist, ask the user to create it (or create it via GitHub UI if available).

### 5) Split into sub-issues when it’s a multi-track epic

Use sub-issues when:
- The parent issue is a “MVP/epic” and has 3+ independently deliverable parts.
- Different parts can be worked on in parallel (e.g. WiFi provisioning vs OTA).

Sub-issue pattern:
- Create the sub-issues first (with a short body that states “Sub-issue of #X”).
- Then attach them to the parent issue as sub-issues.

### 6) Keep technical decisions auditable

For decisions with trade-offs (protocol contracts, OTA strategy, dedup rules):
- Add a short “Proposed behavior” section
- Add an explicit “Discussion / decisions needed” section
- If a decision is already made elsewhere, link the relevant doc/ADR.

### 7) Prefer minimal churn

- Don’t change issue titles unless it materially improves discoverability.
- Don’t move scopes between issues unless it removes duplication.
- Keep language in issues in English (repo-wide convention).

## GitHub tool usage notes (practical)

This project's Claude Code setup uses the **`gh` CLI** for GitHub operations (no
GitHub MCP server is configured). Common sequence:

- Dupe check: `gh issue list --search "<keywords>"` (or `gh search issues "<keywords>" --repo <owner>/<repo>`)
- Understand existing scope: `gh issue view <number>`
- Create: `gh issue create --title "<title>" --body "<body>" --label <component>`
- Update: `gh issue edit <number> --body "<body>" --add-label <component>`
- Design discussion / status notes: `gh issue comment <number> --body "<note>"`
- Sub-issues: create children first, then link via the issues API
  (`gh api`), since `gh issue` has no first-class sub-issue command. State
  “Sub-issue of #X” in each child body as a fallback.

If a GitHub MCP server is later added, the equivalent MCP tools (`search_issues`,
`issue_read`, `issue_write`, `add_issue_comment`, `sub_issue_write`) follow the same
sequence. If a specific action isn’t available (e.g. creating labels), use the GitHub
UI and document the result in the chat.
