# Contributing to Open-Locker

Thank you for helping build open infrastructure for shared lockers and
community resources. We welcome issues, pull requests, documentation,
hardware experience, and feedback. To start, just open an issue or join the weekly [Discord](https://discord.gg/rZ74RYKN3H) meetup
(Tuesdays, 19:30 CET / 18:30 UTC).

This document describes how we work together. It is adapted from several
open-source contribution and AI policies (see [Sources and adaptations](#sources-and-adaptations)).
Those sources informed our wording and expectations. This file is not a
verbatim copy of any of them.

Please also read our [Code of Conduct](CODE_OF_CONDUCT.md). Community behavior
is covered there. This guide focuses on contributions to the repository.

## What we expect from you

### You are responsible for what you submit
  
You should understand your changes and be able to explain them in review. If you do not feel ready to
answer questions about your pull request, take time to re-read the diff and
test it before asking maintainers for review.

### Stay on scope
  
Changes should match the issue or PR purpose. Avoid
unrelated drive-by edits unless maintainers agree they belong in the same
change.

### No malicious or harmful code

Do not introduce malware, backdoors,
credential harvesting, or other code intended to harm users, operators, or
the project. Security-sensitive areas (auth, MQTT, Modbus, provisioning)
deserve extra care and honest disclosure in the PR. (Community conduct,
including endangerment and misuse of shared spaces, is covered in the
[Code of Conduct](CODE_OF_CONDUCT.md).)

### Respect the license

By contributing, you agree that your contributions
can be distributed under the same license as this repository. See
[LICENSE](LICENSE) / [LICENSE.md](LICENSE.md). Ensure AI-assisted output
does not paste proprietary or license-incompatible third-party material.

## Before you build a feature

Read [VISION.md](VISION.md) (also published on the
[project website](https://open-locker.org/)), especially *How we evaluate
contributions*, to make sure your planned changes are consistent with our goals.

Architecture-significant changes (API contracts, MQTT topics, Modbus behavior,
infrastructure, security, or cross-component boundaries) need an architecture
decision record under [`docs/adr/`](docs/adr/). Accepted ADRs are superseded,
not rewritten.

## Issues are welcome

Good issues save everyone time. You can always open a bug, idea, or question on
[GitHub Issues](https://github.com/Open-Locker/Open-Locker/issues).

For issue shape and hygiene, we maintain a contributor skill you can follow
when drafting tickets: [`.cursor/skills/github-issue-workflow/SKILL.md`](.cursor/skills/github-issue-workflow/SKILL.md)
(short summaries, no session-only context, no personal data in public issues).

## Development setup and quality checks

Detailed monorepo notes live in the
[website contributing guide](website/src/content/docs/dokumentation/contributing.md)
(layout, codegen, documentation i18n). Before you push, run the checks for
the components you touched:

| Component | Command |
| --- | --- |
| Backend (`locker-backend/`) | `composer quality` |
| Mobile app (`mobile-app/`) | `pnpm check` and `pnpm test:ci` |
| Locker client (`locker-client/`) | `pnpm check` and `pnpm test` |

Installation and environment setup: [docs/Installation.md](docs/Installation.md)
and component READMEs.

### API contract and mobile client

The backend exposes OpenAPI live at `/docs/api.json` (Scramble). If you change
API responses, regenerate the mobile client with `pnpm generate:api` in
`mobile-app/` while the backend is running. Do not hand-edit
`src/store/generatedApi.ts`.

## Testing

Use your judgment. We do not require 100% coverage, but no tests for
non-trivial behavior makes review harder and is discouraged. Add or update
tests when they clearly protect the change you are making. Run the component
quality commands above before opening a pull request.

## Writing for humans

Optimize PR descriptions, issue bodies, and user-facing docs for human
readability: short sections, clear motivation, and enough context that someone
who was not in your coding session can follow along.

Long or tool-oriented guidance belongs in material marked for agents and
maintainers, such as `AGENTS.md`, `.cursor/rules/`, architecture docs, and
ADRs, not in sprawling PR comments. When you add agent-facing documentation,
label it clearly (for example in the title or intro: “For AI agents / maintainers”).

## Agent skills and workflows

We curate skills and agent guidance so contributions stay consistent. They are updated independently of this file.

| Resource | Purpose |
| --- | --- |
| [`.cursor/skills/guided-pr-review/SKILL.md`](.cursor/skills/guided-pr-review/SKILL.md) | Structured review before you ask maintainers for time |
| [`.cursor/skills/github-issue-workflow/SKILL.md`](.cursor/skills/github-issue-workflow/SKILL.md) | Readable GitHub issues |
| [`locker-backend/AGENTS.md`](locker-backend/AGENTS.md) | Backend agent conventions |
| [`website/AGENTS.md`](website/AGENTS.md) | Website agent conventions |
| [`.cursor/rules/`](.cursor/rules/) | Shared Cursor rules (ADRs, monorepo boundaries) |

If you used coding agents to implement a change, run a separate review
pass (for example with the guided-pr-review skill or another independent
agent/reviewer) before you open the PR. That pass does not replace your
own understanding.

## Pull request process

1. Fork and branch from the current default branch (or follow maintainer guidance
   on the issue).
2. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit
   messages when possible (`feat:`, `fix:`, `docs:`, etc.).
3. Describe what changed, why, and how you tested it. Note any
   follow-ups or uncertainties.
4. Ensure CI passes for the components you modified.
5. Respond to review feedback. Keep discussions in the PR unless moderators
   direct otherwise.

Maintainers may decline changes that are too large or hard to review relative
to their benefit, or that look like unreviewed bulk-generated output. Prefer
small, reviewable steps, especially when you are new to the codebase.

## AI-assisted work

Open-Locker allows AI-assisted development. There must always be a human in
the loop: you are the author and are accountable for your code's quality, license
compliance, and security.

### Your responsibilities

- Read and review all tool-generated code, docs, and tests before you
  submit. Refine output to match project conventions.
- Be confident enough that asking for maintainer review is a fair use of
  volunteer time, and be able to answer questions about your work in review.
- If you are new, start with smaller changes you can fully understand.
- Verify that your use of AI tools complies with your employer’s policies
  when applicable.

### Disclosure

You should disclose AI tool use when it materially contributed to what
you submit (for example: a function or test written by a model, an architecture
you adopted from a suggestion, or documentation drafts you kept largely as
generated).

You do not need to disclose routine IDE autocomplete, spell checking, or
general learning questions that did not land verbatim in the contribution. When
in doubt, disclose in the PR description.

Optional commit trailer (especially for non-trivial generated portions):

```text
Assisted-by: ToolName model-version
```

Many tools add `Co-authored-by:` trailers automatically, for example.

### Before maintainers review

- Write or heavily edit the PR description yourself (tools may help with
  language, but the substance should be yours).
- If agents helped implement the change, complete the separate agent review
  pass described above.
- Do not send obviously unreviewed model output to maintainers. Low-effort
  “AI slop” creates an unfair review burden and may be sent back without line-by-line feedback.

### What we discourage

- Unsupervised agents opening pull requests, posting review comments, or
  taking other actions in project spaces without a responsible human who
  approves each action. Opt-in tools that keep a human in the loop are fine.
- Using automation to fully shortcut issues meant for learning and onboarding
  when the issue label or maintainer intent is clearly “for a human newcomer.”

### Copyright and licensing

AI output can raise unsettled copyright questions. You are responsible for
ensuring you have the right to contribute under our license and that your
submission does not reproduce third-party material in an infringing way.
Maintainers may reject or remove contributions that violate license or security
expectations, regardless of how they were produced.

### Review and acceptance

Reviewers judge code quality, security, maintainability, and fit with
[VISION.md](VISION.md). AI must not be the sole or final arbiter of whether
a contribution is accepted.

## Getting help

- [Discord](https://discord.gg/rZ74RYKN3H): weekly community meetup and chat
- Email: [moin@merona.de](mailto:moin@merona.de)
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)

## Sources and adaptations

We are grateful to the communities who published the policies below. Open-Locker’s
guide paraphrases and adapts their ideas (and in some places, short
phrases) for our monorepo, AGPL licensing, and community norms. We do not
claim ownership of their text. If you reuse our adaptations, please credit both
Open-Locker and the upstream projects listed here.

| Source | What we adapted | Upstream license / terms (as stated upstream) |
| --- | --- | --- |
| [LLVM AI Tool Use Policy](https://github.com/llvm/llvm-project/blob/main/llvm/docs/AIToolPolicy.md) | Human-in-the-loop, accountability, disclosure, extractive contributions, unsupervised agents, copyright responsibility | LLVM project documentation is part of [llvm-project](https://github.com/llvm/llvm-project) (Apache-2.0 with LLVM Exceptions). LLVM’s policy also [credits Fedora text under CC BY 4.0](https://github.com/llvm/llvm-project/blob/main/llvm/docs/AIToolPolicy.md). |
| [Fedora Council — Policy on AI-Assisted Contributions](https://discussion.fedoraproject.org/t/council-policy-proposal-policy-on-ai-assisted-contributions/165092) | Accountability, transparency, discouraging unreviewed bulk output, human merge decisions | Fedora community proposal; LLVM cites related text under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). |
| [KubeVirt AI Contribution Policy](https://github.com/kubevirt/community/blob/main/ai-contribution-policy.md) | Disclosure scope (when to disclose / when not), acceptable uses, rejection of unreviewed low-effort AI output | KubeVirt community materials are typically under the same open-source terms as the [kubevirt/community](https://github.com/kubevirt/community) repository (Apache-2.0). |
| [CNCF — HowTo: Make a Contributing Guide](https://cncf-contribute.netlify.app/maintainers/templates/contributing/) | Overall structure (welcome, setup, tests, PR flow) | CNCF contributor documentation; follow CNCF/terms for reuse if copying substantially. |

Open-Locker-specific sections (VISION.md, curated skills, pre-PR agent review,
testing philosophy, issue workflow links, and monorepo commands) are our own
and are not taken from those upstream documents.

If you believe we should adjust attribution or remove wording too close to an
upstream source, please open an issue or contact [moin@merona.de](mailto:moin@merona.de).
