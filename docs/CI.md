# CI

Two workflows run Claude on AWS Bedrock. Both live in `.github/workflows/`.

| Workflow | Runs when | Does |
| --- | --- | --- |
| `code-review.yml` | A pull request is opened, pushed to, or marked ready | Runs the checks and posts one review |
| `maintainer.yml` | An admin adds `Ready for Build` to an issue | Builds the issue and opens one pull request |

Neither can merge, release, or reach the real Iris.

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

If the model runs out of time (22 minutes), a fallback review posts what it found so far.

To review a pull request again: `gh workflow run code-review.yml -f pr_number=<n>`.

Pull requests from forks are not reviewed. The job runs the pull request's own scripts while it
can reach Bedrock, so a fork's pull request is read by hand.

## The Iris WP Maintainer

An admin adds the `Ready for Build` label to an issue. Claude then reads the docs and the code,
makes the change, tests it on the test network against the mock Iris, and opens one pull request.
It asks the admin for a review and credits the person who reported the issue.

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

## Keeping the test network away from the real Iris

The real Iris reports conversion problems as public GitHub issues that can quote the document. In
CI, `.github/scripts/test-site-ci.sh` adds a must-use plugin that always answers the mock's address,
and makes `iris.equalify.uic.edu` resolve to nowhere inside the web container.

## Setup

The repository needs:

- the secret `AWS_BEDROCK_ROLE_ARN`: an IAM role with Bedrock invoke rights, whose trust policy
  allows `repo:EqualifyEverything/equalify-iris-wp:pull_request` and
  `repo:EqualifyEverything/equalify-iris-wp:ref:refs/heads/main`;
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
