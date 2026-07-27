---
name: guided-pr-review
description: Reviews pull requests with upfront, severity-ranked findings and guides a human reviewer through context, code, and manual test cases. Use for PR reviews, merge assessments, or reviewer test preparation.
metadata:
  version: "0.1.0"
---

# Guided PR Review

Perform an evidence-based code review, show the findings before the walkthrough,
and help a human reviewer form and record their own assessment.

## Operating rules

- Treat the review as read-only unless the user explicitly requests changes.
- Never publish comments, reviews, approvals, or change requests without explicit
  user approval.
- Load repository guidance before reviewing. Inspect relevant `AGENTS.md`,
  `CLAUDE.md`, local rules, architecture documentation, and ADRs when present.
- Verify claims from the PR description against the code and available checks.
- Separate defects, risks, questions, and subjective preferences.
- Prefer existing repository patterns. Call out every newly introduced pattern
  and assess whether it is justified.
- Use subagents for independent analysis lanes when supported. Otherwise perform
  the lanes sequentially.

## Workflow

### 1. Establish scope and context

Collect:

- PR title, description, author, base branch, commits, changed files, and checks
- linked issue or ticket, including discussion and acceptance criteria
- relevant ADRs, product documentation, and repository conventions
- intended behavior, explicit non-goals, rollout, and migration implications

Distinguish newly authored behavior from generated files, vendored code,
mechanical moves, lockfiles, and pre-existing code imported into the repository.

Summarize the change in plain language and identify affected users, components,
data, integrations, and operational processes.

### 2. Set review depth

Classify the change:

- **Trivial**: isolated text, formatting, or mechanically verifiable change with
  no behavioral, security, data, infrastructure, or compatibility impact
- **Standard**: bounded behavior with familiar patterns and moderate impact
- **High risk**: authentication, authorization, secrets, data loss, payments,
  infrastructure, external contracts, concurrency, hardware, migrations, or
  cross-component behavior

Manual testing is expected for standard and high-risk changes. Skip it only for
a trivial change and state why the exception is safe.

### 3. Analyze independently

Cover all four lanes.

#### Code quality and patterns

- Check correctness, readability, cohesion, error handling, and testability.
- Compare the implementation with nearby code and documented conventions.
- Identify new abstractions, architectural patterns, libraries, state models,
  naming conventions, or control flows.
- For each new pattern, explain whether it should be reused, documented,
  replaced with an existing pattern, or rejected.
- Watch for parallel concepts that solve an already-solved problem differently.

#### Business logic and product fit

- Verify that behavior matches the ticket, acceptance criteria, and product
  vision.
- Challenge unnecessary scope, configuration, states, abstractions, and future
  flexibility.
- Look for a smaller or more sustainable solution and explain concrete
  trade-offs rather than merely preferring simpler code.
- Check edge cases, lifecycle behavior, failure recovery, and operational use.

#### Security

- Trace trust boundaries, identities, permissions, inputs, outputs, secrets,
  logs, storage, and external calls.
- Check authorization separately from authentication.
- Consider abuse cases, privilege escalation, injection, information leakage,
  replay, race conditions, unsafe defaults, dependency and CI risks.
- State what was inspected and what could not be verified.
- Do not claim security assurance from static inspection alone.

#### Documentation

- Decide whether the change affects users, operators, developers, APIs,
  architecture, setup, deployment, troubleshooting, or support.
- Require documentation only where it helps a real audience or records a
  durable decision.
- Identify the exact existing document or ADR that should change.
- State explicitly when no documentation update is warranted.

Run relevant automated checks when authorized and practical. Report their exact
scope and result; do not treat passing checks as proof that the behavior is
correct.

### 4. Present findings before the walkthrough

Show the human reviewer the full current assessment before guiding them through
the code. Label findings as provisional until the walkthrough and manual tests
confirm or disprove them.

Order findings by severity:

- **Blocker**: unsafe or incorrect to merge
- **High**: should normally be fixed before merge
- **Medium**: material risk, maintainability issue, or decision needing resolution
- **Low**: optional improvement with limited impact
- **Question**: missing context or an unresolved design decision, not a defect

Each finding must include:

1. concise title
2. evidence with file and location
3. concrete impact or failure scenario
4. recommended action or decision
5. confidence: high, medium, or low

Do not inflate severity for style preferences. If there are no findings, say so
and list residual risks or unverified areas.

### 5. Brief the reviewer

Explain:

- the user or operational problem
- the linked ticket and relevant decisions
- the previous behavior and the new behavior
- scope and non-goals
- the main execution or data flow
- the highest-risk areas

Keep this short enough that the reviewer can retain the mental model.

### 6. Guide the code walkthrough

Choose a conceptual reading order, not merely alphabetical diff order. Usually:

1. entry point or user-facing surface
2. domain or business logic
3. persistence and external boundaries
4. tests
5. configuration, operations, and documentation

For each stop:

- explain why the file matters and what changed
- connect it to the overall flow
- point out relevant findings and design choices
- ask the reviewer for their impression, concerns, and preferred alternative
- record whether the reviewer confirms, rejects, or reframes each finding

Do not use findings to pressure agreement. The reviewer should see the evidence
and retain the final judgment.

### 7. Prepare manual testing

Prepare the environment before asking the reviewer to test:

- prerequisites, services, permissions, feature flags, and test data
- exact setup commands using the repository's preferred tools
- starting state and cleanup or rollback instructions
- URLs, accounts, devices, or observable logs needed

Write numbered test cases. Each case includes:

- purpose and risk covered
- preconditions
- steps
- expected result after each meaningful action
- evidence to capture
- cleanup

Cover, where relevant:

- primary success path
- validation and error handling
- permissions and abuse attempts
- retries, partial failures, and recovery
- boundary and edge cases
- regression of adjacent existing behavior
- accessibility, responsive behavior, and browser/device differences
- observability and operator experience

Let the reviewer execute manual tests unless they explicitly delegate execution.
Collect actual results separately from expected results.

### 8. Reconcile and conclude

Update the findings using code walkthrough feedback and test evidence. Preserve
disagreements and uncertainty instead of forcing consensus.

Conclude with:

- confirmed findings by severity
- dismissed or reframed findings and why
- manual and automated test results
- documentation actions
- residual risks and unverified areas
- recommendation: approve, request changes, or discuss
- follow-up work that is explicitly non-blocking

Draft the final GitHub review separately and ask for approval before publishing.

## Output structure

Use this order:

1. PR context and risk classification
2. provisional findings
3. reviewer briefing
4. guided code walkthrough
5. manual test plan
6. reconciled conclusion

