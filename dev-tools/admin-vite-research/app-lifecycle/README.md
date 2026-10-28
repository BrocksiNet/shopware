# Real App and Meteor SDK lifecycle acceptance

> Historical research record from 2026-10-27/28.
> See the archive [README](../README.md) and [support matrix](../SUPPORT_MATRIX.md) for the current scope.

Date: 2026-10-28.
Test lane: `/path/to/projects/shopware-vite-poc`, branch `codex/admin-vite-integration`.
Both proposed features are enabled together: Twig/native template HMR and the Vite 8 shared HTTP/WebSocket listener.
This report, the diagnostic probes, and the generated extensions are local research and must stay out of both PRs.

## Result

The colleague's concern is valid for registration replay.
An App's iframe reloading and a module hot update are different lifecycle events.
The existing Administration iframe renderer reloads the whole Administration when an already-loaded iframe loads again.
The two feature PRs preserve that guard.
UI-only updates can preserve the App document and draft when their HMR boundary does not reexecute registration.
This is demonstrated by the fixtures' explicitly accepted view module; it is not a promise that arbitrary App bootstraps are safe to replay.

A negative control deliberately accepted the registration module and called its registration function again.
Without an iframe load, the existing reload guard cannot run.
That reproduced duplicate sections, cards, modules, and module links in the actual Administration stores and rendered two cards.
Tabs using the same componentSectionId remained at one because the current store upserts them.
That narrower tab behavior does not make complete SDK registration replay safe.
No production-code fix is necessary for the safe reload behavior observed here.

## Fixtures

| Fixture | What it proves | Location |
| --- | --- | --- |
| ViteHmrSdkProbe | A real installed App with a manifest, type app, registration handshake, active permissions context, and signed iframe URLs | `custom/apps/ViteHmrSdkProbe/Resources/app/meteor-app` |
| VitePocPlugin40 | A plugin-bundled Meteor SDK app served through the new shared Admin listener | `custom/plugins/VitePocPlugin40/src/Resources/app/meteor-app` |
| VitePocPlugin39 observer | Rendered diagnostic snapshot of actual Pinia stores and a document-lifetime marker | `custom/plugins/VitePocPlugin39/src/Resources/app/administration/src/sdk-observer.ts` |
| VitePocPlugin01 | Plugin Twig update while a real App has an unsaved draft | `custom/plugins/VitePocPlugin01/src/Resources/app/administration/src/views/view-01.twig` |

The real App's base URL is `http://localhost:8185/`.
Its registration and confirmation endpoints are container-local HTTP endpoints on 127.0.0.1:8080.
The helper verifies the bootstrap HMAC and confirmation HMAC instead of manually injecting App secrets into the database.
It stores only its local signing state in a mode-0600 file under container `/tmp` and does not persist the supplied integration credentials.
The App was installed and activated with the standard console commands and updated from 1.0.1 to 1.0.2 through app:refresh.
Both live App iframes subsequently used signed URLs carrying app-version 1.0.2.
No DAL entity permissions are requested by this UI-only fixture.

The shared fixture's iframe URL is `http://localhost:5181/_internal_ext/vite-poc-plugin40/meteor-app/index.html`.
Each fixture registers one product tab, one tab section, one dashboard card section, and one main module link.
Distinct component-section IDs and location IDs separate the two fixtures.
Both use Meteor Admin SDK 6.15.0.
The shared fixture also has Vue 3.5.22; the independent fixture initially installed Vue 3.5.13.

## Measurement method

The observer reads the actual tabs, extensionComponentSections, extensionSdkModules, and extensionMainModules stores after view initialization.
It exposes those values as DOM text for browser inspection rather than replacing or mocking the SDK handlers.
A UUID stored in a document meta element identifies the host document independently of observer-module HMR.
Each visible App view also renders its own document UUID and an editable draft field.
Checks compare these markers, draft text, visible headings, store counts, and signed iframe presence.
A second snapshot after boot completion avoids treating temporary zero counts during reload as the settled result.
The product-tab check opens the actual product detail route and renders the signed App iframe inside its SDK tab.

## Observed cases

| Case | Host/App document | Store result | Other evidence |
| --- | --- | --- | --- |
| Independent App view-module HMR | Both preserved | All five counts remain 1 | Updated heading and unsaved draft survive |
| Independent App CSS HMR | Both preserved | All five counts remain 1 | Draft and App UUID survive; screenshot shows the changed heading color |
| Two successive registration-source edits | Host reloads | All five counts return to 1 each time | Latest card and tab labels render |
| App HTML edit | Host reloads | All five counts return to 1 | Fresh App document |
| Deliberately accepted registration replay | Host stays | Tabs 1; cards, tab sections, modules and module links 2 | Old and new cards both render |
| Restore safe bootstrap | Host reloads | All five counts return to 1 | Unsafe handler removed |
| Plugin Twig edit with real App loaded | Host and App stay | All five counts remain 1 | Twig text changes; App draft and UUID survive |
| Product SDK tab after repeated reloads | Existing host remains | One visible App tab and one registered section | App view renders in the signed tab iframe |
| Shared-port Meteor App view-module HMR | Host and both Apps stay | Both fixtures' five counts remain 1 | Both drafts and document UUIDs survive |
| Shared-port Meteor App registration edit | Host reloads | Both fixtures' five counts return to 1 | Latest shared card label renders |
| Manifest update to 1.0.2 followed by App bootstrap reload | Host reloads | Both fixtures' five counts return to 1 | Both real App iframe URLs use version 1.0.2 |
| Final safe fixture state | Settled and rendered | Both fixtures' five counts are 1 | No unsafe registration accept handler remains |

There are 17 recorded browser checkpoints, including initial baselines, the expected negative control, and recovery.
The result verifier asserts every positive boolean and store count rather than only storing screenshots.
The 47 existing SDK/store/iframe tests pass across eight suites, including the initial-load versus subsequent-load App regression.
Fixture TypeScript lint with typescript-eslint recommended rules, fixture App entry type checks, and formatting passed.
The stock Administration ESLint invocation skips files outside its base path, so a separate local fixture lint configuration was used and its skipped invocation is not counted as a successful lint check.
Manifest validation, local YAML lint, fixture CSS stylelint, and Twig/Vue syntax lint passed.
No tracked Shopware source or test files changed during this App acceptance run.

## Relevant Core behavior

- `src/app/component/extension-api/sw-iframe-renderer/index.ts`, onIframeLoad around line 177: first load is allowed; subsequent loads call the page-reload method.
- `src/app/component/extension-api/sw-iframe-renderer/sw-iframe-renderer.spec.js`, around line 379: the existing test explicitly uses a type-app extension and verifies that the second iframe load requests a host reload.
- `src/app/store/tabs.store.ts`, addTabItem around line 25: upsert by componentSectionId within positionId.
- `src/app/store/extension-component-sections.store.ts`: section registration appends entries.
- `src/app/store/main-module.store.ts`: main-module links append entries.
- `src/app/store/extension-sdk-module.store.ts`: stable module definitions are deduplicated, but a changed heading produces a different definition and module ID.

All paths in this section are relative to `src/Administration/Resources/app/administration`.
Those behaviors are present in the tested Core; this acceptance run does not introduce them.

## Decisions

1. Keep the existing full Administration reload after an iframe's second load.
   It is the verified consistency boundary for App bootstrap restarts.
2. Permit view-module and CSS HMR when the App's own HMR boundary keeps SDK registration out of the replayed module.
   The App author still controls that boundary.
3. Do not add an automatic accept handler for arbitrary SDK registration modules to either PR.
   The negative control demonstrates why this would corrupt store state without causing an iframe load.
4. Treat fully state-preserving registration HMR as separate lifecycle work.
   It needs registration ownership, replacement/removal semantics, teardown of listeners/subscriptions, and safe handling of removed registrations and outstanding asynchronous registration calls.
   Adding tab deduplication alone cannot provide that contract.
5. Distinguish independently hosted Apps from plugin-bundled Meteor apps in the port claim.
   The shared listener covers the latter; external manifest base-app-url servers remain independently hosted.
6. Keep all probes, helpers, results, and this decision log outside the PRs.
   Existing production behavior already satisfies the safe path tested here.

## Local test-environment repairs

The lane's configured APP_URL, `http://vite-poc.localhost:8088`, was initially unknown to the running host proxy and unreachable from inside the web container.
Restarting the normal sw-dev host proxy registered the lane.
For backend self-verification, a loopback-only helper forwards container port 8088 to its real backend on port 8000, with vite-poc.localhost resolving to 127.0.0.1 in that container.
The normal app:url:verify command now reports OK; its verification was not disabled.
The ignored local config `config/packages/vite_sdk_probe.yaml` explicitly permits HTTP App traffic and the single loopback IP used by this local fixture.
Global App URL validation remains enabled, and the registration handshake retains its signatures.
These are local fixture accommodations and are not proposed defaults for Shopware.

The independent App devserver is an additional Node process and listener on container 8080/host 8185.
The callback helper adds one loopback listener on container 8088.
These two research processes are outside the shared Admin server and were not part of the earlier RAM or port benchmark.
This run provides lifecycle correctness evidence, not a new performance benchmark or a claim that an independently hosted App needs no server.

## Reproduce

Open `http://localhost:5181/#/sw/dashboard/index` in the authenticated dedicated lane.
The dashboard shows Shared SDK card v5 and SDK App card v4, each with an editable App draft.
The installed fixture product is named SDK lifecycle acceptance product, ID 01a1820d0744746fab8f1ef886a7fe46.
Its App tab is available under the product detail route.

Edit view.ts in either fixture to change its heading without replaying registration.
Enter a draft first and confirm that the heading updates while the draft and document UUID survive.
Edit registrations.ts to change a card label and expect a complete Administration reload, followed by exactly one registration in every category for each fixture.
Do not add an HMR accept handler that calls register again during normal use.
The deliberately unsafe replay has already been removed from both fixtures.

Run the saved-result verifier on the host:

```bash
rtk proxy python3 /path/to/projects/shopware-vite-poc-research-2026-10-28/app-lifecycle/verify-results.py
```

For a lost App devserver process, restore the helper sources from scripts/ into container /tmp and run them through the lane's web service.
The helper uses the existing ignored fixture manifest secret and local /tmp signing state, so do not regenerate that secret for an already registered App.
The standard app:refresh or reinstall workflow is needed if registration state is intentionally reset.

```bash
rtk podman compose exec -T web node /tmp/vite-local-appurl.cjs
rtk podman compose exec -T web node /tmp/shopware-sdk-app-server.mjs
```

Run these from the dedicated worktree root in separate terminals.
The callback helper only needs restarting if container-local APP_URL verification is required.
After recreating the container, restore its local hostname mapping and fixture configuration before rerunning App installation.

## Evidence

- Machine-readable browser checkpoints (retained in the local evidence backup).
- Saved-result assertions (retained in the local evidence backup).
- Existing Jest test results (retained in the local evidence backup) and test log (retained in the local evidence backup).
- UI HMR preserving a draft (retained in the local evidence backup).
- Expected unsafe replay duplicates (retained in the local evidence backup).
- Actual product App tab (retained in the local evidence backup).
- Final safe state with both SDK variants (retained in the local evidence backup).

## Remaining boundaries

This is one deliberately small real App and one plugin-bundled SDK App alongside Commercial and the existing extension workload.
It does not certify arbitrary third-party App code or every SDK registration type.
UI-only HMR relies on an explicit boundary in the App's own source.
Registration replay without a load event remains unsafe on the tested Core and is outside these two feature PRs.
The current full reload can discard unsaved work during a bootstrap change; that is the tested consistency tradeoff.
A broad promise of full HMR for all Apps would therefore be inaccurate.
