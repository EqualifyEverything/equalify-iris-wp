# CI

Three workflows run Claude on AWS Bedrock. All live in `.github/workflows/`.

| Workflow | Runs when | Does |
| --- | --- | --- |
| `code-review.yml` | A pull request is opened, pushed to, or marked ready | Runs the checks and posts one review |
| `maintainer.yml` | An admin adds `Ready for Build` to an issue | Builds the issue and opens one pull request |
| `maintainer-revise.yml` | The maintainer's pull request is reviewed | Answers the review on the same branch |

None of them can merge, release, or reach the real Iris.

## Code review

Every pull request from a branch in this repository gets one review per commit, posted as
github-actions[bot]. Before the review, the job runs:

- `.github/scripts/php-checks.sh` on the changed files: `php -l` under PHP 8.0, PHPCompatibility, and
  WordPress's security sniffs. The first two block. The sniffs are leads for the reviewer.
- `actionlint` on the workflows, and `shellcheck` on the shell scripts.
- When `plugin/` or `test-site/` changed: the test network, against the mock Iris, and
  `test-site/smoke.sh`.

A failed check is reported in the review rather than failing the job. The job fails only when no
review was posted.

The review is a request for changes when it finds something a real site would hit. Otherwise it is
an approval, with any smaller problems as notes. On a pull request opened by github-actions[bot],
it posts a comment instead, starting with the verdict, because a bot cannot approve its own pull
request.

If the model runs out of time (22 minutes), a fallback review posts what it found so far. A run
replaced by a newer push posts nothing.

On the maintainer's pull requests, the review also reads the maintainer's replies to its last
review. It withdraws a finding when the maintainer's reason holds, and does not repeat it otherwise.

To review a pull request again: `gh workflow run code-review.yml -f pr_number=<n>`.

Pull requests from forks are not reviewed. The job runs the pull request's own scripts while it
can reach Bedrock, so a fork's pull request is read by hand.

## The Iris WP Maintainer

An admin adds the `Ready for Build` label to an issue. Claude then reads the docs and the code,
makes the change, tests it on the test network against the mock Iris, and opens one pull request.
It credits the person who reported the issue. It does not ask the admin for a review yet: see
"Answering reviews".

It does not start, and says why on the issue, when:

- the person who added the label is not an admin (the label is removed);
- the issue's text or title was changed after the label went on (the label is removed);
- the issue already has an open pull request from the maintainer.

It ignores comments added after the label. To include them, remove the label and add it again.

When building the issue as written would make the plugin worse, it says so on the issue and opens
no pull request.

After the model finishes, a separate job checks every pull request it opened. That job runs on its
own machine, so the model cannot change it. It turns a pull request into a draft if it touches
`.github/`, `LICENSE` or a `.gitignore`, adds WordPress files, PDFs or database dumps, or is not on
the issue's branch. It then starts the code review, because a pull request opened by
github-actions[bot] does not start one by itself.

The model's rules are in `.github/maintainer/RULES.md`, which both maintainer workflows use. The
maintainer cannot change it.

## Answering reviews

After each review of the maintainer's pull request, `maintainer-revise.yml` hands the review to the
maintainer. For each finding, the maintainer does one of three things:

- **Fixed**: it agrees, and pushes a fix to the same branch.
- **Declined**: it disagrees, and says why.
- **Needs a person**: it is a decision the maintainer should not make alone.

Its reply goes on the pull request, and the review runs again. This repeats until the pull request
is ready or the maintainer is blocked. Only then does it assign the admin who approved the issue and
ask them for a review, with a note of what is settled and what is open.

It stops and asks the admin when:

- the review approves the latest commit;
- nothing is left that the maintainer agrees to change, including when the review only repeats
  findings it already declined;
- it needs a person, a check is red for a reason outside the pull request, or its changes do not
  pass;
- the review did not complete, or the maintainer's run failed;
- six rounds have passed without an approval. That is a backstop; the maintainer is told to stop
  as soon as more rounds would not make the pull request better.

After that, it waits. To send the pull request back, an admin leaves a review: Comment or Request
changes. Reviews and comments from anyone else are not read. An admin can also start a round by
hand: `gh workflow run maintainer-revise.yml -f pr_number=<n>`.

Each round, the model can only push to that pull request's branch. A separate job then checks what
it pushed. If it rewrote the branch's history or touched a path it may not, the pull request becomes
a draft and the admin is asked to read it.

The revise workflow starts only from `main`, so it works once it is merged.

## Keeping the test network away from the real Iris

The real Iris reports conversion problems as public GitHub issues that can quote the document. In
CI, `.github/scripts/test-site-ci.sh` adds a must-use plugin that always answers the mock's address,
and makes `iris.equalify.uic.edu` resolve to nowhere inside the web container.

## Setup

The repository needs:

- the secret `AWS_BEDROCK_ROLE_ARN`: an IAM role with Bedrock invoke rights, whose trust policy
  allows `repo:EqualifyEverything@128076491/equalify-iris-wp@1332230564:pull_request` and
  `repo:EqualifyEverything@128076491/equalify-iris-wp@1332230564:ref:refs/heads/main`. This
  repository uses GitHub's ID-based subjects, so the plain `repo:EqualifyEverything/equalify-iris-wp`
  form does not match. Today that is `role/equalify-iris-gha-bedrock-review`, shared with Iris;
- the `Ready for Build` label;
- Settings → Actions → General → "Allow GitHub Actions to create and approve pull requests";
- branch protection on `main`, because the maintainer job can push branches.

Optional: the variable `BEDROCK_REVIEW_MODEL`, and `BEDROCK_BUILD_MODEL` for the maintainer. Both
default to `us.anthropic.claude-opus-5`.

## Pinned versions

| What | Where | Update by |
| --- | --- | --- |
| GitHub Actions | Each `uses:` line | The commit SHA of the new tag |
| DDEV | `.github/scripts/install-ddev.sh` | Version and SHA-256 from the release's `checksums.txt` |
| actionlint | `code-review.yml`, "Run the checks" | Version and SHA-256 from the release's checksums file |
| PHP and Composer images | `.github/scripts/php-checks.sh` | Image digest |
| PHP sniffs | `.github/phpcs/composer.lock` | `composer update` in `.github/phpcs/`. Keep wpcs at 3.4.1 or later. |

The checks on what the maintainer may change are in `.github/scripts/pr-path-problem.sh`, used by
both maintainer workflows.
