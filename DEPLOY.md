# Publishing the Supaship PHP SDK (beginner guide)

This document explains **end to end** how a public PHP library on GitHub becomes installable with Composer (Packagist). No prior publishing experience is assumed.

## What you are actually “publishing”

PHP libraries are **not uploaded** to Packagist like some other ecosystems. Instead:

1. Your code lives in a **Git** repository (for example on GitHub).
2. **Packagist** (packagist.org) registers that repository and watches it for **new Git tags**.
3. When you create a version tag (for example `v1.0.0`), Packagist creates a **release** of your package that `composer require` can install.

So “publish” means: **push clean code to GitHub** and **create a semver Git tag**. Packagist does the rest once it is connected.

## Glossary

| Term | Meaning |
|------|--------|
| **Composer** | PHP’s dependency manager (`composer install`, `composer require`). |
| **Packagist** | The default registry Composer uses to find packages. |
| **Package name** | The `name` field in `composer.json` (here: `supashiphq/php-sdk`). It must be **unique** on Packagist. |
| **Semantic version** | Versions like `1.0.0`, `1.1.0`, `2.0.0`. Users depend on ranges like `^1.0`. |
| **Git tag** | A named pointer to a specific commit, e.g. `v1.0.0`, often used as a release marker. |

## One-time prerequisites

1. **Git** installed, and you can push to the GitHub remote for this repo.
2. **PHP** (8.1+) and **Composer** installed on your machine for local checks.
3. A **Packagist.org account** (free): sign up at https://packagist.org

## One-time: connect the GitHub repository to Packagist

These steps assume the library already has a valid `composer.json` in the **root** of the repo (this project does).

### Step 1 — Confirm `composer.json`

Open `composer.json` and check:

- **`name`**: `vendor/package` format, lowercase, matches what you want on Packagist (`supashiphq/php-sdk`).
- **`type`**: `"library"` for a reusable package.
- **`license`**, **`require`** (PHP version and extensions), **`autoload`**: all set.

Locally run:

```bash
composer validate --strict
composer install
composer test
```

Fix any errors before submitting to Packagist.

### Step 2 — Push the repository to GitHub

If the repo is not on GitHub yet:

1. Create a **new empty** repository on GitHub (no README/license needed if you already have them locally).
2. From your laptop, in the SDK folder:

```bash
git remote add origin https://github.com/ORGANIZATION/php-sdk.git
git push -u origin main
```

Use your real org/user and repo URL.

### Step 3 — Submit the package on Packagist

1. Log in to https://packagist.org
2. Click **Submit** (top right).
3. Paste the **GitHub repository URL** (HTTPS), e.g. `https://github.com/ORGANIZATION/php-sdk`
4. Click **Check**, then **Submit**.

Packagist will read `composer.json` from the default branch and register the package.

### Step 4 — Enable automatic updates (GitHub webhook)

Without a webhook, you must click **Update** on Packagist after every change. With a webhook, new tags and branch updates sync automatically.

On Packagist, open your package → **Maintainers** / **Settings** (wording may vary) and follow **“GitHub Service Hook”** or **“Webhook”** instructions. Typically you:

1. Copy a **webhook URL** and/or token from Packagist.
2. On GitHub: repo **Settings → Webhooks → Add webhook** and paste the URL Packagist gives you.

After this, pushing tags or commits usually triggers Packagist to refresh within about a minute.

## Every release: tagging a new version

Semantic versioning (https://semver.org) in short:

- **MAJOR** (x.0.0): breaking API changes.
- **MINOR** (1.x.0): new features, backwards compatible.
- **PATCH** (1.0.x): bug fixes, backwards compatible.

### Step 1 — Prepare the repo

1. Merge work to `main` (or your release branch).
2. Run tests and validation:

```bash
composer validate --strict
composer install
composer test
```

3. **Commit** any pending changes with a clear message.

### Step 2 — Choose the next version number

Examples:

- First public release: `1.0.0`
- Bugfix: bump patch (`1.0.0` → `1.0.1`)
- New backwards-compatible feature: bump minor (`1.0.1` → `1.1.0`)

### Step 3 — Create an annotated Git tag

Many PHP projects use a **`v` prefix** on tags (e.g. `v1.0.0`). The **tag name** is what Packagist turns into a version; it must match what Composer expects.

```bash
git checkout main
git pull

git tag -a v1.0.0 -m "Release 1.0.0"
git push origin v1.0.0
```

- **`-a`**: annotated tag (recommended; includes message and metadata).
- **`git push origin v1.0.0`**: publishing the tag is what notifies Packagist (via webhook).

### Step 4 — Confirm on Packagist

Open `https://packagist.org/packages/supashiphq/php-sdk` (adjust if your vendor name differs). Within a short time you should see the new version listed.

If it does not appear:

- Check **GitHub Actions** on the repo (this project runs **Release** workflow on `v*` tags).
- On Packagist, use **Update** manually once.
- Verify the tag exists on GitHub (**Releases** or **Tags** tab).

### Step 5 — Optional: GitHub Release notes

A **Git tag** is enough for Packagist. A **GitHub Release** (with notes) is optional but nice for humans:

1. GitHub repo → **Releases** → **Draft a new release**
2. Choose the tag `v1.0.0`
3. Add title and changelog, publish.

This does not replace Packagist; it is documentation for users browsing GitHub.

## How users install the published package

After the package is on Packagist:

```bash
composer require supashiphq/php-sdk
```

They can pin versions:

```bash
composer require supashiphq/php-sdk:^1.0
```

## GitHub Actions in this repo

| Workflow | When it runs | Purpose |
|----------|----------------|--------|
| `ci.yml` | Push / PR to `main` or `master` | `composer validate`, install deps, **`composer test`** on PHP 8.1–8.3. |
| `publish.yml` | **Manually** (Actions → Run workflow) | Asks for a **version**, runs the same checks, then creates a **GitHub Release** and **tag** with release notes built from commits since the **previous GitHub release** (or recent history if this is the first release). |

### Using `publish.yml` instead of tagging by hand

1. Open the repository on GitHub → **Actions**.
2. Select **Publish Supaship PHP SDK**.
3. Click **Run workflow**.
4. Enter a version (`1.0.0` or `v1.0.0`).
5. After tests pass, the workflow creates the tag and release; Packagist can pick up the new tag like any other tag push.

You can still tag locally if you prefer; **`publish.yml` is optional** but keeps validation and notes in one place.

These workflows **do not** log in to Packagist for you. Public packages are normally updated by Packagist’s **GitHub integration** when tags appear on the default branch.

## Troubleshooting

| Problem | What to check |
|---------|----------------|
| Packagist says **“package not found”** after `composer require` | Typo in package name; Packagist not submitted yet; wait a few minutes after submit. |
| **New tag missing** on Packagist | Webhook missing or failed (GitHub **Settings → Webhooks**); click **Update** on Packagist; confirm `git push origin vX.Y.Z` succeeded. |
| **`composer validate` fails** | Invalid JSON, missing required fields, bad version constraints. |
| **Tests fail on CI** | Match PHP extensions (`json`, `openssl`); run `composer test` locally. |

## Changing the public package name

If you change `name` in `composer.json`, you effectively create a **new** Packagist package. Avoid unless you intend to deprecate the old name and coordinate with your team and docs.

---

**Summary:** Push your repo to GitHub → register it once on Packagist → set up the webhook → for each release, merge to `main`, run tests, then either run the **`publish.yml`** workflow (recommended: validates, then creates the GitHub Release and tag) **or** tag manually with `git tag` / `git push origin vX.Y.Z`. Users install with `composer require supashiphq/php-sdk`.
