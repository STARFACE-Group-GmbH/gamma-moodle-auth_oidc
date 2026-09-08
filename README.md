# auth_oidc — Gamma fork

Fork of the Microsoft `auth_oidc` plugin. It connects Moodle to Gamma SSO (Keycloak);
the Moodle instance itself is used for the Academy.

The plugin stays component-compatible: the component name remains `auth_oidc` and the
install path remains `auth/oidc`. **No rename** — renaming would touch 1225 occurrences
across 66 files and 4 database tables, turning every upstream merge into a conflict.

## What we do here

The upstream plugin already authenticates against Keycloak. What was missing was PKCE,
which Gamma SSO requires, and control over what a Moodle logout does to the shared SSO
session. On top of that we added ID token signature verification as an optional security
measure, and a label so it is obvious which plugin an instance is running.

## Features

**1. Gamma branding**
`pluginname` set to "OpenID Connect (Gamma)". No functional code — a pure string
substitution, so the plugin can be told apart from an unmodified upstream install in the
plugin and authentication lists.

Only actually shows in English: any other installed language pack ships its own AMOS
`auth_oidc` translation, which overrides ours.

**2. PKCE S256**
RFC 7636 PKCE added to the authorization code flow (`classes/oidcclient.php`,
`classes/loginflow/authcode.php`), controlled by the new `enablepkce` admin setting
(default on). The code verifier is stored in the `auth_oidc_state.codeverifier` database
column rather than in the session, because the IdP replies with `response_mode=form_post`
and a `SameSite=Lax` cookie is not sent along with that cross-site POST. Schema change
via `db/install.xml` and `db/upgrade.php`. IdP error responses (`error`,
`error_description`) are never shown to the user — that branch is reachable via a plain
unauthenticated GET, before state is validated, so attacker-chosen text would appear
verbatim in an official Moodle error banner. A generic message (`errorauthgeneral`) is
shown instead; the detail still goes to the Moodle log via `utils::debug()`.

**3. SSO logout confirmation**
New `single_sign_off_confirm` admin setting (default off, generic IdP only, gated under
`single_sign_off`). When enabled, `id_token_hint` is omitted and `client_id` is sent
instead, so the IdP asks the user whether to end the entire SSO session. The previous
behaviour — silent logout with `id_token_hint` — remains the default. Change confined to
`auth.php` (`build_logout_url`) plus setting and language strings.

**4. JWT signature verification**
New class `classes/idtoken_validator.php` validates ID token signatures against the IdP
JWKS endpoint. The approach is taken from `microsoft/moodle-local_o365` (`lib.php`, JWKS
fetch for the Teams tab flow), where it is already in production but implemented for a
different context; here it is adapted to the regular authorization code flow. JWKS keys
are cached through the Moodle cache API with a fixed 24-hour TTL (`db/caches.php`). Covered by
`tests/idtoken_validator_test.php`.

## Before deploying to a target instance

Usually a fresh install; occasionally an upgrade of an existing `auth_oidc`. Check:

- **PKCE** — upgrade only: confirm the IdP accepts `code_challenge` first, or disable
  `enablepkce`. Irrelevant on a fresh install (IdP is configured for it from the start).
- **ID token signature verification** — either case: `verifyidtokensignature` defaults
  off, needs `jwksuri` + `idtokenissuer` set first (no sensible default, IdP-specific).
  Settings page refuses to enable it without both.
- **Companion plugins** — upgrade only, and rare: this fork adds `get()` to
  `httpclientinterface`. A pre-existing plugin implementing that interface directly (e.g.
  `local_o365`) hits a PHP fatal until updated; grep for
  `implements httpclientinterface`/`extends oidcclient` before upgrading such a site.

## Source

| | |
|---|---|
| Original repository | https://github.com/microsoft/moodle-auth_oidc |
| **Moodle we run** | **4.5.11** — the Academy's installation, and the version this fork targets |
| Base of our release line | tag `v4.5.7` (on upstream branch `MOODLE_405_STABLE`) |
| Minimum Moodle required by the plugin | 4.5 (`$plugin->requires = 2024100700`) |

Upstream `master` requires Moodle 5.2 or later and is therefore not usable for us,
which is why our release line branches off the 4.5 tag.

## Remotes

| Remote | URL | Role |
|---|---|---|
| `origin` | `git@gitlab.com:gamma-communications/moodle_academy/gamma-moodle-auth_oidc.git` | company repository, canonical state, development happens here |
| `gh-partner` | `git@github.com:heinzeale/gamma-moodle-auth_oidc.git` | private GitHub repository; access for the Moodle partner (build and review) |
| `upstream` | `https://github.com/microsoft/moodle-auth_oidc.git` | Microsoft, **fetch only** — the push URL is set to `DISABLED` |

## Branch model

> **Default branch: `release/4.5`** — in both `origin` and `gh-partner`. Clone it, build
> from it, review it. It is the 4.5 line because the Academy runs **Moodle 4.5.11**, and
> upstream's newer plugin releases (`master`, `MOODLE_502_STABLE`) require Moodle 5.2 or
> later. There is deliberately no `main`; see below.

```
upstream/MOODLE_405_STABLE ──v4.5.7──┐
                                     │
                     release/4.5 ────┴──●──●──●──▶   our changes, release tags live here
                                        ┊  ┊  ┊
                                        ┊  ┊  ┊       cherry-pick -x, only when the
                                        ▼  ▼  ▼       Academy upgrades Moodle
upstream/MOODLE_502_STABLE ──v5.2.0──┐
                                     │
                     release/5.2 ────┴──●──●──●──▶   same commits, new upstream base
```

One release branch per Moodle line, named after that line. `release/4.5` is based on tag
`v4.5.7`, carries all our commits, is the branch the local dev server mounts, and is the
branch the partner builds from. A second line is only created when it is actually needed —
see "Adopting a new Moodle line" below for the exact procedure.

There is deliberately **no `main`**. Upstream's stable lines diverge — `v4.5.7` is not an
ancestor of upstream `master`, and `MOODLE_405_STABLE` was never merged into
`MOODLE_502_STABLE`; their common ancestor is 4.5.2. A branch that merged both lines
would drag upstream's 4.5.3–4.5.7 changes into the 5.2 line and conflict in
`version.php`, `db/upgrade.php` and the language files. Carrying our work across Moodle
lines is therefore a cherry-pick operation, not a merge — the same approach Moodle core
itself uses.

Within one line, merges are fine and are the way upstream fixes arrive: see the upstream
cycle below. **Never rebase onto an upstream tag** — that would orphan existing release
tags by rewriting their ancestry.

## Feature update (our own change)

```bash
git switch -c feat/foo release/4.5
# ... commits ...
git switch release/4.5 && git merge --no-ff feat/foo
git push origin release/4.5
git push gh-partner release/4.5
```

### `version.php` convention

Changes that need an upgrade step must raise the plugin version, otherwise
`db/upgrade.php` never fires. To avoid claiming a version number that upstream will hand
out later, we append a decimal Gamma step to the upstream version instead of
incrementing it — **counting from `.50` upwards**, because upstream uses low decimal
steps itself (`2024100720.01`, `2024100730.01`) and would collide with us:

```php
$plugin->version = 2024100735.51;      // upstream 2024100735 plus Gamma step 51
$plugin->release = '4.5.7+gamma2';
```

Upstream's next number (`2024100736`) stays strictly greater, so ordering survives a
merge and the resulting conflict is trivial to resolve. Upgrade steps guard on the same
value:

```php
if ($oldversion < 2024100735.50) {
    // ...
    upgrade_plugin_savepoint(true, 2024100735.50, 'auth', 'oidc');
}
```

Steps used so far on this line: `.50` PKCE (`4.5.7+gamma1`), `.51` ID token signature
verification (`4.5.7+gamma2`).

Purely additive changes without database or cache definitions — language strings, admin
settings — do not need a version bump.

## Upstream cycle (pulling a Microsoft fix)

Upstream publishes 4.5.x fixes on branch `MOODLE_405_STABLE`, tagged `v4.5.8`, `v4.5.9`,
and so on.

```bash
git fetch upstream --tags
git switch release/4.5
git merge v4.5.8                          # merge the tag, do not rebase
git push origin release/4.5 --tags
git push gh-partner release/4.5 --tags
```

Expect conflicts in `version.php` and `db/upgrade.php`: keep upstream's base number,
re-apply our decimal step on top (`2024100736` upstream → our `2024100736.01`), and keep
our upgrade blocks below upstream's.

## Adopting a new Moodle line

This is what has to happen when the Academy upgrades Moodle and we therefore want the
newest upstream plugin release as our base. Nothing here needs doing in advance — the
work is deliberately deferred until the Moodle upgrade is real.

**1. Pick the upstream tag that matches the target Moodle.** Upstream ships one stable
branch per Moodle line (`MOODLE_502_STABLE` for Moodle 5.2, and so on) with tags such as
`v5.2.0`. Check `$plugin->requires` in that tag against the target Moodle version.

**2. Branch the new release line off that tag.**
```bash
git fetch upstream --tags
git switch -c release/5.2 v5.2.0
```

**3. Cherry-pick our commits forward, oldest first.** `-x` records the original hash in
the message, so both lines stay traceable and it is obvious which Gamma change is which.
```bash
git log --oneline --reverse v4.5.7..release/4.5      # our commits, in order
git cherry-pick -x <hash> <hash> <hash> ...
```

Expect conflicts in `version.php` and `db/upgrade.php` — resolve them by applying the
decimal Gamma step to the *new* upstream base (`v5.2.0`'s number plus `.50`), and by
placing our upgrade blocks after upstream's. The `README.md` commit conflicts too, since
upstream's README differs between lines; keep our part and re-attach it above the new
original.

**4. Verify against the new Moodle.** The API changes between Moodle lines are what makes
this a cherry-pick rather than a merge: `\core\url` and `\core\context` namespacing,
changed hook signatures, and `db/upgrade.php` expectations all shift. `postlogout_hook`
and the login flow classes we touch are the parts to re-test — plus the PHPUnit suite
including `tests/idtoken_validator_test.php`.

**5. Switch the default branch** in `origin` and `gh-partner` to the new line once it is
the one being deployed, and say so in this README.

Both release branches then live side by side. A fix that applies to both gets
cherry-picked into each; the lines are never merged into one another, because upstream's
stable lines are not ancestors of each other.

**Estimating the effort:** it scales with the number of our commits (currently four
functional ones) and with how far apart the two upstream bases are, not with the size of
the Moodle upgrade. The Gamma changes are confined to `classes/oidcclient.php`,
`classes/loginflow/authcode.php`, `classes/loginflow/base.php`, `classes/idtoken_validator.php`,
`auth.php`, `settings.php`, `db/*` and the language files — a diff against upstream shows
the full surface:
```bash
git diff v4.5.7..release/4.5 --stat
```

## Building a release

```bash
git switch release/4.5
git tag -a gamma-4.5.7+2 -m "Gamma auth_oidc 4.5.7+2"
git archive --format=zip --prefix=oidc/ gamma-4.5.7+2 > auth_oidc-gamma-4.5.7+2.zip
git push origin gamma-4.5.7+2 && git push gh-partner gamma-4.5.7+2
```

`--prefix=oidc/` is mandatory — Moodle expects the plugin directory to be named `oidc`.

## Pushing to the partner

`gh-partner` is pushed **manually and deliberately**; there is no automated mirroring job, so
the partner only ever sees states we consider ready rather than every work-in-progress
commit.

---

*Everything below is the unmodified original README from Microsoft.*

---

# Moodle Plugins for Microsoft Services
*including* **Microsoft 365** *and other Microsoft services*

## OpenID Connect Authentication Plugin

The OpenID Connect plugin provides single-sign-on functionality using configurable identity providers.

This is part of the suite of Microsoft 365 plugins for Moodle.

This repository is updated with stable releases. To follow active development, see: https://github.com/Microsoft/o365-moodle

## Installation

1. Unpack the plugin into /auth/oidc within your Moodle install.
2. From the Moodle Administration block, expand Site Administration and click "Notifications".
3. Follow the on-screen instructions to install the plugin.
4. To configure the plugin, from the Moodle Administration block, go to Site Administration > Plugins > Authentication > Manage Authentication.
5. Click the icon to enable the plugin, then visit the settings page to configure the plugin. Follow the directions below each setting.

For more documentation, visit https://docs.moodle.org/405/en/Microsoft_365

For more information including support and instructions on how to contribute, please see: https://github.com/Microsoft/o365-moodle/blob/master/README.md

## Issues and Contributing
Please post issues for this plugin to: https://github.com/Microsoft/o365-moodle/issues/

Pull requests for this plugin should be submitted against our main repository: https://github.com/Microsoft/o365-moodle 

## Copyright

&copy; Microsoft, Inc.  Code for this plugin is licensed under the GPLv3 license.

Any Microsoft trademarks and logos included in these plugins are property of Microsoft and should not be reused, redistributed, modified, repurposed, or otherwise altered or used outside of this plugin.
