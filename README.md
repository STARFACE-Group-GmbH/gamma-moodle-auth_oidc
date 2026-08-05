# auth_oidc — Gamma fork

Fork of the Microsoft `auth_oidc` plugin. It connects Moodle to Gamma SSO (Keycloak);
the Moodle instance itself is used for the Academy.

The plugin stays component-compatible: the component name remains `auth_oidc` and the
install path remains `auth/oidc`. **No rename** — renaming would touch 1225 occurrences
across 66 files and 4 database tables, turning every upstream merge into a conflict.

## What we do here

We add functionality that upstream lacks, or that upstream implements for Microsoft
Entra ID only, so the plugin can authenticate against Gamma SSO — a generic OIDC
provider (Keycloak) rather than Microsoft Entra ID:

- PKCE (S256) in the authorization code flow
- ID token signature verification
- optional user confirmation on single sign-out
- Gamma label in the plugin and authentication lists

## Source

| | |
|---|---|
| Original repository | https://github.com/microsoft/moodle-auth_oidc |
| Base of our release line | tag `v4.5.7` (on upstream branch `MOODLE_405_STABLE`) |
| Target Moodle | 4.5.11 |
| Licence | GPLv3, unchanged — see the original README below |

Upstream `master` requires Moodle 5.2 or later and is therefore not usable for us,
which is why our release line branches off the 4.5 tag.

## Remotes

| Remote | URL | Role |
|---|---|---|
| `origin` | `git@gitlab.com:gamma-communications/moodle_academy/gamma-moodle-auth_oidc.git` | company repository, canonical state, development happens here |
| `gh-partner` | `git@github.com:heinzeale/gamma-moodle-auth_oidc.git` | private GitHub repository; access for the Moodle partner (build and review) |
| `upstream` | `https://github.com/microsoft/moodle-auth_oidc.git` | Microsoft, **fetch only** — the push URL is set to `DISABLED` |

## Branch model

```
upstream/MOODLE_405_STABLE ──v4.5.7──┐
                                     │
                     release/4.5 ────┴──●──●──●──▶   our changes, release tags live here
```

One release branch per Moodle line. `release/4.5` is based on tag `v4.5.7`, carries all
our commits, is the branch the local dev server mounts, and is the branch the partner
builds from. It is also the default branch in both remotes.

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

When the Academy moves to a newer Moodle, branch a new release line from the matching
upstream tag and cherry-pick our commits forward. `-x` records the original hash in the
message, which keeps the two lines traceable.

```bash
git switch -c release/5.2 v5.2.0
git cherry-pick -x <our commits from release/4.5>
```

Both release branches then live side by side; a fix that applies to both gets
cherry-picked, not merged.

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

`git push gh-partner --mirror` is for the initial push only. Do not use it afterwards:
`--mirror` deletes every ref on the remote that does not exist locally, including review
branches the partner creates there. Regular pushes never delete anything.

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
