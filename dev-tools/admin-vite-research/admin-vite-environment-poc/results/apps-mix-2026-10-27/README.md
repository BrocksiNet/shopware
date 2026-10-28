# Benchmark results

> Historical research record from 2026-10-27/28.
> See the archive [README](../../../README.md) and [support matrix](../../../SUPPORT_MATRIX.md) for the current scope.

These measurements were generated on 2026-10-27T10:56:28.470Z with Vite 8.3.3.

The fixture uses the shopware-shaped profile at a 1x source multiplier and models 36 ts, 14 twig, 6 scss, 4 json, 2 vue per plugin extension.

The runs record process-tree RSS, CPU time, file descriptors, inotify watches, threads, server startup time, and module transform time.

A worker is stopped if process-tree RSS exceeds 4096 MiB or it runs longer than 180000 ms.

These results do not include a browser process, customer extension configs, or a booted Shopware runtime.

The raw observations are in [raw.json](raw.json), the median summary is in [summary.csv](summary.csv), and the plots are in [graphs.svg](graphs.svg).
