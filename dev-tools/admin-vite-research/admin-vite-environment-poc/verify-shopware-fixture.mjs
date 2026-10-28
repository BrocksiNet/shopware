import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";

const projectRoot = path.resolve(import.meta.dirname, "../..");
const administrationRoot = path.join(
    projectRoot,
    "src/Administration/Resources/app/administration",
);
const mappingPath = path.join(
    administrationRoot,
    "build/vite-plugins/utils/vite-server-mapping.json",
);
const pluginName = process.env.VITE_POC_PLUGIN ?? "VitePocPlugin01";
const technicalName = pluginName
    .replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)
    .replace(/^-/, "");
const mapping = JSON.parse(await fs.readFile(mappingPath, "utf8"));
const mainUrl = `http://127.0.0.1:${mapping.vitePort}`;
const extensionMapping = mapping.extensions[technicalName];
assert.ok(extensionMapping, `No Vite server mapping for ${technicalName}.`);

async function fetchModule(url) {
    const response = await fetch(url);
    const body = await response.text();
    assert.equal(
        response.status,
        200,
        `${url} returned ${response.status}: ${body.slice(0, 300)}`,
    );
    return { body, contentType: response.headers.get("content-type") ?? "" };
}

async function fetchExtensionGraph(
    extension = extensionMapping,
    entryFile = "main.ts",
) {
    const entryUrl = new URL(
        `${extension.basePath}${entryFile}`,
        mainUrl,
    ).toString();
    const pending = [entryUrl];
    const visited = new Set();
    const types = new Map();

    while (pending.length > 0) {
        const url = pending.pop();
        if (visited.has(url)) continue;
        visited.add(url);

        const { body, contentType } = await fetchModule(url);
        const type = contentType.split(";")[0];
        types.set(type, (types.get(type) ?? 0) + 1);
        if (!type.includes("javascript") && !type.includes("typescript"))
            continue;

        const imports =
            /(?:\bimport\s+(?:[^'";]*?\s+from\s+)?|\bexport\s+[^'";]*?\s+from\s+)['"]([^'"]+)['"]/g;
        for (const match of body.matchAll(imports)) {
            const childUrl = new URL(match[1], url);
            if (
                childUrl.origin === mainUrl &&
                childUrl.pathname.startsWith(extension.basePath)
            ) {
                pending.push(childUrl.toString());
            }
        }
        assert.ok(
            visited.size <= 1000,
            "Extension graph exceeded 1000 modules.",
        );
    }

    return {
        entryUrl,
        modules: visited.size,
        types: Object.fromEntries(types),
    };
}

async function checkAllEntries() {
    const plugins = JSON.parse(
        await fs.readFile(path.join(projectRoot, "var/plugins.json"), "utf8"),
    );
    const entries = Object.entries(plugins).filter(
        ([, definition]) => definition.administration?.entryFilePath,
    );
    let passed = 0;
    for (const [name, definition] of entries) {
        const tech = definition.technicalName;
        const server = mapping.extensions[tech];
        if (!server) continue;
        const entryFile = path.posix.basename(
            definition.administration.entryFilePath,
        );
        await fetchModule(
            new URL(`${server.basePath}${entryFile}`, mainUrl).toString(),
        );
        passed += 1;
    }
    assert.equal(
        passed,
        entries.length,
        `Only ${passed}/${entries.length} Administration entries had a Vite server mapping.`,
    );
    console.log(
        `Entry requests: ${passed}/${entries.length} returned HTTP 200.`,
    );
}

async function checkAllGraphs() {
    const plugins = JSON.parse(
        await fs.readFile(path.join(projectRoot, "var/plugins.json"), "utf8"),
    );
    let moduleCount = 0;
    for (const [name, server] of Object.entries(mapping.extensions)) {
        const definition = Object.values(plugins).find(
            (item) => item.technicalName === name,
        );
        const entryFile = path.posix.basename(
            definition?.administration?.entryFilePath ?? "main.ts",
        );
        const graph = await fetchExtensionGraph(server, entryFile);
        moduleCount += graph.modules;
    }
    console.log(
        `All extension graphs: ${Object.keys(mapping.extensions).length} entries and ${moduleCount} modules returned HTTP 200.`,
    );
}

async function connectExtensionSocket(extension) {
    const client = await fetchModule(
        new URL(`${extension.basePath}@vite/client`, mainUrl).toString(),
    );
    const token = client.body.match(/const wsToken = "([^"]+)"/)?.[1];
    assert.ok(token, `Could not read the HMR token for ${extension.basePath}.`);
    const socket = new WebSocket(
        `ws://127.0.0.1:${mapping.vitePort}${extension.basePath}?token=${token}`,
        "vite-hmr",
    );
    await new Promise((resolve, reject) => {
        const timeout = setTimeout(
            () =>
                reject(
                    new Error("Timed out connecting to Vite HMR websocket."),
                ),
            5000,
        );
        socket.addEventListener(
            "open",
            () => {
                clearTimeout(timeout);
                resolve();
            },
            { once: true },
        );
        socket.addEventListener(
            "error",
            () => {
                clearTimeout(timeout);
                reject(
                    new Error(
                        "Could not connect to the extension Vite HMR websocket through the Administration server.",
                    ),
                );
            },
            { once: true },
        );
    });
    return socket;
}

async function checkHmr() {
    const sourcePath = path.resolve(
        projectRoot,
        process.env.VITE_POC_HMR_FILE ??
            path.join(
                "custom/plugins",
                pluginName,
                "src/Resources/app/administration/src/views/view-01.twig",
            ),
    );
    const original = await fs.readFile(sourcePath, "utf8");
    const beforeText =
        process.env.VITE_POC_HMR_BEFORE ??
        "Dashboard Twig extension: edit this text to test HMR.";
    const afterText =
        process.env.VITE_POC_HMR_AFTER ??
        "Dashboard Twig extension: HMR update reached the dashboard.";
    const changed = original.replace(beforeText, afterText);
    assert.notEqual(
        changed,
        original,
        "HMR fixture edit did not change the module source.",
    );
    const controlExtension = mapping.extensions["vite-poc-plugin02"];
    const [socket, controlSocket] = await Promise.all([
        connectExtensionSocket(extensionMapping),
        connectExtensionSocket(controlExtension),
    ]);

    try {
        let controlUpdated = false;
        controlSocket.addEventListener("message", (event) => {
            const message = JSON.parse(String(event.data));
            if (message.type === "update") controlUpdated = true;
        });
        const update = new Promise((resolve, reject) => {
            let sawFileChange = false;
            let sawUpdate = false;
            let sawFullReload = false;
            const updateWasDelivered = () =>
                sawUpdate || (updateType === "script" && sawFullReload);
            const finish = () => {
                if (sawFileChange && updateWasDelivered()) resolve();
            };
            const timeout = setTimeout(
                () =>
                    reject(
                        new Error(
                            "Timed out waiting for the plugin Twig HMR update.",
                        ),
                    ),
                10000,
            );
            socket.addEventListener("message", (event) => {
                const message = JSON.parse(String(event.data));
                if (process.env.VITE_POC_DEBUG_HMR === "1") {
                    console.log("Plugin HMR message:", JSON.stringify(message));
                }
                if (
                    message.type === "custom" &&
                    message.event === "file-changed" &&
                    message.data.file.endsWith(path.basename(sourcePath))
                ) {
                    sawFileChange = true;
                    finish();
                }
                if (message.type === "update") {
                    sawUpdate = true;
                    finish();
                }
                if (sawFileChange && sawUpdate) {
                    clearTimeout(timeout);
                }
            });
        });
        await fs.writeFile(sourcePath, changed);
        await update;
        const holdVisibleMs = Number.parseInt(
            process.argv
                .find((argument) => argument.startsWith("--hold-visible="))
                ?.split("=")[1] ?? "0",
            10,
        );
        if (holdVisibleMs > 0) {
            console.log(
                `Holding the changed Twig template for ${holdVisibleMs} ms so the browser can be inspected.`,
            );
            await new Promise((resolve) => setTimeout(resolve, holdVisibleMs));
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
        assert.equal(
            controlUpdated,
            false,
            "Editing plugin 1 also sent an HMR update to plugin 2.",
        );
        console.log(
            "Twig HMR protocol: plugin 1 emitted a file-change and update event; plugin 2 received no update. This does not verify a browser-rendered update.",
        );
    } finally {
        await fs.writeFile(sourcePath, original);
        socket.close();
        controlSocket.close();
    }
}

async function checkNativeHmr() {
    assert.equal(
        pluginName,
        "VitePocPlugin01",
        "Native SFC fixture is generated in VitePocPlugin01.",
    );
    const sourcePath = path.join(
        projectRoot,
        "custom/plugins",
        pluginName,
        "src/Resources/app/administration/src/sw-dashboard-index.override.vue",
    );
    const original = await fs.readFile(sourcePath, "utf8");
    const updateType = process.argv.includes("--native-script-hmr")
        ? "script"
        : "template";
    const changed =
        updateType === "script"
            ? original.replace(
                  "const nativeHmrText = 'Native SFC state counter';",
                  "const nativeHmrText = 'SFC script HMR reached the dashboard';",
              )
            : original.replace(
                  "edit this text to test HMR.",
                  "SFC template edit reached the dashboard.",
              );
    assert.notEqual(
        changed,
        original,
        `Native SFC ${updateType} edit did not change the source.`,
    );

    const controlExtension = mapping.extensions["vite-poc-plugin02"];
    const [socket, controlSocket] = await Promise.all([
        connectExtensionSocket(extensionMapping),
        connectExtensionSocket(controlExtension),
    ]);

    try {
        let controlUpdated = false;
        controlSocket.addEventListener("message", (event) => {
            if (JSON.parse(String(event.data)).type === "update")
                controlUpdated = true;
        });
        const update = new Promise((resolve, reject) => {
            let sawFileChange = false;
            let sawUpdate = false;
            const finish = () => {
                if (sawFileChange && sawUpdate) resolve();
            };
            const timeout = setTimeout(
                () =>
                    reject(
                        new Error(
                            `Timed out waiting for the native SFC ${updateType} update.`,
                        ),
                    ),
                10000,
            );
            socket.addEventListener("message", (event) => {
                const message = JSON.parse(String(event.data));
                if (process.env.VITE_POC_DEBUG_HMR === "1") {
                    console.log("Native HMR message:", JSON.stringify(message));
                }
                if (
                    message.type === "custom" &&
                    message.event === "file-changed" &&
                    message.data.file.endsWith(
                        "sw-dashboard-index.override.vue",
                    )
                ) {
                    sawFileChange = true;
                    finish();
                }
                if (message.type === "update") {
                    const updates = message.updates ?? [];
                    const includesNativeSfc = updates.some((item) =>
                        String(item.path).includes(
                            "sw-dashboard-index.override.vue",
                        ),
                    );
                    console.log(
                        `Native HMR update modules: ${JSON.stringify(updates.map(({ path: updatePath, type }) => ({ path: updatePath, type })))}`,
                    );
                    sawUpdate ||= includesNativeSfc;
                    finish();
                }
                if (message.type === "full-reload") {
                    sawFullReload = true;
                    finish();
                }
                if (sawFileChange && updateWasDelivered()) clearTimeout(timeout);
            });
        });

        await fs.writeFile(sourcePath, changed);
        await update;
        const holdVisibleMs = Number.parseInt(
            process.argv
                .find((argument) => argument.startsWith("--hold-visible="))
                ?.split("=")[1] ?? "0",
            10,
        );
        if (holdVisibleMs > 0) {
            console.log(
                `Holding the changed SFC ${updateType} for ${holdVisibleMs} ms so the browser can be inspected.`,
            );
            await new Promise((resolve) => setTimeout(resolve, holdVisibleMs));
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
        assert.equal(
            controlUpdated,
            false,
            "Editing the native SFC also sent an HMR update to plugin 2.",
        );
        const resultMessage =
            updateType === "script"
                ? "Native SFC script change: plugin 1 emitted a file-change and full-reload event; plugin 2 received no update."
                : "Native SFC template HMR: plugin 1 emitted a file-change and Vue update event; plugin 2 received no update.";
        console.log(resultMessage);
    } finally {
        await fs.writeFile(sourcePath, original);
        socket.close();
        controlSocket.close();
    }
}

const graph = await fetchExtensionGraph();
console.log(
    `Extension graph: ${graph.modules} modules returned HTTP 200 (${JSON.stringify(graph.types)}).`,
);
if (process.argv.includes("--all-entries")) await checkAllEntries();
if (process.argv.includes("--all-graphs")) await checkAllGraphs();
if (process.argv.includes("--hmr")) await checkHmr();
if (
    process.argv.includes("--native-hmr") ||
    process.argv.includes("--native-script-hmr")
)
    await checkNativeHmr();
