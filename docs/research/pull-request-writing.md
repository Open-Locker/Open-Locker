# Pull request writing

Researched 2026-09-11 against first-party templates and contributor guidance.

## Examples from prominent projects

| Project | Useful pattern | Project-specific details to leave behind |
| --- | --- | --- |
| React | Explain the problem and motivation, then provide concrete verification evidence: commands and results, or visual evidence for UI changes. | React's Yarn, Flow, production-mode commands and CLA process. [PR template](https://github.com/facebook/react/blob/main/.github/PULL_REQUEST_TEMPLATE.md) |
| VS Code | Connect the issue, proposed change, and instructions for testing. Keep a PR focused on one issue or shared root cause. | Its CLA, Electron API rules, and contribution acceptance process. [PR template](https://github.com/microsoft/vscode/blob/main/.github/pull_request_template.md), [contributor guidance](https://github.com/microsoft/vscode/wiki/How-to-Contribute#pull-requests) |
| Kubernetes | Separate purpose, related issues, reviewer notes, and user-facing impact. Highlight required user action and link supporting design documents. | Slash-command labels, release-note fences, KEP conventions, and its AI disclosure process. [PR template](https://github.com/kubernetes/kubernetes/blob/master/.github/PULL_REQUEST_TEMPLATE.md) |
| Django | Give a concise rationale and issue reference; account for tests, documentation, release notes, and visual changes where relevant. | Trac flags, exact commit grammar, target-branch requirements, and its AI disclosure/review policy. [PR template](https://github.com/django/django/blob/main/.github/pull_request_template.md) |

Kubernetes also recommends focused, independently useful changes and explaining
what changed and why. Its commit-message guidance is useful background, but its
commit length and formatting limits are not universal PR title requirements.
[Pull request process](https://github.com/kubernetes/community/blob/master/contributors/guide/pull-requests.md#best-practices-for-faster-reviews)

These are examples of maintainers' requested information, not measured evidence
that a particular template produces faster or better reviews. Source links use
current branches; recheck them before applying an upstream project's policies.

## Recommendation for Open-Locker

The following is a local synthesis, not a copied upstream template:

- Use a stable default with **Summary** and **Testing**. The summary explains
  the trigger or problem, resulting behavior, and essential rationale. Testing
  records what was actually checked and its outcome, including missing coverage.
- Add **Compatibility and rollout** only for material migration, deployment,
  API/MQTT contract, or client-version implications. Add **Motivation** or
  **Implementation** for context, tradeoffs, or review focus that the summary
  cannot cover briefly; add **Screenshots** when visual evidence is available.
- Link verified issues. Use a closing keyword only when the PR actually completes
  that issue; use a neutral reference for partial work.
- Keep short changes short. Expand the explanation when a reviewer needs an
  example, design decision, or operational consequence to judge the change.
- Inspect the repository's current template and contributor rules first; preserve
  required local fields rather than imposing this fallback over them.
- Use an informative outcome-focused title. Apply a title prefix or specific
  grammar only when the repository requires it; the surveyed projects do not
  establish one universal title standard.
- Base claims on the actual branch diff and observed checks. Distinguish passing,
  failing, blocked, and unrun checks. Examples in the skill are illustrative,
  never evidence that a command was run.
- Drafting text and publishing it are different operations. Writing a PR skill
  does not itself authorize commits, pushes, or creating a PR.

The reusable skill belongs in `.agents/skills/pr-writer/`; the existing agent
symlinks provide the same implementation to each supported assistant. This note
holds research provenance so the task-time instructions can stay concise.

## Supporting guidance

Google's engineering guidance emphasizes a specific standalone first line,
explaining what and why, and updating descriptions as a change evolves.
[Writing good CL descriptions](https://google.github.io/eng-practices/review/developer/cl-descriptions.html)

GitHub interprets closing keywords when the PR targets the default branch.
A reference in a PR targeting another branch should not promise automatic closure.
[Linking a pull request to an issue](https://docs.github.com/en/issues/tracking-your-work-with-issues/using-issues/linking-a-pull-request-to-an-issue)

Open-Locker's `.github/git-cliff/*.toml` recognizes conventional commit types.
The skill uses that vocabulary for its local title default; this is a project
choice rather than a claim that the surveyed projects mandate the same format.
