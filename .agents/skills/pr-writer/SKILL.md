---
name: pr-writer
description: Draft, create, or refresh pull request titles and structured descriptions from branch changes. Use for PR writing and updates, not code review or commit-only requests.
---

# Pull request writer

Produce a reviewer-ready title and description that explain the problem, resulting
behavior, and evidence. Write in English unless the user or repository requires
otherwise. Use consistent sections with detail proportional to the change.

## Establish the scope

1. Read repository instructions, contribution guidance, and the applicable PR
   template. Preserve required sections and checklist items; use the default
   structure below where the repository has no template.
2. Inspect the current branch, working tree, commits, and full branch diff. For
   an existing PR, read its title, body, base/head branches, and current checks.
   Use its base branch; for a new PR, use the user's specified target or the
   verified repository default. Resolve the corresponding remote ref and compare
   from the merge base (`git diff BASE...HEAD`). Do not assume `main` or `dev`.
3. Separate committed changes from staged/uncommitted work. A proposal may include
   intended local changes if labeled; a published PR description must describe
   its actual head. Do not claim local checks cover a different revision.
4. Read relevant issue/spec context and validation output already available.
   Distinguish a confirmed absent PR from authentication/network failures. If
   evidence is unavailable, state the limitation instead of inventing details.

## Issue context

Use issue context when it exists. First extract issue numbers or URLs from the
user's request, branch name, commits, existing PR text, and repository guidance.
When the user mentioned an issue, inspect that issue with `gh issue view` and
use its problem statement, expected outcome, constraints, and acceptance details
to frame the PR. Do not rely on the issue number alone.

When no issue was supplied, search the repository's issue list using specific
terms from the change, for example:

```bash
gh issue list --repo OWNER/REPO --state all --search "<distinctive problem terms>" --limit 50
```

Inspect plausible matches and use a reference only when the issue clearly covers
the change. If no issue matches, omit an issue reference. Never invent a number or
claim that the PR closes an issue it only partially addresses.

The description must connect the issue to the implementation: explain why the
issue mattered, what behavior the PR changes to address it, and what remains
outside this PR. Use `Fixes #N` only when the implementation completes the issue;
use `Refs #N` for partial or related work. Keep issue context in Summary or a
short Motivation section so reviewers can understand the change without opening
another page.

Scope is established when each material claim can be traced to the diff, supplied
context, or an observed check. Old PR prose and commit messages are leads, not
substitutes for inspecting the change.

## Title

Follow repository conventions. For Open-Locker, use
`type(scope): imperative description`, omitting scope when the change spans
components. Prefer `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `build`,
`ci`, `chore`, `style`, or `revert`; choose an established component scope.

Describe the dominant change across the whole PR, with a specific outcome and no
trailing period. Aim for roughly 72 characters without sacrificing clarity.
Use `!` only for an actual compatibility break and explain the migration in the
body. Re-evaluate the title when refreshing a PR whose scope changed.

## Default description

Use these two sections in this order. Replace all placeholder text before delivery.

```markdown
## Summary

Explain the concrete problem and resulting behavior in one short paragraph.
Include the reason for the change; use a few bullets only for distinct outcomes.

## Testing

Describe the relevant checks actually performed, their results, and what they
establish. State material untested behavior and the reason when known.
```

Keep these sections even for a small change; one sentence each can suffice.
For documentation-only work, report actual document/link checks or explicitly
state that no checks ran. Never manufacture application tests to fill the section.

Add only sections that answer a concrete reviewer question:

| Section | When to include it | What to explain |
| --- | --- | --- |
| Motivation | The problem needs more context than Summary can hold | Reproduction, root cause, constraints, or verified issue context |
| Implementation | Reviewers need to understand a non-obvious approach | Key design choice, alternatives/tradeoff, or review order |
| Compatibility and rollout | API, schema, config, storage, MQTT, or deployment behavior changes | Affected consumers, migration, deployment ordering, and rollback limits |
| Screenshots | Visual behavior changed and evidence is available | Labeled before/after images and what to inspect |

Place explanatory sections after Summary and Testing last. Summarize material
risks in the relevant section; omit boilerplate assurances such as "no risk".
If visual evidence is unavailable, say so under Testing rather than adding an
empty Screenshots section. Use a small before/after example or diagram only when
it explains behavior more clearly than prose.

In Testing, distinguish passed, failed, not run, and pending CI. Name regression
coverage and meaningful manual scenarios. Include an exact command only when it
helps reproduce a check; omit logs and exhaustive command lists. Check checklist
boxes only when evidence supports completion.

End with verified issue references where useful. Use `Fixes #N` only when the PR
fully resolves the issue and closure is intended; use `Refs #N` for partial or
related work. GitHub closing keywords take effect for the default branch, so do
not promise issue closure on a merge into another target branch.

## Write and deliver

- Explain behavior and reviewer impact before file names or implementation
  details. Avoid a file-by-file inventory, repeated content across sections,
  conversation history, and internal agent/process terminology.
- On updates, rewrite for the final whole change. Preserve relevant human-written
  context, verified issue links, and required checklist content. Skip a remote
  update if the existing title and description remain accurate.
- For a writing-only request, return the requested title and/or Markdown body;
  respect description-only requests. Creating or
  updating a remote PR requires a request covering that action; skill activation
  alone does not authorize pushing, committing, or publishing.
- When creation is requested, reuse a matching existing PR and otherwise create
  a draft unless the user asks for ready-for-review. If branch preparation is
  incomplete, deliver the draft text and identify the prerequisite.
- Use structured tool fields or a UTF-8 temporary body file with `gh pr create`
  or `gh pr edit --body-file`; preserve actual newlines and literal Markdown.
  Re-read the remote PR to verify the requested update before reporting success.

Before delivery, check title/scope alignment, evidence for every result, required
template fields, readable Markdown, verified links, and removal of placeholders
or sensitive data. The description should be understandable without this chat.

For examples of section sizing, read [examples](references/examples.md).
