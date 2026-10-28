# Template HMR dependency safety follow-up

> Historical research record from 2026-10-27/28.
> See the archive [README](../README.md) and [support matrix](../SUPPORT_MATRIX.md) for the current scope.

Date: 2026-10-28.
PR: https://github.com/shopware/shopware/pull/22113.
Feature head: f616fb0f22875bc34a3e71d350b8d54a48985d00.
The follow-up contains three source/test files and no research, benchmark corpus, or documentation files.

## Result

The imported-helper correctness gap from the script-HMR assessment is fixed by a safe full Administration reload.
The shopware-setup Vite hook follows the changed module's importer graph to already-loaded native setup SFCs.
The walk handles transitive, cyclic, and shared paths with a visited set.
Changed runtime-script or JSON dependencies are invalidated and compiled through Vite before the reload is sent.
An invalid dependency throws through Vite's existing error path, leaving the mounted page and its previous valid behavior intact.
A repaired dependency is compiled and then applied through a full reload.
Unrelated dependencies and styles do not trigger this fallback.
The existing native template bridge is preserved.

An initial attempt to classify updates by Vue's rerender-only marker failed the real Shopware template-state check.
That attempt was discarded and is absent from the pushed commit.
The final graph-based implementation preserves state on the tested native and Twig template updates.

## Validation

- New helper regression cases fail against the previous implementation.
- 942 related setup, Twig, component-factory, adapter, and transport tests pass in 25 suites with the final source.
- Direct lint of the three changed files passes.
- Administration TypeScript passes.
- The full composer eslint:admin wrapper passes, including ESLint, SCSS, and formatting; existing warnings remain.
- Live transitive helper edit: the host reloads and the displayed computed changes from 1 to 2.
- Live invalid transitive helper: error overlay appears, the host document stays, and the previous computed remains rendered.
- Live repair: the host reloads once, the error overlay disappears, and the new computed value 3 renders.
- Native template edit: the host document stays and the local counter remains 2.
- Twig template edit: the host document stays and the native counter remains 2.
- The signed App and shared-port Meteor SDK App remain singly registered in their stores.
- The temporary native and Twig fixtures are restored from their saved bytes; the helper and leaf probe files are removed.

Raw browser evidence is in browser-cases.json.
The JSON test report is final-tests.json.
The full lint output is full-lint.log.
The screenshot template-state-preserved.png records the successful state-preserving template test.

## Branches and independence

The clean feature worktree is /path/to/worktrees/template-hmr-safety/shopware-trunk.
Its branch remains codex/vite-shared-server-poc.
The combined test lane remains /path/to/projects/shopware-vite-poc on codex/admin-vite-integration.
The combined integration commit is edab1ac7457682b99f0156d165b297cc745b90b4.
Combining the HMR head with port head 07e1110ebd241161cbe5032b82c30604c75eb82b in either order produces tree 998e104fa3195a126d31f8d5743411a2477e662c.
The port PR branch was not changed by this follow-up.

## Readiness boundary

This record supersedes the imported-helper blocker in the preceding research assessment.
The implemented path remains template HMR plus safe full reload for component script changes.
It does not implement component-script replacement or SDK bootstrap replay.
Fresh exact-head CI for f616fb0 was started after the push and must be assessed separately from local success.
The draft state remains until the relevant CI verdict is known.
Known bundled-mode, release-note disposition, and CodeQL baseline qualifications remain explicit in the prior assessment and PR description.

## Next feature

Component script HMR remains a separate change.
Its useful initial target is replacing a native registration in its existing order and remounting only the affected base-component instances.
A remount may reset that component's local state; it should preserve unrelated routes and external stores.
Do not weaken this safe dependency fallback until the registration replacement and teardown behavior are tested.

## Fresh CI status before handoff

The exact-head Vite 8 build and Administration lint job passed on f616fb0.
The full Jest Admin job was still executing its unit-test step at the last check.
No failed check had been reported on either fetched page.
CodeQL is neutral for the previously documented missing baseline configuration.
The PR therefore remains a draft while the remaining CI runs finish; this report does not claim full CI or merge approval.
