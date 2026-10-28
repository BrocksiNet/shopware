# Browser validation

> Historical research record from 2026-10-27/28.
> See the archive [README](../../../README.md) and [support matrix](../../../SUPPORT_MATRIX.md) for the current scope.

This manual smoke check complements the server-only resource sample in [README.md](README.md).

It ran on 27 October 2026 in the `vite-poc` Shopware development lane.

## Correct development entry point

The browser entry point is the main Vite server at `http://localhost:5181/` on the host.

The PHP `/admin` route at `http://localhost:8110/admin` returned an HTTP 200 shell without Administration script tags because this new lane did not have a built Administration entrypoint installed.

That PHP route response was not evidence that the Admin rendered.

## Proxy correction

The initial Vite process inherited `APP_URL=http://vite-poc.localhost:8088`, which the container could not reach.

The Vite watcher was restarted with `APP_URL=http://127.0.0.1:8000`, the PHP service address inside the same container.

The Vite proxy then returned the expected HTTP 401 from `/api/_info/config` when no session was present.

## Rendered result

The browser rendered the translated Shopware login form from the Vite root.

After signing in to the isolated lane's local test account, the browser rendered the authenticated Administration dashboard at `http://localhost:5181/#/sw/dashboard/index`.

The rendered dashboard showed the main navigation, sales channels, widgets, and the logged-in `admin` user.

The browser tab was left on the dashboard while the 40-plugin Vite watcher remained running.

## Real extension fixture follow-up

The first four generated plugins import Twig blocks and register overrides for the dashboard, manufacturer detail, product detail, and order detail components.

The authenticated browser visibly rendered the `VitePocPlugin01` dashboard card and the `VitePocPlugin02` manufacturer detail note.

On 27 October 2026, the dashboard card text was changed from “Dashboard Twig extension: edit this text to test HMR.” to “Dashboard Twig extension: HMR update reached the dashboard.”

The text appeared in the mounted dashboard without a manual refresh or route change.

The verifier observed an update for plugin 1 and no update event for plugin 2.

This validates the first end-to-end Twig HMR path and extension update isolation for this fixture.

## Manufacturer state preservation

The manufacturer detail fixture was opened at `http://localhost:5181/#/sw/manufacturer/detail/01a17d1047d57359a47bb5c6b40f0a7e`.

The Name field was changed to `HMR unsaved-state probe` and left unsaved.

Plugin 2's Twig text was changed to `manufacturer Twig HMR update reached the detail page.`

The new note appeared in the mounted detail component while the Name field retained the unsaved value.

Restoring the Twig source returned the note to its original text and kept the unsaved Name value.

The form was not saved, so no manufacturer data was changed.

The saved 40-plugin resource sample predates these four behavioral fixture changes.

## What this did not measure

This was a visual and functional smoke check, not a browser performance benchmark.

It did not collect browser heap, browser CPU, network request totals, or edit-to-visible-screen latency.

The separate graph verifier exercised all 41 extension entries and returned 2,730 modules, but this smoke check did not prove customer-specific extension configuration compatibility.

## Lighthouse collection status

A three-run Lighthouse CI configuration targets the authenticated Vite product-list route and writes reports locally.

Collection did not start because the container's Puppeteer Chrome binary required `qemu-x86_64-static` and the container lacked `/lib64/ld-linux-x86-64.so.2`.

No Lighthouse results, request counts, TTFB, or browser resource metrics are available yet.

The saved [Lighthouse config](../../lighthouserc.shopware-vite8.cjs) can be rerun when a compatible local browser executable is available.
