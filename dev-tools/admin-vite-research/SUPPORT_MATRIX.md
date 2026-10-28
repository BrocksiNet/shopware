# Administration HMR support and removal behavior

Scope checked on 2026-10-28 against the combined integration commit `9eeb2c939956605d883fd4ca6735905adab4b229`.
The claims below distinguish transport discovery, template updates, component remounts, and extension installation lifecycle.

## Extension types

| Extension kind | Shared listener | HMR scope and evidence |
| --- | --- | --- |
| Plugin Administration entry | Yes, when discovered in `var/plugins.json` | Twig/native templates and the supported native script slice; generated fixtures and selected Commercial Twig behavior tested |
| Symfony bundle Administration entry | Yes, through the same manifest-based entry discovery | Same pipeline as a plugin entry; the real Storefront bundle is included, and Commercial adds feature-bundle entries |
| Local Meteor iframe App entry | Yes, with its own `meteor-app/` path | Its own Vue/Vite HMR and SDK lifecycle boundaries; same-bundle Admin and Meteor entry routing tested |
| Independently hosted Shopware SDK App | Its remote server remains separate | Installed signed App, view/CSS HMR, bootstrap reload, manifest update, and singular registrations tested; this is not Twig HMR inside a remote App |

Discovery reads entry metadata rather than requiring a particular PHP plugin base class.
Calling both an Administration entry and a Meteor entry from one bundle “a plugin” hides an important routing distinction, so they have separate transport identities.
A remote App's server and the Shopware PHP backend are outside the claim of one Administration asset listener.

## Edits, errors, and removals

| Change | Expected behavior | Evidence or boundary |
| --- | --- | --- |
| Supported legacy Twig template edit | Recompile affected template inheritance and rerender affected components | State-preserving plugin and Commercial checks; eager/lazy overrides and resolved descendants covered |
| Ordinary native SFC template edit | Rerender existing instances | Browser counters and surrounding App drafts preserved |
| Native template edit exposing a new private setup binding | Restart affected component subtree if generated script changes | Classification uses generated script, not authored script text alone |
| Supported native SFC script or imported helper edit with #22150 | Replace owned native registration and remount affected subtree | Computed changes, transitive/shared helpers, same-basename extension overrides, repeated replacement, and cached unmounted definitions tested |
| Legacy Options API registration/module/bootstrap script edit | Full Administration reload | Arbitrary side effects and factory-composed definitions are not safely replaceable by this native slice |
| Invalid supported helper source | Vite error overlay; previous page stays mounted until repair | Live syntax-error and repair checks; broad runtime computed/method errors still need a larger browser matrix |
| Remove registration/import from a Twig importer | Build-side template associations are forgotten | Unit regressions cover lazy registration removal and rename without accumulated handlers; this does not prove runtime unregister of an already applied override |
| Delete a Twig file still imported by active code | Normal missing-module/error recovery path | Not a supported hot uninstall or automatic restoration of a base block; repair the importing code and reload/restart as needed |
| Delete a Twig importer | Build-side association cleanup | Unit regression; no general teardown contract for its previous global side effects |
| Add or delete a discovered native `.override.vue` file | Rescan discovery, invalidate generated entry, full page reload | Automated discovery checks and live delete/recreate acceptance; SDK registration counts settle at one |
| Change native block ownership or override target | Full page reload | Structural ownership change is outside an in-place update |
| SDK App bootstrap/registration source or iframe document restart | Preserve existing full Administration reload guard | Installed App and shared Meteor App verified; unsafe registration replay reproduced duplicates in a negative control |
| Install, uninstall, activate, deactivate, or remove an entire plugin/App/bundle | Normal Shopware lifecycle, refresh bundle manifest where applicable, restart watcher | Extension server set is captured at startup; dynamic installed-extension reconciliation is not implemented |
| Bundled development mode | Changes apply through reload | State-preserving template/native-script HMR is not claimed for the current bundled pipeline |

For installed-extension changes, use the normal Shopware lifecycle commands, regenerate `var/plugins.json` with `bin/console bundle:dump` when necessary, and restart the Administration watcher.
Deleting a directory while its bootstrap still imports files is a broken import graph rather than a completed uninstall.
Neither cache-association cleanup nor a file-discovery reload implements runtime removal of all stores, tabs, routes, listeners, and overrides owned by an extension.

## Code and validation references

Paths below are relative to `src/Administration/Resources/app/administration` in the corresponding feature branches.

- `build/vite-plugins/utils/index.ts`: manifest-driven Administration and Meteor entry discovery.
- `build/vite-plugins/extension-dev-server/index.ts`: isolated middleware servers, shared transport, startup and shutdown lifecycle.
- `build/vite-plugins/twig-hmr/index.ts`: importer associations and `watchChange` deletion cleanup.
- `build/vite-plugins/override-component-register/index.ts`: generated native override discovery and reload on structural changes.
- [App lifecycle acceptance](app-lifecycle/README.md): positive checks and unsafe registration replay negative control.
- [Native script HMR implementation record](component-script-hmr/README.md): component ownership, teardown, cached definitions, and browser acceptance.

A fresh focused check for this archive passed 49 tests across five suites covering discovery, shared transport, Twig associations, and override discovery.
The preceding native-script implementation run passed 1,546 related tests across 71 suites, full Administration lint/type checks, and the Vite 8 core production build.
Those are local results, not a substitute for the current PR CI verdict or maintainer review.

## Remaining review and validation work

- Run the native-script browser matrix with the original separate-listener topology as well as the combined shared-listener lane.
- Run a real Windows environment; path normalization unit coverage does not replace it.
- Expand runtime computed/method failures and error-boundary recovery checks.
- Resolve concurrent production-build/watch mapping interference before claiming that workflow is supported by this experiment.
- Validate more real extension configurations and investigate the bundled-mode watcher warning under the large workload.
- Repeat performance measurements with randomized order and explicit cold/warm cohorts before publishing speed or capacity guarantees.
- Finish release documentation, exact-head CI assessment, and maintainer review before merging the drafts.

General hot replacement of legacy component scripts, SDK registration bootstraps, and whole installed extensions would need a separate ownership and teardown design.
It is not a missing checkbox that can safely be enabled by adding an unconditional HMR accept handler.
