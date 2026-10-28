# Commercial extension validation

> Historical research record from 2026-10-27/28.
> See the archive [README](../../README.md) and [support matrix](../../SUPPORT_MATRIX.md) for the current scope.

## Environment

The test ran in the dedicated `shopware-vite-poc` Shopware development lane.

The supplied Commercial checkout was mirrored into the Shopware container with the Mutagen session `shopware-vite-poc-commercial`.

The session is a one-way replica from the Commercial checkout to `/var/www/html/custom/plugins/SwagCommercial`.

The Commercial source checkout is clean after the test probe was removed.

Commercial Admin dependencies were installed inside the container with `npm ci --no-audit --prefer-offline` in `custom/plugins/SwagCommercial`.

The extension was refreshed and activated through `bin/console`.

## Server and port observations

The Administration Vite server listens on container port `5173` and is exposed to the host on port `5181`.

The generated Vite server mapping contains 77 extension servers in addition to the Administration server.

Each extension server keeps a separate internal port, while the browser connects through the Administration server's host-facing port `5181`.

The ContentGenerator server is exposed under `/_internal_ext/content-generator/` and the Commercial package server is exposed under `/_internal_ext/swag-commercial/`.

The POC therefore confirms that `ADMIN_VITE_8` does not consolidate extension servers or remove their internal port allocation.

The all-entry verifier returned HTTP 200 for all 77 Administration entries.

## Twig HMR observation

The browser test used the actual Commercial ContentGenerator override at `src/ContentGenerator/Resources/app/administration/src/module/sw-cms/elements/text/component/sw-cms-el-text.html.twig`.

The CMS editor loaded the Commercial `sw-cms-el-text` override and displayed a temporary marker from that Twig file.

On source change, the ContentGenerator Vite client logged a `hot updated` message for the Twig module.

The second extension's HMR socket received no update event.

The first attempt edited the container copy directly, which was incorrect for this one-way Mutagen session because the host checkout immediately replaced the container change.

After editing the host Commercial checkout and flushing Mutagen, the mounted CMS element changed to the new marker without a page reload.

Restoring the source also removed the marker from the mounted element without a reload.

The browser console showed the injected HMR callback running and the Shopware update bridge returning success.

The temporary marker was removed, Mutagen was flushed, and the CMS editor showed the original template again.

No CMS layout was saved during this test.

## Compatibility note

Commercial's `src/Resources/config/packages/shopware.yaml` declares `SSO.major` as a boolean, while the current Shopware test lane schema expects a string.

For plugin activation, the value was temporarily changed in the Commercial source to `v6.8.0.0`, then restored to its original boolean value.

The container cache was cleared while the compatible string was present.

Do not clear the Shopware cache again in this lane until the Commercial config is adapted temporarily or the lane's schema compatibility is resolved.

## Current state

The Administration Vite server listens on container port `5173` and remains available through `http://localhost:5181`.

The watcher should be stopped through its process tree; interrupting only the parent command can leave child Vite servers behind and cause the next run to select different internal ports.

The authenticated CMS editor tab remains open on the Imprint layout.

The Commercial Mutagen session remains active for further tests.
