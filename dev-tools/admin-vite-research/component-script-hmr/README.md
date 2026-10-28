# Native component script HMR implementation

> Historical research record from 2026-10-27/28.
> See the archive [README](../README.md) and [support matrix](../SUPPORT_MATRIX.md) for the current scope.

Date: 2026-10-28.
Draft PR: https://github.com/shopware/shopware/pull/22150.
Feature branch: codex/admin-component-script-hmr.
Feature commit: 695fc0e2ced07a5ca8dfa2c5cc613639dd0ace96.
The PR depends on template HMR PR #22113.
The port PR #22119 remains independent.
Only Administration source and tests are committed.
All fixtures, evidence, and these notes remain outside the public commits.

## Contract and decisions

Native SFC script edits restart affected component instances and their descendants.
Local state in that subtree can reset.
Surrounding components and external stores remain alive.
Ordinary template-only edits rerender existing instances.
A template edit that introduces a new private setup binding changes the generated script and needs a component restart.
The implementation uses the generated setup script fingerprint rather than authored script text alone.

Generated native HMR IDs include the normalized absolute source identity.
Vue's root-relative IDs can otherwise collide between extensions with identical override filenames.
An owned callback is replaced in its existing array position.
Mounted instances keep observing the previous array until they unmount, instead of replaying the new callback against already-overridden state.
Vue-owned effects stop on unmount.
This does not undo arbitrary timers, external subscriptions, or global side effects an extension fails to dispose.

The hidden registration mount uses v-once.
Vue's queued parent update alone reuses that vnode.
The module retains a parent-rerender hook in import.meta.hot.data.
A Vue HMR rerender clears that parent's render cache without restarting the parent.

The factory caches copies of native definitions.
Vue's HMR record updates its initial definition and active instances, but does not update an unmounted factory copy.
The module retains the cached definitions and updates them for both script and template edits.
Previously used Vue app contexts retain props, emits, and options caches after unmount.
Those caches are invalidated for the updated definition before it is reused.
This uses the installed Vue runtime's internal cache layout.
The regression exercises a component hidden and reopened inside the same Vue app, including changed prop declarations.

Factory-composed native definitions use a page reload.
The reload policy is set before awaiting lazy legacy overrides.
A native parent is also marked when a legacy child extends it.
This avoids replacing a composed definition with a plain newly imported SFC.
Legacy registration modules, extension bootstrap, SDK App bootstrap, and bundled development retain page reloads.

Override-file creation and deletion rescan discovery, invalidate the generated entry, and then reload.
Reloading without refreshing that entry leaves imports of deleted override files.
Source target and block-ownership changes also reload.
Imported helpers propagate to the native component boundaries, including transitive and shared dependencies.
The original dependency stays in the graph so an unaccepted bootstrap importer can still require a reload.
Invalid helper source is compiled before propagation and leaves the mounted page behind the Vite error overlay.

Startup ordering is still the existing native registration mechanism.
In the real lane, parallel extension loading produced different initial orders between full page boots.
HMR preserves each owner's position within the current boot.
It does not introduce a new plugin-priority mechanism.

## Validation

1,546 related tests passed across 71 suites.
The full Administration TypeScript, ESLint, SCSS, and formatting checks passed.
Touched build plugins also passed scoped ESLint.
The Vite 8 Administration production build passed.
The full extension production build passed earlier in the same lane.
The native cached-definition update hook was absent from the final production JavaScript.

The real dashboard exercised native overrides of the legacy dashboard and a separately registered native base SFC.
A native child computed changed from 10 to 20 while its parent counter remained 2 and both SDK App drafts survived.
The edited child's own draft reset.
A later experiment removed the child through template HMR, edited its computed from 20 to 30 while unmounted, and restored the child.
The reopened child rendered 30.
The host document remained 4a2bf49e-9f06-43e9-8291-9806184c111b and both surrounding App drafts survived.
See cached-child-reopened.png and cached-child-reopened.txt.

A transitive helper changed a rendered computed from 2 to 6 without replacing the host document.
Invalid helper syntax kept the page and drafts mounted behind the overlay.
Repairing it produced the new value 8.
See helper-error.png and helper-error.txt.
These local accessibility recordings include ephemeral signed test-frame URLs and must not be published as raw logs.

Three extensions use the same sw-dashboard-index.override.vue basename.
Editing one contribution retained the other two and their current order.
Deleting and recreating an override caused clean discovery reloads with one SDK tab, card, section, module, and module link per fixture.
See override-deleted.txt.
Twenty sequential replacements with a three-registration chain are covered against the real installed Vue runtime in Jest.
Old watchers stop and only the current watcher fires.

Twig HMR was tested after native script HMR.
The Twig text changed to "Twig HMR and native script HMR coexist." while the native counter, child draft, SDK App drafts, and host document remained unchanged.

## Committed integration smoke test

The combined lane was merged at 9eeb2c939956605d883fd4ca6735905adab4b229.
The native child computed changed from 30 to 40 with the committed code.
Its own draft reset, while the dashboard counter remained 2 and both SDK App drafts survived.
The host remained 7f1ce7d6-2100-47f2-b79a-a47425b5e761.
The SDK iframe document IDs also stayed unchanged.
See committed-smoke.png and committed-smoke.txt.
Both feature worktrees and the integration checkout have clean tracked state.
The demo fixtures remain ignored local files.
GitHub reports the draft mergeable; CI was still running at handoff.

## Local demo files

Open http://localhost:5181/#/sw/dashboard/index.
The dedicated lane is /path/to/projects/shopware-vite-poc.
The committed integration branch is codex/admin-vite-integration.

Edit custom/plugins/VitePocPlugin01/src/Resources/app/administration/src/sw-native-script-probe.vue to change the native child's computed.
Its local draft should reset while the dashboard counter and SDK App drafts survive.
Edit native-script-hmr-helper.ts in the same directory to change the transitive override helper.
That edit restarts the dashboard subtree, including its iframe descendants.
SDK registration counts should remain one even when those descendant iframe documents restart.
Edit sw-dashboard-index.override.vue in VitePocPlugin02 to replace one contribution of the three-extension chain.
Edit VitePocPlugin01's src/views/view-01.twig for the Twig coexistence check.

The new demo files remain ignored local fixtures.
Original main.ts and dashboard override sources are archived here for restoration.
The three-chain dashboard source is saved as three-chain-dashboard.vue.
Do not copy these fixtures or research artifacts into the feature PR.

## Environment caveat

Running the full production build while the watcher was alive regenerated a legacy Vite mapping with a different main port and old extension proxy entries.
This interrupted the active test watcher.
The test lane was repaired to vitePort 5173 with an empty extensions mapping, then restarted.
This is separate from native script HMR and belongs in the port-tooling follow-up assessment.
The final production core build was run directly with the installed Vite 8 CLI to avoid regenerating the live mapping.
Do not claim this run validates concurrent build/watch lifecycle behavior.

## Review gates and limits

Keep the PR draft pending exact-head CI and review.
Release documentation remains pending under the instruction to keep docs out of these feature commits.
The browser acceptance used the combined shared-listener lane.
A separate browser run with the original many-port topology remains a follow-up check.
Windows path normalization is covered by unit tests; a real Windows lane has not been run.
Direct legacy Options API script HMR and SDK App bootstrap HMR are outside this first native slice.
Runtime callback setup failures are tested with native and Options API bases.
Broader throwing computed/method and error-boundary recovery scenarios still deserve a browser matrix.
Commercial was installed in the lane, but this third-PR acceptance edited the native fixture extensions rather than claiming coverage of every Commercial feature.

## Change impact

- Blast radius: Vite 8 unbundled native development sessions and component-registration helpers guarded for development.
  Shared HTTP caches, Store API routes, checkout, remote media storage, and the Symfony container are unchanged.
- Who pays: enabled development sessions perform source classification and affected-definition cache updates.
  No additional Node processes, listeners, or backend queries are introduced.
- Easier: native component logic edits avoid a whole-Administration restart without an author-facing registration API.
- Verdict: ship this scope for draft review.
  CI, release documentation, and the remaining browser matrix are merge gates.
