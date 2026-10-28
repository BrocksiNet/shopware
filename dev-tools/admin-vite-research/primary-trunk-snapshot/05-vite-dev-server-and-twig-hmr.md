# Vite development servers and Twig HMR

> Earlier original-checkout snapshot; superseded by the current support matrix (retained in the local evidence backup).

This document records the current Administration development-server architecture and a technical direction for improving Twig edit feedback under the `ADMIN_VITE_8` experiment.

It is research, not an architectural decision or an implementation commitment.

The repository state was inspected on 27 October 2026, after PR [#21389](https://github.com/shopware/shopware/pull/21389) merged.

## Summary

Shopware does not currently start one operating-system Vite process per plugin.

`build.ts` starts two long-running child processes in development: the Administration Vite CLI and `build/plugins.vite.ts`.

The latter creates one Vite `DevServer` instance per discovered plugin or app, sequentially, inside its own Node.js process.

The main Administration Vite server proxies `/_internal_ext/<technical-name>/` and its WebSocket traffic to those extension servers.

This still creates a per-extension scaling cost: each server has its own Vite module graph, watcher, optimizer/configuration work, HMR environment, and listener/port.

PR #21389's bundled dev mode reduces browser module requests substantially, according to the PR description, but it still calls `createServer()` for every extension.

Bundling therefore addresses request fan-out; it does not remove the per-extension Vite server instances or their startup and resource costs.

Twig HMR and server consolidation are related developer-experience concerns, but they are separate technical changes.

The first should make a changed Twig source update mounted Admin components without a page reload.

The second should reduce server-instance overhead without breaking extension-specific resolution, Vue sharing, app serving, HMR, or proxy behavior.

Both can be explored under `ADMIN_VITE_8`, but should use separate opt-in switches during the experiment so performance and compatibility results are attributable.

## Current process and server topology

`src/Administration/Resources/app/administration/build.ts` runs `startDevelopmentProcesses()`.

That helper in `build/vite-plugins/development-process-runner.ts` uses `concurrently` to launch:

- The Administration Vite CLI, selected by `getViteCommand()`.
- `ts-node -T build/plugins.vite.ts`, which owns extension development servers.

These are two operating-system child processes of the Administration build runner.

Inside the extension process, `build/plugins.vite.ts` calls `toolchain.vite.createServer()` once for each extension returned by `loadExtensions()`.

These are multiple Vite server instances in one Node process, not one child process per plugin.

For `N` discovered plugins/apps, the current topology is approximately two long-running child processes and `N + 1` Vite server instances, with the Administration being the additional server.

The extension instances are currently created serially by `runForEachExtension()` and each calls `listen()`.

`build.ts` also generates `vite-server-mapping.json` with a port per extension plus one Administration port.

`vite.config.mts` reads that mapping and configures the Administration server to proxy extension paths and WebSockets.

`createPluginDevJson()` emits extension entry and HMR-client URLs under those extension paths.

The extension server has intentionally scoped configuration today.

Each plugin has its own root, cache directory, entry point, Vue external alias, aliases, plugin list, and component setup/override transforms.

Apps use a more limited server configuration and expose their Meteor application HTML rather than an Administration plugin entry.

The current topology can be represented as:

```text
build.ts
├── child: Administration Vite CLI (main server, port P)
└── child: plugins.vite.ts
    ├── Vite DevServer for plugin A (port P+1)
    ├── Vite DevServer for plugin B (port P+2)
    └── Vite DevServer for each remaining plugin/app

main server -- HTTP and WebSocket proxy --> extension servers
```

## What PR #21389 changes and does not change

The PR upgrades the optional toolchain to Vite 8, enables HTTP/2 when HTTPS is provided, and adds Vite 8's experimental bundled dev mode behind `SHOPWARE_ADMIN_BUNDLED_DEV=1`.

The bundled mode changes the delivery form of the Administration and plugin code and changes the extension entry/client URLs written to `sw-plugin-dev.json`.

The PR description reports that with 44 extensions, requests to `/_internal_ext/` per reload fell from about 1,428 to about 136.

The PR also documents that apps remain unbundled and plugin file changes reload the page instead of using HMR in bundled mode.

The implementation in `build/plugins.vite.ts` retains the per-extension `createServer()` loop when bundled mode is enabled.

The port mapping and main-server proxy are also still present.

Therefore, bundled mode is a useful mitigation for browser request fan-out, but does not resolve the concern that work and server resources scale with the number of extensions.

There is a nuance to the claim that the issue will necessarily “come back.”

Bundled mode may permanently improve the request count for the supported Vite 8 configuration, but it does not change the architecture that creates one dev server per extension.

Any pain caused by independent server initialization, watcher overhead, memory, file descriptors, port management, or repeated optimizer work can remain or grow with extension count.

The repo does not currently provide measurements proving which of those costs dominates in practice.

Measure them before setting a target or claiming a single-server design will fix the observed slowdown.

## Local measurement snapshot

A short local measurement was run on 27 October 2026 in the linked Shopware development container, using Node.js 24 and the current three discovered extensions: Storefront, SwagExtensionStore, and SwagAgenticCommerce.

The measurements cover the watcher process tree and listening ports after server readiness.

They do not include a browser navigation, HTTP request count, actual file-edit latency, or repeated-run statistics.

Vite 6.4.5 reported the Administration server ready in 458 ms.

The main server, extension runner, and two esbuild services had approximate resident memory of 1,017 MiB, 130 MiB, 987 MiB, and 23 MiB respectively at the sampled time.

This totals about 2.2 GiB across those processes; `VmRSS` is not a perfect attribution measure and the esbuild services are child processes.

Vite 8.3.3's first unbundled start reported ready in 6,349 ms while it re-optimized dependencies.

At that sample, the Vite process was about 2,016 MiB RSS and the extension runner about 195 MiB RSS.

After the optimizer cache was warm, Vite 8 unbundled reported 456 ms ready; the Vite process was about 1,066 MiB RSS and the extension runner about 183 MiB RSS.

Vite 8 bundled mode, run with the warmed Vite 8 dependencies, reported 415 ms ready.

Its Vite process was about 1,024 MiB RSS and its extension runner about 362 MiB RSS.

The RSS values are single snapshots from separate runs, not a controlled benchmark.

Across all three modes, the process topology and listeners remained constant: one Administration Vite server on port 5173 and one extension-runner process owning three Vite servers on ports 5174–5176.

This confirms that bundled mode does not consolidate per-extension servers.

It also shows no obvious warm-start reason to undertake a broad server rewrite solely to fix cold startup: the Vite 8 warm readiness times were close in this small sample.

The first Vite 8 optimizer run was much slower and had much higher RSS, so fresh-cache startup and repeat startup must be reported separately.

Bundled mode's extension-runner RSS was higher in this sample, which is consistent with it doing additional bundling work; the sample is too small to infer a stable memory delta.

The observation supports keeping the architecture question open, rather than asserting a rewrite is required.

The current data is too limited to decide whether server-instance overhead becomes material at larger extension counts.

The next useful measurement is a repeatable 0/1/5/10/20/44-extension matrix, with cold and warm caches measured separately, plus an actual browser load and edit-to-screen timings.

### Isolated Vite 8 prototype

The isolated prototype and repeatable benchmark live at [dev-tools/admin-vite-environment-poc](../admin-vite-environment-poc/README.md).

The benchmark writes Shopware-shaped `var/plugins.json` and `sw-plugin-dev.json` files and reproduces the plugin-entry and Meteor app discovery paths from `loadExtensions()` and `createPluginDevJson()`.

Its base fixture transforms 64 source files and about 216 KB per plugin, including TypeScript, Twig, Sass, JSON, Vue SFCs, glob imports, and one extension-scoped config transform.

The source volume is calibrated against `SwagAgenticCommerce` (90 files and 276,689 bytes) and `SwagExtensionStore` (123 files and 458,804 bytes) in this checkout.

A doubled profile creates 126 transformable files and about 432 KB per plugin, close to the larger checked-in extension tree.

An optional mixed run adds one Meteor app for every four extensions and follows the app HTML discovery path.

The benchmark compares the current per-extension server pattern, named environments with default dependency discovery, named environments with explicit no-discovery, and one shared client graph.

It samples process-tree RSS, CPU time, file descriptors, inotify watches, threads, startup time, and transform time every 100 ms during server startup and module transforms.

Each base matrix point was run twice on Vite 8.3.3 and Node.js 24.13.0 in a Linux ARM64 container with eight available CPUs.

At 40 plugins with the base profile, the per-extension topology used 41 Vite servers and listeners, sampled at 360 MiB RSS, started in 616 ms, and transformed the fixture in 2,707 ms.

The shared client graph used one Vite server and listener, sampled at 367 MiB RSS, started in 64 ms, and transformed the fixture in 2,964 ms.

The shared graph therefore removes server and listener fan-out and substantially shortens server setup, but this fixture does not show a RAM reduction.

At the same extension count, named environments with default dependency discovery sampled at 527 MiB and took 4,162 ms total.

Adding `optimizeDeps.noDiscovery` to each named environment reduced that result to 378 MiB and 2,521 ms total, while retaining 42 separate environments.

With the doubled source profile, the 40-extension run sampled at 416 MiB for per-extension servers, 692 MiB for named environments with default discovery, 479 MiB for named environments without discovery, and 501 MiB for the shared graph.

The shared graph used fewer servers and listeners but was not the lowest-memory mode in the doubled-source run.

The tests do not load the full Shopware Vite plugin stack, customer extension configs, a browser process, or the running Shopware application.

The per-extension baseline also places the Administration and extension servers in one measured Node process, unlike the real process boundary, so it excludes the extra process overhead of the current topology.

An initial 40-environment run statically imported every extension from the Administration entry and exceeded the safety limit; that fixture incorrectly made each environment scan the combined extension graph, so those measurements were discarded.

The corrected fixture keeps the Administration entry independent and supplies each named environment with its own extension entry, matching the generated extension manifest topology.

A manual Chrome check still confirms that the two-plugin shared-client fixture hot-updates one extension without reloading the page or changing its sibling.

The resulting evidence supports reducing listeners and server setup as valid goals, but it does not yet justify promising lower RAM or compatibility with customer extensions.

Next, run the actual checked-in extension roots with Shopware's real Vite plugin stack and measure browser request count, browser heap, Twig HMR, and edit-to-screen latency.

## Forty-extension scale and one-server feasibility

The standalone Rspack watcher README describes one Rspack MultiCompiler containing an Administration compiler and a separate compiler for each extension.

At 40 Administration extensions, that design therefore means roughly 41 compiler configurations coordinated by one watcher, not one shared compilation graph.

Rspack's native parsing, SWC, and persistent cache may improve build time, but the published README contains no memory results from which to estimate a 40-extension RSS or GiB requirement.

Do not infer the Rspack install's memory from the three-extension Vite sample or promise that Rspack uses less RAM without measuring a representative project.

The Rspack README provides a benchmark command for startup, per-compiler timing, module/chunk counts, and emitted bytes, but describes no RSS reporting.

For a fair memory comparison, measure process-tree peak RSS/PSS and cgroup peak while running both watchers against the same Shopware checkout, extension set, Node version, cache state, and browser scenario.

A single Vite dev server is technically plausible.

Vite's Environment API is designed to let one server share its HTTP server, middleware, resolved configuration, and plugin pipeline across multiple independent environments/module graphs.

However, Vite currently labels this API Release Candidate and recommends ecosystem validation before relying on it.

Those environments are intended to model environments such as browser, Node server, and edge runtime; using one environment per Shopware plugin is a possible adaptation, not a documented turnkey multi-project mode.

Moreover, environments still have independent module graphs, so this design may reduce listeners, repeated server setup, and duplicated global middleware without eliminating per-extension graph or transform state.

A more aggressive design would use one Vite client environment and one module graph for all extension entries.

That can avoid duplicating server and graph infrastructure, but requires Shopware to resolve source files across many extension roots and preserve extension-specific semantics in one shared config.

The current implementation gives each extension a Vite root and automatically resolves its Vite config from that root.

A unified server would need an explicit compatibility strategy for per-extension aliases, plugins, transforms, optimize dependencies, Vue externalization, source-map paths, CSS/Sass, and user config.

Plugin identity must also remain isolated: two extensions can contain modules with the same relative path, registration name, or virtual module id, and update events must never cross between them.

Meteor apps require separate treatment because they expose HTML/apps rather than Administration plugin entries and are currently not bundled.

There is no technical reason that one Vite listener must proxy to other local Vite ports; a consolidated server could route `/_internal_ext/<name>/...` directly through its own middleware and module resolver.

That changes the current URL, HMR-client, and configuration topology and needs integration coverage.

### Suggested prototype boundary

Start with a prototype that runs one Vite server and keeps separate per-extension environments or namespace-aware module graphs.

This tests whether one HTTP/WebSocket listener and shared initialization materially reduce startup and memory while retaining isolation.

If the environment API keeps nearly all of the measured memory, prototype the more aggressive single-client-graph model only for the documented Shopware extension config subset.

Run both with 3, 10, and 40 extensions, including representative plugins with custom aliases/config and at least one app.

Compare fresh-cache and warm-cache startup, median and peak RSS/PSS, cgroup peak, open descriptors, listening ports, request count, HMR correctness, and extension compatibility.

If the unified server only reduces process/listener count while total memory remains near the existing value, do not justify a compatibility-heavy rewrite as a RAM fix.

If one shared graph materially lowers memory and passes the extension compatibility suite, keep the implementation behind `ADMIN_VITE_8` during the experimental phase and separately evaluate its production build/config effects.

## Twig template update path

`build/vite-plugins/twigjs-plugin/index.ts` currently transforms `.twig` and `.html` files into JavaScript string exports.

It does not implement a Vite `hotUpdate` hook or send a template-specific HMR payload.

Changing an imported template can invalidate the module in Vite, but Shopware needs a runtime contract that replaces the registered template and re-renders all mounted component instances that depend on it.

The Administration's Twig component and block runtime lives around `TemplateFactory` and the Vue adapter.

Before implementation, trace registration, inheritance/override ordering, and mounted-instance lookup through `src/core/factory/template.factory` and the Twig/Vue adapter.

The existing `.vue` setup transform in `build/vite-plugins/shopware-setup/index.ts` is a useful local pattern for mapping changed source files to virtual modules and handling transform errors, but it does not solve Twig runtime replacement.

The standalone [Shopware HMR Watcher](https://github.com/shyim/shopware-hmr-watcher) advertises Twig HMR that re-renders affected components while retaining their state.

Its README calls the project experimental and says compatibility with individual Shopware releases is not established.

Use it as a behavioral reference and source of test cases, not as proof that the same runtime contract already exists in Shopware.

## Technical direction

### Phase 0: establish a baseline

Use a representative checkout and a fixed extension set.

Record extension count, startup time until every server is listening, process count, RSS per process, total RSS, open file descriptors, number of listening ports, and browser request count for a cold Admin load and a reload.

Repeat with ordinary Vite 8 and Vite 8 bundled mode.

Record edit-to-screen latency separately for Admin core Twig, plugin Twig, Vue template, and plugin JavaScript edits.

This separates the bundled mode's request-count improvement from any startup, memory, or HMR change.

### Phase 1: Vite 8 Twig HMR on the existing topology

Implement a dev-only Vite 8 plugin hook in the shared Twig transform or a dedicated companion plugin.

Keep production output unchanged and leave Vite 6 behavior unchanged.

Initially watch files that are already part of a Vite module graph.

Map the changed absolute template path to its owning module graph and stable template identity.

On a successful edit, send the updated template (or a versioned module update) through Vite HMR.

Add a narrow runtime API that replaces the template registration and schedules re-rendering for every mounted instance that resolved that identity.

Preserve component state where the existing Vue/Twig architecture permits it.

When dependency/instance mapping is uncertain, use a full reload as a correctness fallback.

On a syntax/transform failure, retain the last successful template and report a source-located error; the next valid edit must recover without restarting the watcher.

Keep this behavior behind `ADMIN_VITE_8` and a development-only opt-in, for example `SHOPWARE_ADMIN_TWIG_HMR=1`, during the first experiment.

Do not make `ADMIN_VITE_8=1` alone silently change template update behavior while compatibility is being established.

### Phase 2: test and evaluate Twig behavior

Unit-test path-to-module mapping, update payloads, malformed edits, recovery, and the no-HMR production/Vite 6 paths.

Add browser coverage for an imported core template, an extension Twig override, inherited templates, and multiple mounted instances of one template.

Verify that state is retained for template-only edits and document cases that intentionally reload.

Compare edit-to-screen latency and reload frequency with the standalone watcher on the same fixture set.

### Phase 3: investigate consolidation independently

Do not describe “one process” as equivalent to “one compiler/server.”

There are at least three possible goals:

1. Reduce operating-system process count while retaining multiple Vite server instances.
2. Share expensive Vite infrastructure while retaining separate extension module graphs/environments.
3. Run all extensions through one Vite server and one module graph.

Goal 1 is unlikely to help much here because the extension servers already live in one Node process.

It may allow in-process coordination, but does not remove per-server graphs, watchers, optimizers, or ports.

Goal 2 is a candidate if Vite 8's environment/server APIs can express isolated extension environments with a shared HTTP listener and watcher.

This requires a prototype and API compatibility review against the exact Vite 8 version in `build/vite-8`.

Goal 3 offers the largest potential reduction in server overhead but has the highest compatibility risk.

Prototype one server with plugin-aware module resolution and per-extension context before committing to it.

The prototype must answer:

- How does a request under `/_internal_ext/<name>/` map to the correct extension root and entry?
- How are extension-specific aliases and plugin config applied without leaking into other extensions?
- How do `preserveSymlinks`, extension-local dependencies, CSS/Sass, `import.meta.glob`, workers, and source maps behave?
- How are Vue imports externalized to the host `window.Shopware.Vue` across all extensions?
- Can plugin code use one HMR WebSocket/client without update collisions between identical module paths or component names?
- Can each extension keep its own dependency optimizer/cache while sharing the listener and watcher?
- How do Meteor apps continue to serve their HTML and assets, given their current limited config and their exclusion from bundled dev mode?
- What happens when one extension has invalid config or a transform failure?
- Can the existing HTTP/2 proxy and `/_internal_ext/` URL contract remain stable?
- How are file additions, removals, and generated bundle manifests handled without restarting all extension servers?

Do not assume that Vite's `createServer()` can be called once with an array of roots or configs.

The Vite 8 API and plugin lifecycle must be checked directly, and the design should use documented APIs rather than private internals if possible.

### Phase 4: benchmark and decide

Compare baseline, bundled mode, Twig HMR, and any consolidation prototype on the same fixture set.

Use startup latency, total memory, open descriptors, watcher count if measurable, port count, browser request count, edit-to-screen latency, HMR correctness, and extension compatibility as decision criteria.

Retain the separate-server architecture if consolidation saves little while imposing broad extension compatibility risk.

Adopt a shared server/environment architecture only if measurements show a meaningful gain and the compatibility suite covers representative extension configurations.

## Rollout and flags

`ADMIN_VITE_8` selects the Vite 8 toolchain and is already an experimental Shopware feature flag.

`SHOPWARE_ADMIN_BUNDLED_DEV=1` independently opts into Vite 8 bundled dev mode.

Twig HMR should initially be a separate development-only opt-in so the team can test runtime behavior independently.

Any server-consolidation prototype should also have a separate opt-in, for example `SHOPWARE_ADMIN_SINGLE_VITE_SERVER=1`.

The exact flag names are proposals, not settled API.

Once behavior and compatibility are established, decide whether to collapse experimental switches into Vite 8 defaults before the 6.8 transition.

Production builds, generated extension bundles, and the Vite 6 fallback should remain isolated from these development experiments.

## Relevant implementation files

- `src/Administration/Resources/app/administration/build.ts` starts the Administration and extension build processes.
- `src/Administration/Resources/app/administration/build/vite-plugins/development-process-runner.ts` starts the two development child processes.
- `src/Administration/Resources/app/administration/build/plugins.vite.ts` creates and listens on one Vite server per extension/app.
- `src/Administration/Resources/app/administration/build/vite-plugins/utils/index.ts` discovers extensions, allocates ports, configures proxy mappings, and writes extension dev metadata.
- `src/Administration/Resources/app/administration/vite.config.mts` configures the main server, including extension proxy routes.
- `src/Administration/Resources/app/administration/build/vite-plugins/twigjs-plugin/index.ts` transforms Twig sources into string modules.
- `src/Administration/Resources/app/administration/build/vite-plugins/shopware-setup/index.ts` demonstrates virtual module and hot-update handling for Vue setup files.
- `src/Administration/Resources/app/administration/build/vite-plugins/vite-toolchain/index.ts` selects Vite 6 or Vite 8 through `ADMIN_VITE_8`.
- `src/Administration/Resources/app/administration/technical-docs/03-extensibility/05-twig-block-system.md` documents the Twig block runtime.

## References

- [PR #21389: Vite 8 upgrade, HTTP/2 watcher, and experimental bundled dev mode](https://github.com/shopware/shopware/pull/21389).
- [Shopware HMR Watcher](https://github.com/shyim/shopware-hmr-watcher).
- [Vite 8 bundled dev mode announcement](https://vite.dev/blog/announcing-vite8-1#experimental-bundled-dev-mode).
- [Vite Environment API](https://vite.dev/guide/api-environment).
- [Vite Environment API for plugins](https://vite.dev/guide/api-environment-plugins.html).
- [Rspack JavaScript API and MultiCompiler](https://rspack.dev/api/javascript-api).
- [Isolated Administration Vite Environment API prototype](../admin-vite-environment-poc/README.md).
