# Administration Vite development-server prototype

> Earlier original-checkout snapshot; use the [current prototype record](../admin-vite-environment-poc/README.md) and [support matrix](../SUPPORT_MATRIX.md) for later findings.

This isolated prototype compares Vite server topologies against a Shopware-shaped extension fixture.

It does not change the Administration watcher, enable `ADMIN_VITE_8`, or boot the Shopware runtime.

## Fixture model

The fixture follows the Administration and `custom/plugins/<name>/src/Resources/app/administration/src` layout.

It writes a `var/plugins.json` manifest and discovers plugin entries and Meteor app HTML using the predicates and path rules in `loadExtensions()`.

It writes a `sw-plugin-dev.json` manifest with the `js`, `hmrSrc`, and `html` entry URLs used by the Administration.

The base plugin profile contains 36 TypeScript modules, 14 Twig templates, 6 Sass files, 4 JSON files, and 2 Vue components.

The profile contains 64 transformable source files plus a Vite config file and totals about 216 KB per plugin.

Those generated source sizes are calibrated against two extension source trees in this checkout.

`SwagAgenticCommerce` has 90 source files and 276,689 bytes, while `SwagExtensionStore` has 123 source files and 458,804 bytes.

The `--source-multiplier=2` profile creates 126 transformable source files and about 432 KB per plugin, close to the larger checked-in source tree.

An optional `--app-every=4` setting inserts one Meteor app for every four discovered extensions.

The app fixture uses the app `meteor-app/index.html` discovery rule and a smaller HTML/TypeScript/JSON source profile.

## Compared server topologies

`per-extension` creates one Administration server and one server per extension, matching the current server/listener fan-out.

The prototype keeps those server instances in one measured Node process, while Shopware currently runs the Administration server in a separate process.

`environments-default` creates one Vite server with one named client environment per extension and gives each environment its own extension entry for dependency scanning.

`environments-no-discovery` uses the same environments with `optimizeDeps.noDiscovery` and an empty include list.

`shared-client` uses one Vite client environment and routes namespaced extension modules through its one module graph.

All modes use the installed Vite 8.3.3 package and the Vue compiler configuration used by the Administration extension watcher.

The fixture includes a Twig transform, per-extension config transforms, extension-local aliases, Sass, JSON, Vue SFCs, and glob imports.

The fixture does not load the full Shopware Vite plugin stack or arbitrary customer configuration.

## Measurements

The benchmark records server startup, module transform time, total run time, process-tree RSS, process-tree CPU time, open file descriptors, inotify watches, process count, and thread count.

It samples `/proc` every 100 ms while Vite servers start and fixture modules transform.

Each base matrix point runs twice and the CSV reports the median.

The worker aborts when its process-tree RSS exceeds 4 GiB or it runs longer than 180 seconds.

The results are process-level Vite measurements and exclude a browser, customer extensions, and the booted Shopware application.

The Administration root does not statically import extension entries; the extension manifests and named-environment entries model the runtime discovery path.

## Run

Run these commands from the repository root inside the linked Shopware development container.

```bash
podman compose exec -T web node dev-tools/admin-vite-environment-poc/run-poc.mjs 2 shared-client
podman compose exec -T web node dev-tools/admin-vite-environment-poc/benchmark.mjs --counts=1,5,10,20,40 --modes=per-extension,environments-default,environments-no-discovery,shared-client --runs=2
podman compose exec -T web node dev-tools/admin-vite-environment-poc/benchmark.mjs --counts=40 --modes=per-extension,environments-default,environments-no-discovery,shared-client --source-multiplier=2
podman compose exec -T web node dev-tools/admin-vite-environment-poc/benchmark.mjs --counts=4,20,40 --modes=per-extension,environments-no-discovery,shared-client --app-every=4
```

The first command runs the browser-oriented HMR fixture.

The benchmark command writes `raw.json`, `summary.csv`, `graphs.svg`, and a result README under `results/latest` by default.

Use `--output=dev-tools/admin-vite-environment-poc/results/<name>` to preserve a named result set.

Set `POC_MAX_RSS_MIB` or `POC_WORKER_TIMEOUT_MS` to adjust the worker safety limits.

Use `--summary-only=true --output=<result-directory>` to regenerate the summary and graphs from saved `raw.json` without rerunning the matrix.

## Current results

The base 40-plugin matrix and its plots are saved under [results/2026-10-27](../admin-vite-environment-poc/results/2026-10-27/README.md).

The 40-plugin, doubled-source run is saved under [results/large-extension-profile-2026-10-27](../admin-vite-environment-poc/results/large-extension-profile-2026-10-27/README.md).

The 40-extension app mix with 10 synthetic apps is saved under [results/apps-mix-2026-10-27](../admin-vite-environment-poc/results/apps-mix-2026-10-27/README.md).

![40-extension server topology benchmark charts](../admin-vite-environment-poc/results/2026-10-27/graphs.svg)

At 40 plugins in the base profile, the current per-extension topology creates 41 Vite servers and listeners and samples at about 360 MiB RSS.

The shared client graph creates one server and listener and samples at about 367 MiB RSS, so this run does not show a RAM reduction.

The shared client graph starts in about 64 ms compared with about 616 ms for the per-extension topology, while total startup plus transforms is about 3.1 seconds compared with about 3.3 seconds.

Named environments with default dependency discovery use about 527 MiB RSS and take about 4.2 seconds total in this run.

Named environments with explicit no-discovery use about 378 MiB RSS and take about 2.5 seconds total, but still retain 42 independent environments.

The larger doubled-source run reports about 416 MiB for per-extension servers, 692 MiB for named environments with default discovery, 479 MiB for named environments without discovery, and 501 MiB for the shared client graph.

These results suggest the shared graph removes server and listener fan-out, while memory savings depend on extension graph size and are not yet strong enough to claim as a customer outcome.

## Limits and next work

The generated module mix resembles local Shopware source counts and byte sizes but does not execute the actual plugin source trees.

The per-extension baseline shares one Node process for all Vite instances and therefore does not include the extra process overhead of the real Administration server.

The fixture does not yet model the full asset, override-component, virtual-module, and Vue-global plugin interactions from `build/plugins.vite.ts`.

It also does not prove compatibility for duplicate aliases, plugin names, config hooks, optimized dependencies, symlinks, or unusual customer Vite configs.

The earlier lightweight prototype's Chrome check remains a two-plugin HMR check and does not measure browser heap, request count, Twig HMR, or edit-to-screen latency at 40 plugins.

The next useful step is a controlled run using the real checked-in extension roots and Shopware's actual plugin stack, followed by a browser run that records initial request count, browser heap, and edit-to-screen latency.
