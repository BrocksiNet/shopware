# Script HMR feasibility and current PR readiness

> Historical research record from 2026-10-27/28.
> See the archive [README](../README.md) and [support matrix](../SUPPORT_MATRIX.md) for the current scope.

This is the historical feasibility assessment before the fixes and implementation.
The imported-helper fallback was fixed in PR #22113.
The native implementation and current limits are recorded in [the implementation log](../component-script-hmr/README.md).


Assessment date: 2026-10-28.
The tested checkout is `/path/to/projects/shopware-vite-poc` on `codex/admin-vite-integration`.
This assessment changes no tracked Shopware code, PR metadata, or published branch history.
All experiments and research stay outside the two PRs.

## Recommendation

A third PR is worthwhile for component-scoped script HMR with targeted remounting and a safe full-reload fallback.
It should start with native Shopware SFCs and explicitly exclude arbitrary extension bootstrap replay and App SDK lifecycle replacement.
Legacy Options API support remains valuable, but its registration/cache/super-chain work must be evaluated separately before adding it to the initial third PR.
The useful initial promise is that an edited component can restart without reloading the entire Administration.
Preserving every local ref, input draft, closure, and side effect across arbitrary script changes is a different and substantially harder promise.

The current HMR PR is not yet merge-ready because this research found a live imported-script dependency case that leaves stale rendered state.
The safe fallback for that case belongs in the existing HMR PR rather than being deferred to the third feature PR.
The port PR can proceed independently, subject to its existing audit/baseline and review decisions.

## New evidence: four runtime experiments

The research suite uses the installed Vue HMR runtime and the actual Shopware composition-extension-system implementation.
It does not mock the Vue HMR runtime.
All four tests pass, including the negative controls that assert the currently unsafe behavior.
These are local feasibility experiments, not a completed implementation or end-to-end acceptance of a future third PR.

| Experiment | Observed result | Consequence |
| --- | --- | --- |
| Vue component script replacement | Changing a computed factor from 2 to 3 changes rendered output from 4 to 6 through Vue reload | The Vue runtime provides the required targeted-remount mechanism |
| State during that replacement | Edited child draft resets; parent draft and shared external state survive | A component remount is useful HMR but does not preserve every local draft |
| Re-register native computed override | Base 10, old override +1, then edited override +2 renders 13 with two callbacks | Appending is not replacement; the intended replacement would render 12 |
| Remove an already-applied callback | The registry becomes empty but rendered value remains 11 | Deleting an entry does not reconstruct already-merged instance state |
| Remove a callback that created a watcher | Watcher still runs after registry removal; unmounting the base stops it | Registry cleanup and reactive-effect cleanup are separate concerns |

The first two observations are assertions within one test, hence four tests in total.
Source: research test (retained in the local evidence backup).
Raw evidence: Jest results (retained in the local evidence backup) and Jest log (retained in the local evidence backup).
The tests run in Jest/jsdom; they do not simulate a complete future Shopware HMR integration.

## Newly found live correctness gap

The existing shopware-setup hotUpdate hook checks a changed Vue file's own script fingerprint.
It returns before applying that check to a changed JavaScript or TypeScript dependency.
A native override can therefore change behavior through an imported helper while its own script text stays identical.

The live probe temporarily changed the dashboard's native override to import delta from a TypeScript helper.
Its computed used that imported delta.
With delta equal to 1, the real dashboard rendered Imported computed probe 1.
Editing only the helper to export delta equal to 2 did not reload the host and left the dashboard rendering 1.
A manual full Administration reload then rendered 2 with the same saved sources.
The browser error log contained no error for this stale update.

The rendered host document marker stayed 53ebc3c9-7f25-40f0-9842-2cf54b05cf0c across the helper edit.
The marker changed to d6d0dcc5-9862-4e6a-9683-19d7d6b2827e only after the manual reload.
The helper was confirmed to contain export const delta = 2 inside the container before the reference reload.
This demonstrates missing safe handling of dependency-driven script updates; it is not an assumption based only on the hook's source.
The precise division between missing dependency propagation and stale setup/block state still needs a focused regression to establish the smallest correct fix.

Evidence: browser observations (retained in the local evidence backup), stale dashboard screenshot (retained in the local evidence backup), probe override (retained in the local evidence backup), and helper source (retained in the local evidence backup).
The original native override was restored byte for byte, and the temporary helper was removed from the test lane.
The final browser shows the original Native SFC state counter and singular registrations for both SDK fixtures.
Recovery evidence: recovery snapshot (retained in the local evidence backup) and restored dashboard (retained in the local evidence backup).

## Immediate work for the existing HMR PR

1. Add a regression that changes an imported helper or composable of a mounted native override.
   Include a transitive dependency and a dependency shared by more than one component.
2. Route a dependency-driven script update to a safe full Administration reload until component registration replacement is supported.
   Cover both a graph-based server fallback and any client-side Vue update-boundary classification used by the implementation.
3. Keep genuine template-only rerenders and style-only updates on their current state-preserving path.
   Vue's internal rerender-only update marker is a candidate signal to investigate, not a currently implemented or guaranteed public contract.
4. Test invalid helper source, repair, deletion, and repeated helper edits without reload loops or duplicate overrides.
5. Re-run the affected setup/HMR suites and the real dashboard probe with both independent PR combinations.

Do not remove the safe reload guard simply to avoid the new failing scenario.
Do not include this research corpus or its browser screenshots in the feature PR.

## Why native Vue helps but is not sufficient

The Vite 8 toolchain pins Vite 8.3.3 and @vitejs/plugin-vue 6.0.9.
The Administration runtime uses Vue 3.5.22.
Vue's runtime exposes separate rerender and reload operations.
Rerender replaces render behavior on existing instances, while reload marks definitions dirty and recreates affected instances, invalidating Vue's props, emits, and options caches.
Shopware adds its own registries, lazy component cache, override chaining, private override state, and cross-component block slots around that mechanism.
Vue cannot replace or remove those additional registrations on Shopware's behalf.

The native setup registry is an array of callbacks per component.
The runtime records which callback identities have already been applied to each instance.
A newly evaluated function has a new identity and is treated as another override, not as a new version of the previous override.
Existing effects belong to the base component's scope and are stopped when that base unmounts, rather than when a registration is removed.
The native block component already removes its stable slot wrapper on unmount, which is useful existing teardown behavior to reuse.
The native extension-target registry also needs ownership-aware handling if changed or removed block targets are to be replaced during a live session.

Legacy components add separate constraints.
Component.register rejects a second registration of the same name.
Component.override appends ordered entries with cached resolver closures.
VueAdapter caches resolved component definitions, and Component.extend and the $super chain depend on the built configuration.
The current Twig bridge intentionally replaces a template and rerenders affected components; it does not replace arbitrary script definitions.

## Proposed third-PR scope

The following describes a proposed design, not APIs that already exist.

### Stable ownership

Assign a development-only identity to each generated native component or override registration.
Derive it from normalized source-module identity, target component, registration kind, and an ordinal when one module registers more than one item.
Ignore HMR query timestamps while retaining the extension root so different plugins cannot collide.
Replace an owner's registration in its existing ordered position instead of deleting it and appending a new one.
Track native block targets under the same owner so deletion and rename can remove obsolete targets.
Preserve ordinary non-HMR registration semantics and compatibility with previously built extensions.
The compiler should inject the metadata without requiring extension authors to add a new ritual.

### Controlled replacement

Compile and validate the new module before committing its replacement to the live registry.
Keep the old rendered version on compile failure and show the existing error overlay.
Use a generation token for pending async loads so an older completion cannot overwrite a newer replacement.
On a runtime failure that cannot be rolled back safely, request the existing full reload rather than leaving a partially replayed registry.
Do not promise atomic rollback of arbitrary external side effects.

### Rebuild and remount

For the initial implementation, rebuild the affected setup chain from its base and current ordered registrations.
Remount the affected base-component instances and descendants through the installed Vue HMR runtime.
A hidden override component remount alone is insufficient because already-mounted base instances hold merged state and effects.
Use the existing unmount cleanup for block wrappers and component-owned effects.
Do not reset unrelated Pinia stores, the router, or other SDK Apps to implement component HMR.
The remounted component's own state and descendant state may reset, and this must be stated in the feature's contract.

Replaying an override into an existing instance without remounting is a later optimization.
It requires override-specific effect scopes, restoration of overridden bindings, and replay of later overrides against the newly produced previous state.
Manually created timers, DOM listeners, and external subscriptions still need explicit disposal; stopping a Vue scope cannot undo arbitrary global effects.

### Boundaries and fallback

Enable the third path only for the existing Vite 8 development activation boundary and initially for the ordinary, non-bundled development mode.
Leave extension entry modules, router/module/store registration, global side effects, and SDK App bootstrap reloads on the safe full-reload path.
Account for imported helper/composable changes as well as inline script changes.
Keep production output and disabled-feature behavior unchanged.
Do not create additional Vite listeners or Node subprocesses for script HMR.
The port and WebSocket isolation established by the second PR remains the transport boundary.

## Acceptance gates for a third PR

- Edit a computed or method and see its new result without changing the host document marker.
- Verify that the edited instance is recreated while a sibling component's draft and external store state survive.
- Use two simultaneous instances, a native override chain with at least three registrations, and useSwPreviousState calls.
- Change, remove, rename, and reorder an override without accumulating callbacks or stale block targets.
- Exercise an Options API base with a native override and a native base with an Options API shim.
- Repeat at least twenty edits and count registrations and effects after every update.
- Verify that watchers and subscriptions tied to old instances stop and do not fire twice.
- Cover lazy components, pending imports, helper dependencies, syntax errors, repair, and runtime callback failure.
- Verify fallback for bootstrap/global registration changes and retain the existing SDK iframe reload guard.
- Run with the third change plus each existing feature combination and with the Vite 8 flag disabled.

## Cost and value assessment

| Work package | Value | Complexity/risk | Recommendation |
| --- | --- | --- | --- |
| Imported-script safe fallback | Correctness of current native development behavior | Small to medium; exact update boundary still needs regression | Required before treating the HMR PR as merge-ready |
| Native component script HMR with remount | Avoid whole-Admin boot for supported native component logic edits | Medium to high; Vue provides a foundation, Shopware lifecycle must be added | Worth a separate third PR after the current fixes |
| Legacy computed/method HMR | High immediate value for existing extensions | High; factory cache, inherited components, $super, and mixed native shims | Separate feasibility gate; do not promise it as a trivial addition |
| Preserve all local script state | Convenient for drafts inside the edited component | Very high and partly ambiguous when code/state shape changes | No universal promise; consider explicit state ownership later |
| SDK App registration HMR without Admin reload | Avoid global restart for App bootstrap work | High, spans SDK/core lifecycle and iframe sessions | Separate architectural work, not initial third-PR scope |

This is a qualitative engineering estimate, not a measured implementation schedule.
The new native API is explicitly experimental and optional in discussion 21162, so the native-only third PR should not be sold as improving every existing extension's computed edit immediately.
A stronger universal claim would require the legacy and SDK work as well.

## Current PR readiness

The two PRs are still drafts and GitHub reports both mergeable.
No PR state was changed as part of this assessment.
The current remote heads match the locally reviewed feature branches.

| PR | Remote head | Checks at this assessment | Verdict |
| --- | --- | --- | --- |
| 22113 Twig/native template HMR | 979a72aa1781e94b4eb165c01118428e85a9bd76 | 124 check runs: 93 successful, 30 skipped, 1 neutral CodeQL | Ready for continued review; not merge-ready until the imported-helper gap has a safe fallback and regression |
| 22119 shared port | 07e1110ebd241161cbe5032b82c30604c75eb82b | 122 check runs: 86 successful, 34 skipped, 1 neutral CodeQL, 1 failed dependency audit | Functionally high confidence for tested scope; merge decision still needs audit/baseline resolution and maintainer review |

The skipped checks are not counted as tests that ran.
Both heads have successful Jest Admin and lint check runs.
The HMR Codecov patch check succeeds at 88.88 percent against its configured 71.96 percent target; the bot comment still identifies uncovered lines.
The port Codecov summary does not measure the full build-tool test surface and should not be read as proof of complete coverage.

The port audit failure reports the same deepmerge and qs advisories reproduced earlier with untouched trunk package files.
The audited dependency declarations added by the port PR are not the source of those advisories.
The audit is nevertheless currently failed, not green or waived by this assessment.
Both CodeQL checks are neutral because a JavaScript nightly baseline configuration is missing, so a complete introduced-alert comparison is unavailable.
A direct query for open alerts on each current PR merge ref returned no entries, which does not repair that missing comparison.
Only bot review/comment entries were present in the fetched discussion history; the successful codeowners status alone is not a substantive independent runtime review.

The previous 948 combined focused regression tests, production build, real Commercial acceptance, and 17 SDK App browser checkpoints remain useful evidence.
They do not cover away the new live imported-helper finding.
The PR diffs contain source, dependency declarations, and tests; no benchmark or research corpus is present in either diff.
Both PR descriptions currently have unchecked release/documentation items and bots warn about missing release information.
Under the existing instruction not to commit research/docs, this assessment does not add release files; the release-note disposition still needs an explicit project decision before handoff as merge-ready.
Windows and arbitrary third-party configurations remain unverified, as does general state-preserving template HMR in experimental bundled development.

## Change impact

- Blast radius: Administration development sessions under the existing Vite 8 boundary; production runtime guards and shared factory changes still require their normal tests.
- Who pays: enabled developer sessions; no new Store API, checkout, shared HTTP-cache variant, remote-media behavior, or Symfony container split is proposed.
- Easier or more complex: the port PR removes port management, the template PR removes supported template reloads, and the proposed third PR would remove whole-page script reloads for its supported component scope without adding an author-facing setup step.
- Verdict: proceed independently with the port review, harden the existing HMR fallback, and narrow the third PR to component-scoped replacement/remounting.

## Sources

- [Vue 3.5.22 HMR runtime](https://github.com/vuejs/core/blob/v3.5.22/packages/runtime-core/src/hmr.ts).
- [Installed-version Vue plugin hot update implementation](https://github.com/vitejs/vite-plugin-vue/blob/plugin-vue%406.0.9/packages/plugin-vue/src/handleHotUpdate.ts).
- [Vite HMR API: accept, dispose, prune, data, and invalidate](https://vite.dev/guide/api-hmr).
- [Shopware native SFC and Composition API discussion](https://github.com/shopware/shopware/discussions/21162).
- [Current HMR PR](https://github.com/shopware/shopware/pull/22113) and [current port PR](https://github.com/shopware/shopware/pull/22119).
- Exact-head CI and file snapshot (retained in the local evidence backup).
- [Earlier default/bundled acceptance and benchmarks](../2026-10-28-acceptance-and-benchmarks.md).
- [Real SDK App acceptance](../app-lifecycle/README.md).

Local source inspection included shopware-setup/index.ts, shopware-setup-hmr/index.ts, composition-extension-system/index.ts, native-extension-targets.ts, async-component.factory.ts, vue.adapter.ts, use-block-context.ts, and sw-block/index.ts.
These paths are under `src/Administration/Resources/app/administration`, with the setup plugins under build/vite-plugins.

## Re-run the runtime experiments

Copy the saved research spec into the ignored container folder `/var/www/html/custom/HmrResearch`.
From the dedicated lane root, use the existing Shopware Jest harness with an explicit research match and the Administration dependency path.
The first two attempted runner configurations found no tests or could not resolve Vue outside the Admin root; those attempts are not counted as successful experiments.
The command below is the successfully verified configuration.

```bash
rtk podman compose exec -T web bash -lc 'cd src/Administration/Resources/app/administration && npm run unit -- --runInBand --coverage=false --roots /var/www/html/src/Administration/Resources/app/administration /var/www/html/custom/HmrResearch --modulePaths /var/www/html/src/Administration/Resources/app/administration/node_modules --testMatch "**/*.research.spec.ts" --runTestsByPath /var/www/html/custom/HmrResearch/native-script-hmr.research.spec.ts'
```

The research spec also passes Prettier and the local typescript-eslint fixture configuration.
No implementation changes were committed or pushed.
