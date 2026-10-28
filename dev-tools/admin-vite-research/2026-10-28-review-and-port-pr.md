

> Historical research record from 2026-10-27/28.
> See the archive [README](README.md) and [support matrix](SUPPORT_MATRIX.md) for the current scope.

# Review and shared listener PR

Updated acceptance and measurements are in [the follow-up record](2026-10-28-acceptance-and-benchmarks.md).
This file records the earlier snapshot.

## Published changes

- Twig and native SFC template HMR: https://github.com/shopware/shopware/pull/22113.
- Shared Administration listener: https://github.com/shopware/shopware/pull/22119.
- HMR head: `85352dc887ce5731f4a8214d26cdc8e6a1a74eb0`.
- Port head: `71d6f67f7115082fc980ab06416f40570f6d3ded`.
- Local combined branch: `codex/admin-vite-integration` in `/path/to/projects/shopware-vite-poc`.
- Combined head: `f0cb3a703c287ac482ebe88d91d83b0501a18796`.

Both feature branches merge with the checked trunk without conflicts.
Merging the feature branches in either order produces the same tree.
Their published diffs contain source code and regression tests only.
Prototype tools, generated fixtures, benchmark results, research documents, and release-note edits are excluded from both diffs.

## Architecture decision

The port fix uses Vite middleware mode for each extension and shares the Administration HTTP server for WebSocket upgrades.
The Environment API prototype did not establish compatibility with arbitrary extension Vite configurations in one shared module graph.
Keeping isolated compiler instances preserves the existing configuration and module-resolution boundary while removing separate TCP listeners and the extension launcher process from the Vite 8 path.
This is a listener consolidation claim, not evidence of RAM savings.
An Admin plugin and Meteor app from the same bundle have distinct URL prefixes.
Child watcher shutdown follows the parent server lifecycle.
Extension HMR transport inherits the parent address, including a configured non-default port.

## Review correction in HMR

The earlier global block-index reset discarded eager Twig block entries belonging to unresolved components.
A new regression test reproduced the loss before the fix.
HMR now resets and rebuilds only the edited component's index and preserves other components and condition continuation state.
The local setup-SFC script-update experiment remains in the pre-existing stash and was not included.
Native SFC script edits still require a manual browser reload.

## Validation

- HMR-related suites: 810 tests passed.
- Port-related suites: 32 tests passed.
- Combined suites: 842 tests passed.
- The final address inheritance change passed the five shared-listener tests and a real HTTP/WebSocket check on port 5190.
- Repository Administration lint, type, and formatting checks passed with existing repository warnings.
- New middleware modules passed direct ESLint and Prettier checks.
- The port branch completed the production Administration build with Commercial and 40 generated plugins.
- The Vite 6 path started both legacy processes and served the extension directly and through its proxy.
- Existing Vite 8 topology: 77 Admin extension entries and 78 TCP listeners across the main and extension processes.
- Port branch alone: 77 entries, 2,992 fetched modules, one Vite process, and one TCP listener at 5173.
- Final combined branch: 77 entries, 2,994 fetched modules, one Vite process, and one TCP listener at 5173.
- A separate small Meteor app from the same bundle as plugin 40 rendered and applied a visible hot update independently of that plugin.
- An SCSS edit reached its extension's WebSocket and produced no update on the control extension.
- Twig updates worked independently and combined while the native SFC counter remained at 2.
- Native SFC template updates also preserved the counter at 2 on both topologies.
- The real Commercial ContentGenerator Twig override updated visibly in the Imprint CMS editor through the combined shared listener.
- Restoring the Commercial source removed the marker live, and its source checkout was clean afterward.
- No CMS layout was saved during the probe.

## Test environment correction

The one-way Commercial Mutagen session was deleting generated Vite dependency caches under `.tmp`.
This caused persistent optimized-dependency 504 responses during the full graph check.
The local `shopware-vite-poc-commercial` session was recreated with the existing exclusions plus `**/.tmp/`.
All graph requests passed once those caches persisted.
This session change is local environment configuration and is absent from the Shopware PRs.

## Remaining draft acceptance cases

Real browser acceptance for experimental bundled development mode and HTTPS remains outstanding for the port PR.
Native SFC script HMR is outside the verified template update path.
The CI runs were still in progress when the PRs were prepared; local validation is not a claim that every CI job has finished.

## Change impact

- Blast radius: the Administration development launcher and the ADMIN_VITE_8 development server; the production compiler configuration remains shared with the existing build path.
- Who pays: development sessions enabling Vite 8 use the shared transport, while the Vite 6 path retains its original server topology.
- Easier or more complex: developers manage one development port and retain their extension configuration boundaries.
- Verdict: ready as two independent draft PRs, with the remaining acceptance cases stated in their descriptions.
