# Shopware Administration Vite and HMR research

Research snapshot: 2026-10-28.
This branch preserves the investigation, prototype, measurements, and blog draft separately from the feature pull requests.
It is an archive for review and further experiments, rather than a supported Shopware tooling package.

Start with the [blog draft](BLOG_POST.md), the [current support matrix](SUPPORT_MATRIX.md), and the [real Shopware measurement method and results](2026-10-28-acceptance-and-benchmarks.md#measurement-method).

## Implementation status

| Change | Draft PR | Relationship |
| --- | --- | --- |
| Twig and native template HMR, including safe script-dependency fallback | [#22113](https://github.com/shopware/shopware/pull/22113) | Independent of the transport change |
| Shared Administration HTTP/WebSocket listener with isolated extension Vite instances | [#22119](https://github.com/shopware/shopware/pull/22119) | Independent of template HMR |
| Native component script HMR and owned override replacement | [#22150](https://github.com/shopware/shopware/pull/22150) | Stacked on #22113 |

These are draft changes, rather than a merged release or a claim that every extension registration can be hot-reloaded.
The first two branches have been combined in both orders during validation.
The third change was tested in a combined lane containing both earlier changes.
Feature code and tests belong in those PRs; this branch contains the research artifacts only.

## Read the evidence in order

1. The [initial investigation](05-vite-dev-server-and-twig-hmr.md) and [decision log](06-vite-shared-server-poc-decision-log.md) explain the alternatives and abandoned assumptions.
2. The [Environment API prototype](admin-vite-environment-poc/README.md) and its dated result folders contain synthetic experiments.
   Their results do not measure the final shared-listener implementation.
3. The [real Shopware acceptance and benchmark report](2026-10-28-acceptance-and-benchmarks.md) records the 77-entry comparison, request counts, resource usage, and limitations.
4. The [App lifecycle report](app-lifecycle/README.md) records an installed signed App, a shared-port Meteor App, and the negative control that reproduced duplicate registration.
5. The [script-HMR feasibility assessment](full-script-hmr/README.md), [template safety follow-up](template-hmr-ready/README.md), and [native script implementation record](component-script-hmr/README.md) show how the safety boundary evolved.

Historical reports intentionally retain the findings and test counts at the time they were written.
The support matrix describes the final scope in this archive; earlier statements that scripts always reload are superseded for the native script slice in #22150.
Historical CI observations are snapshots, rather than live check status.

## Measurements and reproduction

The real comparison's [derived values](live-comparison-summary.json), [baseline process samples](baseline-live-samples.json), [shared process samples](shared-live-samples.json), [baseline browser timings](browser-baseline.json), and [shared browser timings](browser-shared.json) are included.
The [analysis script](analyze-live-comparison.py) reads adjacent files and regenerates the summary and graphs with Python and Matplotlib.
The chart uses median bars and individual-run dots; the report separately gives the highest observed RSS and PSS values.

![Recorded real Shopware comparison](live-comparison.png)

The prototype scripts retain their original repository-relative location assumptions.
To rerun them, copy `admin-vite-environment-poc` to `<shopware-root>/dev-tools/admin-vite-environment-poc` in a disposable development lane and follow its README with an installed Vite 8 toolchain.
Do not run fixture generators against a customer's project.
The historical real-Shopware runner can create and modify local test fixtures; read its arguments and source before use.
The archive is not installed or imported by the Administration.

## Provenance and exclusions

[ARCHIVE_MANIFEST.json](ARCHIVE_MANIFEST.json) records original and archived hashes for the imported public artifacts.
Personal workspace paths were replaced with placeholders, historical banners were added, and moved links were repaired.
The earlier original-checkout research document is preserved separately in [primary-trunk-snapshot](primary-trunk-snapshot/05-vite-dev-server-and-twig-hmr.md).
The later report remains at the archive root.

Before removing the original checkout's research files, all 16 files were copied byte for byte into a local backup with a SHA-256 manifest.
The complete local evidence directory also retains the raw test output and browser recordings.
Raw accessibility recordings and signed iframe URLs, App signing helpers/state, dependency snapshots, machine-specific verification logs, and proprietary Commercial source are excluded from this public branch.
References to excluded evidence in historical reports identify files retained in that local backup.
The published dashboard screenshot contains only generated test fixtures.

The blog, support matrix, archive index, and imported research are isolated on `codex/admin-vite-research-archive` in the author's fork.
No cleanup or archive commit is added to a feature PR.
