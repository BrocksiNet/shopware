# Vite shared-server prototype decision log

> Historical research record from 2026-10-27/28.
> See the archive [README](README.md) and [support matrix](SUPPORT_MATRIX.md) for the current scope.

This log records decisions and evidence from the isolated Shopware Vite 8 experiment.

It is intended for review and challenge before any production architecture change.

The experiment ran on 27 October 2026 from the clean `origin/trunk` revision `3f96d5c386b4575838592dc105b89d2e40c10926`.

The work is on branch `codex/vite-shared-server-poc` in the dedicated local Shopware test checkout.

## Decision 1: measure the real watcher topology before proposing consolidation

Shopware starts two long-running Vite worker processes in development: one for the Administration and one for extension development servers.

The extension worker calls `createServer()` once per discovered extension or app and listens on a distinct port for each server.

With the Administration server included, 40 active fixture plugins produced 42 listening Vite ports.

The scaling problem under investigation is per-server configuration, graph, watcher, optimizer, listener, and port overhead within the extension worker.

Calling this “one subprocess per plugin” is inaccurate and would lead to targeting the wrong cost.

The measured process tree had a median of four processes: npm, the build orchestrator, and the two Vite workers.

The implementation path is `build.ts` → `build/vite-plugins/development-process-runner.ts` → `build/plugins.vite.ts`.

## Decision 2: establish a real Shopware baseline with 40 active extensions

The isolated `sw-dev` lane is registered as `vite-poc` and uses the project `shopware-vite-poc`.

The lane's PHP app is exposed on host port 8110 and the main Vite server on host port 5181.

The browser's development entry point is the Vite root at `http://localhost:5181/`.

Opening `/admin` on the PHP app returned an HTTP 200 shell without Administration module script tags because this fresh lane has no built Administration entrypoint installed.

That HTTP response was not proof that the Admin rendered, and the earlier report overstated what the HTTP check established.

The first browser attempt exposed that the Vite process inherited `APP_URL=http://vite-poc.localhost:8088`, which was not reachable from inside its container.

I restarted Vite with `APP_URL=http://127.0.0.1:8000`, the container-local PHP address, and confirmed the Vite proxy returned the backend's expected HTTP 401 for an unauthenticated API config request.

The browser then rendered the translated Shopware login form and the authenticated Administration dashboard.

The dashboard showed the main navigation, storefront sales channel, dashboard widgets, and logged-in `admin` user.

The browser details and route correction are captured in [browser validation](admin-vite-environment-poc/results/shopware-vite8-40-extensions-2026-10-27/browser-validation.md).

The lane enables `ADMIN_VITE_8` and writes the JavaScript feature manifest with `bin/console feature:dump`.

The 40 generated fixtures use Shopware plugin metadata and lifecycle discovery, then enter the normal extension dump and Vite watcher configuration.

Each fixture has 36 TypeScript modules, 14 Twig templates, 6 Sass files, 4 JSON files, and 2 Vue components, totaling roughly 216 KB across 102 files.

The source profile was calibrated against two checked-in Shopware extensions by file count and byte size.

The fixtures are representative of extension discovery and transform workload, but their source and per-plugin configuration are generated rather than customer-authored.

## Baseline result

The full result, raw samples, CSV time series, six resource graphs, and logs are in [the 40-extension Shopware Vite 8 run](admin-vite-environment-poc/results/shopware-vite8-40-extensions-2026-10-27/README.md).

The final benchmark ran with the container-local API proxy target and started the main server in 885 ms and the extension servers in 2,070 ms.

The final 20-second sample measured 1,858.9 MiB median and 1,989.6 MiB peak container RSS.

Earlier resource runs used an API proxy target that the container could not reach, so they are excluded from this baseline.

The npm development process tree measured 1,006.7 MiB median and 1,161.0 MiB peak RSS.

The container CPU median was 20.4 percent and the peak was 64.3 percent.

The Vite process tree used a median of 247 open file descriptors, 72 threads, and 11,853 inotify watches.

All 41 extension graphs returned 2,730 modules successfully during verification.

The verification peak container RSS while requesting all graphs was 2,113.4 MiB, below the runner's 4 GiB safety cutoff.

The lane's resolved Composer dependency snapshot is saved as composer.lock (retained in the local evidence backup).

Its SHA-256 is `578fd7e7c977d2f79831ca8a55cafbf1c4c85bcb3b705e3bbef30a894e58eb26`.

In the standalone 40-plugin synthetic matrix, 41 per-extension Vite servers used about 360 MiB RSS, while one shared client graph used about 367 MiB.

The shared graph therefore reduced the server count in that fixture but did not reduce measured memory.

With doubled source sizes, the per-extension topology used about 416 MiB and the shared graph about 501 MiB.

Named Vite environments used about 527 MiB with default dependency discovery and about 378 MiB with discovery disabled, while retaining 42 environments.

The synthetic standalone benchmark's RSS was less than half the real Shopware Vite process-tree RSS at the 40-plugin baseline.

Therefore, the synthetic benchmark is useful for comparing graph and environment designs but does not predict Shopware's absolute memory requirement.

## Twig HMR finding

The verifier edits one fixture Twig template while monitoring HMR messages for the changed plugin and a control plugin.

The changed plugin received its template update and the control plugin received no update.

The first four generated plugins now call `Shopware.Component.override()` for real dashboard and detail components with imported Twig block templates.

The authenticated browser visibly renders the dashboard and manufacturer fixtures.

Editing the dashboard Twig file now changes the mounted component's rendered text without a manual refresh.

This validates update delivery, plugin isolation, real override rendering, and the first live component HMR path.

The prior investigation and proposed Twig work are summarized in [Vite development servers and Twig HMR](05-vite-dev-server-and-twig-hmr.md).

## Initial implementation spike: completed for dashboard fixture

The first update path was implemented on the existing per-extension Vite topology to keep HMR behavior separate from server consolidation.

The dashboard browser acceptance case now passes, and focused unit coverage checks stable override replacement and the Vue adapter runtime bridge.

The dashboard update and manufacturer unsaved-state checks were both completed manually in the authenticated browser on 27 October 2026.

These checks cover one dashboard component and one stateful detail form, but they do not replace broader Twig compatibility testing.

## Current decision: no adoption case yet; continue only as a gated prototype

## Twig HMR runtime spike: dashboard path now works

The first runtime spike is implemented on the `ADMIN_VITE_8` development path.

`TemplateFactory` can replace an existing override by its stable resolver identity and clear its normalized-template cache.

`AsyncComponentFactory` re-indexes Twig blocks after replacement so changed block contents do not accumulate in the registry.

`VueAdapter` exposes a development-only update method that rebuilds the merged Twig template and rerenders the existing Vue component through Vue 3.5's internal HMR runtime.

Generated fixture plugins accept updates to their own Twig module and pass the changed template to the adapter.

The Vite 8 development plugin now injects the Vite accept callback for supported static Twig imports in ordinary extension modules.

On 27 October 2026, the authenticated dashboard visibly changed from “Dashboard Twig extension: edit this text to test HMR.” to “Dashboard Twig extension: HMR update reached the dashboard.” while remaining on the dashboard route.

The same run observed an HMR update for plugin 1 and no update event for plugin 2.

Focused Jest coverage for stable override replacement and the Vue adapter bridge passed before the browser run.

This proves a mounted dashboard component can receive a Twig update without a manual page refresh.

It does not yet prove preservation of unsaved component state, correctness for every Twig inheritance pattern, or compatibility with arbitrary extension entry setups.

The Vue bridge uses the internal `__VUE_HMR_RUNTIME__` interface and must stay isolated behind the Vite 8 development gate with a reload fallback.

The manufacturer state-preservation case also passed manually in the authenticated browser.

While the manufacturer Name field contained the unsaved value `HMR unsaved-state probe`, plugin 2's Twig note updated in place and the field value remained unchanged.

Restoring the source restored the original Twig note while retaining the unsaved field value.

The form was not saved, so the fixture check did not alter backend data.

## Commercial source-pattern compatibility check

The user supplied a local `SwagCommercial` checkout as a real extension source for compatibility testing.

Its `custom/plugins/SwagCommercial` path points to the supplied source checkout.

The ContentGenerator entry registers `sw-cms-el-text` with `Shopware.Component.override('sw-cms-el-text', () => import('./module/sw-cms/elements/text/component'))`.

The imported `component/index.ts` statically imports `sw-cms-el-text.html.twig` and exports `Component.wrapComponentConfig({ template, ... })`.

The HMR transform regression test now uses this registration, component, and Twig structure.

The generated POC dashboard fixture uses the same lazy-registration and wrapped-component structure, and the authenticated Admin browser showed its edited Twig text without a page refresh.

The companion fixture plugin received no update event during that check.

The complete Commercial package was mounted into the dedicated Shopware environment using a one-way Mutagen replica from the host checkout.

Its Admin dependencies were installed inside the container, and the plugin was activated after temporarily adapting the SSO configuration value to the test lane's schema.

Editing the host Commercial Twig file changed the mounted CMS text element without a page reload, and restoring the file removed the change live.

The browser console confirmed the injected callback and Shopware update bridge both reported success.

The verifier must not edit the container copy for this one-way session because Mutagen restores the host version; edit the host source and flush the session instead.

## Native SFC and mixed-mode HMR check

The user supplied [Discussion #21162](https://github.com/shopware/shopware/discussions/21162), which describes `<script setup>` SFCs and Composition API as an experimental extension-authoring alternative.

The discussion says Twig and SFC extensions can extend components implemented in the other mode, making mixed-mode compatibility a migration requirement rather than an optional future concern.

The prototype now includes a dashboard `sw-block` override authored as a Vue SFC with a local counter.

The generated SFC is registered through the Administration's normal `*.override.vue` discovery path and Shopware's setup-SFC transform.

The validation changed the file inside the `vite-poc` container while the authenticated browser was open on the dashboard.

The plugin 1 WebSocket received a file-change event and a `js-update` for the virtual `*.vue.shopware-setup.vue` module, while plugin 2 received no update.

The Vite HTTP response served the changed module, and the browser's Vite client logged `hot updated` for the virtual module.

The first browser run confirmed that Vite delivered the update but the rendered block did not change.

The virtual setup-SFC module's Vue HMR callback selected `reload()` for a template-only edit because Shopware's wrapper did not set Vue's `_rerender_only` marker.

A development-only post-transform plugin now adds a second HMR accept callback for virtual `*.vue.shopware-setup.vue` modules and calls Vue's HMR `rerender()` with the updated render function after Shopware's existing callback.

The authenticated browser now shows the edited SFC text immediately while the fixture's local counter remains at `1`, demonstrating that the mounted component state survives a template-only edit.

The test was repeated after removing an unrelated `markRaw` experiment, confirming that the result depends on the Vite HMR adapter alone.

The fixture's initial Twig smoke test exposed a separate false-negative: the same template was also included by a broad eager glob, so Vite propagated the change to an unaccepted importer and selected a full reload.

The fixture now excludes its directly overridden Twig file from that eager glob, while retaining the other Twig modules in its graph.

After that correction, the authenticated browser applied both a Twig edit and a native setup-SFC template edit in place, and the SFC counter remained at `2`.

Do not route native `.vue` edits through the Twig `TemplateFactory` bridge.

Keep Twig template HMR and Vue SFC HMR as separate compiler/runtime paths, then validate both through the mixed compatibility shims described in the discussion and [native block ADR](https://github.com/shopware/shopware/blob/trunk/adr/2024-09-26-native-block-system.md).

This fixture validates template-only updates for a Shopware setup-SFC native block override.

Script, style, ordinary non-setup SFC, delete/rename, error-recovery, and broader mixed Twig/SFC compatibility cases remain open before claiming complete HMR support.

## Native SFC script-update limitation

On 28 October 2026, a `<script setup>` constant edit emitted a file-change event and a Vue `js-update` for the virtual setup-SFC module.

The browser logged `hot updated`, but the mounted dashboard continued showing the old script value during the observation window.

The result reproduced after a clean page load with the local counter set to `1`.

Temporarily removing `v-once` from the hidden override-component mount did not make the new script value render.

This distinguishes the verified template-only HMR path from script HMR, which currently delivers an event without applying the changed setup value.

Trace the Shopware setup-SFC HMR registration and Vue component reload lifecycle before advertising script HMR support.

The evidence does not justify claiming that one Vite server or Environment API will reduce Shopware's memory use.

The synthetic graph tests show a one-server topology can remove listener fan-out without reducing RSS, and larger source graphs can make its RSS higher.

The Shopware baseline now proves that the 40-plugin development Admin renders, but it does not compare a shared-server implementation or quantify a user-visible gain.

The current results therefore do not make a case to ship or enable a shared-server change by default.

Continue only with a small shared-server prototype behind the existing `ADMIN_VITE_8` gate and a separate development-only opt-in.

Keep the prototype isolated until it passes graph, Twig and native SFC HMR, browser, and representative extension-compatibility checks against this baseline.

Vite describes the Environment API as Release Candidate, says some APIs remain experimental, and currently advises users against switching to it.

The [Vite Environment API guidance](https://vite.dev/guide/api-environment) does describe one dev server with shared HTTP server, middleware, resolved config, and plugin pipeline alongside independent environments.

That API is a promising experiment surface, but it does not by itself guarantee lower memory or compatibility with Shopware's current plugin config model.

## Comparison required before making an adoption decision

The next prototype should test one shared Vite server and one listener while preserving extension-aware module resolution and Twig HMR behavior.

Keep this experiment behind the existing `ADMIN_VITE_8` gate and add a separate development-only opt-in for shared-server behavior.

Use Vite 8's current per-extension servers as the primary control so the server topology is the only intended variable.

Keep the Vite 6 path and `SHOPWARE_ADMIN_BUNDLED_DEV` as additional controls, and do not call bundled dev server consolidation because it still creates one Vite server per extension.

Compare all modes on the same 40 fixtures, same authenticated Admin route, same browser version, viewport, backend data, and cache state.

Run at least five cold and five warm navigations per mode and interleave modes to reduce time-of-run bias.

Record requests and bytes by route, especially `/_internal_ext/`, document TTFB, document response time, navigation load time, FCP, LCP, TBT, Speed Index, and Lighthouse performance score.

Also record browser CPU and heap, server and container RSS/CPU, startup time, full-graph transform time, listeners, file descriptors, threads, and inotify watches.

Measure Twig edit-to-visible-update latency on a mounted fixture component and verify plugin isolation, state retention, and the full-reload fallback when update mapping is ambiguous.

Only recommend adoption if the shared server produces a repeatable user-visible or resource improvement without regressions across representative extension configurations.

## Risks and limits

The first four generated plugins now use actual `Shopware.Component.override()` calls and imported Twig blocks on the dashboard, manufacturer detail, product detail, and order detail screens.

The dashboard and manufacturer overrides have been confirmed in the authenticated browser.

The remaining 36 generated plugins retain the module-rich profile used for the 40-extension resource baseline.

The generated extensions do not represent every customer plugin's aliases, Vite hooks, symlinks, dependencies, or override patterns.

The experiment has not benchmarked a full customer installation, the browser heap, or edit-to-visible-screen latency.

The authenticated browser smoke check proves that the Vite-served Admin can render against the local backend and that the dashboard and manufacturer Twig overrides appear.

The runtime bridge now replaces the mounted dashboard component's render function after a Twig update, without requiring a manual refresh.

The dashboard and manufacturer state-preservation cases pass, while broader template-compatibility coverage remains open.

The bridge uses Vue's internal development HMR runtime, so Vite 8 version changes require validation and unsupported cases must fall back to a page reload.

The current spike proves the Shopware template replacement and mounted-component update path, but it is not yet a drop-in HMR experience for arbitrary extension imports.

The Lighthouse baseline config is saved at [lighthouserc.shopware-vite8.cjs](admin-vite-environment-poc/lighthouserc.shopware-vite8.cjs).

The run could not start because the container's bundled Puppeteer Chrome invoked `qemu-x86_64-static` and failed to find `/lib64/ld-linux-x86-64.so.2`.

No Lighthouse score, request count, TTFB, or browser resource metric is reported from that failed attempt.

The fixture source trees live under ignored `custom/plugins` paths and must be recreated with the included generator after a fresh checkout.

The isolated lane resolved Composer constraints during setup because the Shopware checkout did not contain a tracked `composer.lock`.

Any prototype using it must pin and validate the exact Vite version and be treated as disposable research until the API guidance changes.

Results come from one machine and container and should not be generalized into a customer RAM guarantee.

## Reproduction notes

Use the dedicated lane and avoid global `sw-dev sync all`, which can affect unrelated stopped lanes on this host.

Generate fixtures with `node dev-tools/admin-vite-environment-poc/generate-shopware-fixture.mjs --count=40`.

Refresh and install the generated plugins, enable `ADMIN_VITE_8`, run `bin/console feature:dump`, and run `bin/console bundle:dump` inside the lane.

Run the baseline using `node dev-tools/admin-vite-environment-poc/run-shopware-vite8-benchmark.mjs` from the Shopware repository root.

Run an independent graph and Twig HMR check with `node dev-tools/admin-vite-environment-poc/verify-shopware-fixture.mjs --all-graphs --hmr`.

The generator refuses to overwrite plugin directories without its marker and supports removing only its own fixture directories with `--clean=true`.
