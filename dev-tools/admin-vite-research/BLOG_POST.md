# One port, fewer lost edits: improving Shopware Administration development with Vite

Editing an Administration template should not mean navigating back to a detail page, reopening a modal, and entering the same test data again.
Running a project with dozens of extensions should not require managing a growing collection of development ports either.
Those two frustrations started this investigation, and they led to three draft changes for Shopware's Vite development tooling.

The most concrete result so far: in our real Shopware test environment, 77 Administration extension entries went from 78 asset listeners to one.
Twig template edits can update the affected view while preserving its component state.
A separate native component change extends that workflow to supported script edits by restarting the affected component subtree while keeping the surrounding Administration alive.

All three changes are drafts behind the Vite 8 development path.
This is a report about the implementation and the evidence we collected, with the remaining limits made explicit.

## The useful challenge behind the experiment

The discussion started with Soner Sayakci's [Rspack-based Shopware HMR watcher](https://github.com/shyim/shopware-hmr-watcher).
Its independent compilers and Shopware-aware hot updates raised a useful question: could we offer a comparable editing experience within Shopware's existing Vite tooling?

Shopware already had the [Vite 8 upgrade in #21389](https://github.com/shopware/shopware/pull/21389), including the `ADMIN_VITE_8` opt-in path.
That provided a place to experiment with development behavior without making it the default for every existing extension.

We split the problem into two parts.
The server topology determines ports, process overhead, and how extension requests reach their compiler.
The hot-update lifecycle determines whether a changed file can update a view safely without replaying global registrations.
Changing a bundler alone does not settle the second question.

We have not produced a controlled Rspack-versus-Vite benchmark.
The comparison described below measures Shopware's existing separate-listener Vite topology against our proposed shared-listener Vite topology.

## A prototype was useful, but a rendered Administration mattered more

We started with a small prototype reproducing the familiar structure: an Administration root, separate extension roots, aliases, and generated source files.
Vite's Environment API looked promising for putting several extension graphs behind one server.
We expanded the synthetic workloads with TypeScript, Twig, Sass, JSON, and Vue files, and recorded resource samples and graphs.

That established that the idea deserved investigation.
It did not establish that a real Shopware Administration would load, authenticate, render, or respect existing extension configuration.
An HTTP 200 response and a running watcher were insufficient success criteria.

We moved the work into a dedicated Shopware development environment, added 40 generated extensions, installed Shopware Commercial, and opened the actual Administration.
Some fixtures were changed to make visible component overrides rather than merely exporting unused source.
Later checks exercised a real signed SDK App and a plugin-bundled Meteor App, including their actual store registrations and iframe documents.

This transition also changed the proposed architecture.
We retained isolated Vite instances for extensions and shared their HTTP and WebSocket transport through the main Administration server.
Each extension can keep its configuration and dependency boundary while its instance runs in middleware mode behind a distinct URL prefix.
We did not collapse every extension into one universal compiler or module graph.

That distinction matters for aliases, extension-specific Vite plugins, linked packages, and dependencies that cannot simply be treated as one application's imports.
It also explains why the final implementation still has approximately the same number of file watches.

## Making Twig changes update the view

Compiling a changed Twig file is only the first step.
Shopware builds components through registrations, overrides, inherited templates, and cached definitions.
Updating the imported template string while leaving those resolved definitions untouched can leave the screen stale.

The template change connects the changed source back to its component registrations, invalidates the affected template results, and asks Vue to rerender the affected components.
It also updates resolved `Component.extend` descendants and handles eager and lazy overrides.
The intended experience is straightforward: edit the block you are working on and see the result without losing the surrounding form or counter state.

The edge cases were where the implementation became useful.
Removing registrations must clear stale build associations.
Renaming an importer must not accumulate handlers.
An invalid edit must surface an error and recover after repair.
A script helper imported by a native component must not quietly keep displaying the previous computed value.

For the template PR, script-related updates retain a safe page-reload boundary.
That keeps template HMR independently useful while the more complicated script lifecycle lives in a separate change.

## Native blocks need a component lifecycle too

Shopware's [SFC and Composition API discussion](https://github.com/shopware/shopware/discussions/21162) makes the future direction clear: extensions can use Vue Single File Components, `<script setup>`, and native blocks alongside the existing Twig approach.
The native script experiment therefore focuses on that path.

A script edit can change the setup function, computed properties, watchers, or exposed state.
We restart affected component instances and their descendants so that Vue can dispose the previous effects and run the new setup.
The edited subtree can lose its local state; the surrounding Administration and external stores remain alive.

Shopware override registration needs additional care.
Appending a replacement callback would apply an override twice.
Replaying it against state that already contains the old override can build the wrong chain.
The implementation replaces a callback at its existing position and gives the restarted instance a fresh chain to evaluate.

We also found less visible cases: extensions with identical override filenames need distinct HMR identities, and a component edited while unmounted must reopen with its new definition.
The tests cover repeated replacements, stopped watchers, imported helpers, cached definitions, and changes to cached prop declarations.

In one browser check, a native child's computed value changed while its parent's counter stayed at two and two surrounding SDK App drafts survived.
The child's own draft reset, which is the expected script-remount contract.
A subsequent Twig edit worked in the same session without resetting those surrounding values.

## Why Apps do not get an unconditional accept handler

A colleague pointed out that reloading an App can register its tabs and other contributions again.
We tested the concern rather than assuming that all contributions deduplicate themselves.

The negative control deliberately replayed SDK registration without reloading the host.
It produced duplicate sections, cards, modules, and links in real Administration stores.
Tabs using the same identifier happened to upsert, but that did not make the rest of the replay safe.

We kept the existing full Administration reload boundary for App bootstrap and iframe restarts.
An App can still hot-update an isolated view or its CSS when its own HMR boundary avoids replaying registration.
An independently hosted App also keeps its own server; the shared-listener change covers the Administration's local asset servers, including discovered Meteor entries.

Likewise, removing an entire installed extension is not an in-place template update.
Use the normal installation lifecycle, refresh the bundle manifest where necessary, and restart the watcher.
Deleting an actively imported Twig file does not automatically unregister its previous override or restore a base block.

## What we measured

The final comparison used a real Shopware Administration with Commercial and 40 generated plugins, producing 77 Administration extension entries.
Both sides used the same HMR implementation.
The proposed side used the actual middleware transport implementation, rather than the earlier Environment API prototype.

We performed two warm-up navigations and three recorded reloads for each topology.
Readiness meant that the authenticated dashboard had inserted the native counter button into the DOM.
The document load event occurred much earlier, so it would have measured the wrong thing for this experiment.

| Metric | Separate listeners | Shared listener |
| --- | ---: | ---: |
| Asset TCP listeners | 78 | 1 |
| Node processes, including launcher | 3 | 2 |
| Highest observed summed RSS | 2.213 GiB | 1.695 GiB |
| Highest observed summed PSS | 2.076 GiB | 1.620 GiB |
| Peak open file descriptors | 611 | 126 |
| Inotify watch registrations | 19,195 | 19,203 |
| Median dashboard ready | 13.31 s | 9.03 s |
| Median completed requests before readiness | 4,214 | 4,214 |
| Median document TTFB | 91.8 ms | 50.2 ms |
| Median resource TTFB p95 | 9.0 ms | 3.2 ms |
| Median Node CPU time to readiness | 5.02 CPU s | 1.36 CPU s |

![Real Shopware comparison, including individual runs](live-comparison.png)

The reliable topology claim is 78 listeners becoming one.
The observed peak RSS was about 0.52 GiB lower in this environment.
The browser still requested roughly the same module graph, so this change does not claim to solve the high request count of unbundled development.
The three baseline dashboard times were 23.49, 12.10, and 13.31 seconds; the shared-listener times were 16.94, 5.05, and 9.03 seconds.
Those ranges overlap, and a universal percentage speedup would overstate this evidence.

The caches were warm, the runs were sequential rather than randomized, and other host activity was present.
Browser cache bypass was requested, but some immutable dependencies remained cached.
The resource samples include the Node launcher and compilers, excluding the browser, PHP, and database.
Summed RSS can count shared pages more than once, which is why PSS is included too.
Request counts are completed Resource Timing entries started before readiness, plus the document, sampled shortly after readiness.

We do not have a valid Lighthouse performance-score comparison, and we do not infer a RAM requirement for 40 customer extensions from these generated fixtures.
These measurements also predate the final trunk synchronization and native-script work; they support the transport comparison rather than a new performance claim for script HMR.
The [measurement report](2026-10-28-acceptance-and-benchmarks.md#measurement-method), [derived values](live-comparison-summary.json), and analysis program are preserved for scrutiny.

## Three reviewable changes

The implementation is split into [template HMR #22113](https://github.com/shopware/shopware/pull/22113), [shared transport #22119](https://github.com/shopware/shopware/pull/22119), and [native component script HMR #22150](https://github.com/shopware/shopware/pull/22150).
The first two can be reviewed and merged independently.
The third is stacked on the template change.

The latest native implementation validation passed 1,546 related tests across 71 suites, Administration lint and type checks, and a Vite 8 core production build.
The browser acceptance checks included real rendered components and App lifecycle state.
That gives us a useful draft, while exact-head CI and maintainer review remain merge requirements.

There is more to validate: a real Windows lane, broader runtime-error recovery, more extension configurations, and the native-script browser cases on the original separate-listener topology.
Bundled development currently applies changes through reload rather than promising the same state-preserving HMR.
Longer randomized cold and warm benchmarks are needed before stronger performance claims.

The feature is worth pursuing because it removes a concrete operational problem and reduces repeated work during editing.
One Administration asset port makes the development environment easier to expose and manage.
Template HMR keeps the context developers spent time setting up.
Native script HMR extends that benefit where a safe component replacement lifecycle exists, while retaining reloads where registration side effects require them.
