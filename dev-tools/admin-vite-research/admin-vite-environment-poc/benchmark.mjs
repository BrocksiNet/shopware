import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";
import { createRequire } from "node:module";
import { pathToFileURL } from "node:url";
import { performance } from "node:perf_hooks";

const scriptPath = path.resolve(import.meta.filename);
const repositoryRoot = path.resolve(import.meta.dirname, "../..");
const administrationRoot = path.join(
    repositoryRoot,
    "src/Administration/Resources/app/administration",
);
const vite8Package = path.join(administrationRoot, "build/vite-8/package.json");
const vite8Require = createRequire(vite8Package);
const vuePluginFactory = vite8Require("@vitejs/plugin-vue").default;
const sourceMix = { ts: 36, twig: 14, scss: 6, json: 4, vue: 2 };
const appSourceMix = { ts: 8, twig: 0, scss: 0, json: 1, vue: 0 };
const modes = [
    "per-extension",
    "environments-default",
    "environments-no-discovery",
    "shared-client",
];
const enabledModes = new Set(modes);

function readOptions(args) {
    const options = {};

    for (const argument of args) {
        const [key, value] = argument.replace(/^--/, "").split("=");
        options[key] = value ?? "true";
    }

    return options;
}

function listFromOption(value, fallback) {
    return (value ?? fallback).split(",").map((item) => item.trim());
}

function fixtureSourceMix() {
    return Object.entries(sourceMix)
        .map(([extension, count]) => `${count} ${extension}`)
        .join(", ");
}

function scaleSourceMix(mix, multiplier) {
    return Object.fromEntries(
        Object.entries(mix).map(([extension, count]) => [
            extension,
            count * multiplier,
        ]),
    );
}

function extensionInfo(fixtureRoot, index, appEvery, sourceMultiplier) {
    const number = String(index + 1).padStart(2, "0");
    const isApp = appEvery > 0 && (index + 1) % appEvery === 0;
    const folderName = `${isApp ? "App" : "Plugin"}${number}`;
    const typeRoot = isApp ? "apps" : "plugins";
    const relativeRoot = isApp
        ? path.join("custom", typeRoot, folderName, "Resources/app/meteor-app")
        : path.join(
              "custom",
              typeRoot,
              folderName,
              "src/Resources/app/administration",
          );
    const root = path.join(fixtureRoot, relativeRoot);
    const technicalName = `${isApp ? "app" : "plugin"}-${number}`;

    return {
        index,
        isApp,
        folderName,
        technicalName,
        name: technicalName,
        root,
        viteRoot: isApp ? root : path.join(root, "src"),
        entry: path.join(root, "src/main.ts"),
        urlPrefix: `/__poc_ext__/${technicalName}`,
        label: `${technicalName}-label`,
        sourceMix: scaleSourceMix(
            isApp ? appSourceMix : sourceMix,
            sourceMultiplier,
        ),
    };
}

async function writeFile(filePath, contents) {
    await fs.mkdir(path.dirname(filePath), { recursive: true });
    await fs.writeFile(filePath, contents);
}

async function measureFixtureTree(directory) {
    const entries = await fs.readdir(directory, { withFileTypes: true });
    let files = 0;
    let bytes = 0;
    for (const entry of entries) {
        const entryPath = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            const child = await measureFixtureTree(entryPath);
            files += child.files;
            bytes += child.bytes;
        } else if (entry.isFile()) {
            files += 1;
            bytes += (await fs.stat(entryPath)).size;
        }
    }
    return { files, bytes };
}

async function readCpuModel() {
    const cpuInfo = await fs.readFile("/proc/cpuinfo", "utf8").catch(() => "");
    return (
        cpuInfo.match(/^(?:model name|Hardware|Processor)\s*:\s*(.+)$/m)?.[1] ??
        os.cpus()[0]?.model ??
        "unknown"
    );
}

async function makeFixtures(
    fixtureRoot,
    administrationRootPath,
    count,
    appEvery,
    sourceMultiplier,
) {
    const extensions = Array.from({ length: count }, (_, index) =>
        extensionInfo(fixtureRoot, index, appEvery, sourceMultiplier),
    );
    await writeFile(
        path.join(administrationRootPath, "index.html"),
        '<!doctype html><html><head></head><body><div id="app"></div><script type="module" src="/src/main.ts"></script></body></html>',
    );
    await writeFile(
        path.join(administrationRootPath, "src/main.ts"),
        'export const administration = "administration-entry";',
    );

    const manifest = {};
    const pluginDevManifest = { metadata: "shopware" };

    for (const extension of extensions) {
        const mix = extension.sourceMix;
        const imports = [
            'import label from "@extension-label";',
            'import.meta.glob("./modules/*.ts", { eager: true });',
            'import.meta.glob("./views/*.twig", { eager: true, import: "default" });',
            'import.meta.glob("./data/*.json", { eager: true });',
            'import.meta.glob("./components/*.vue", { eager: true });',
            ...Array.from(
                { length: mix.scss },
                (_, index) =>
                    `import "./styles/style-${String(index + 1).padStart(2, "0")}.scss";`,
            ),
        ].join("\n");

        await writeFile(
            extension.entry,
            `${imports}\nexport const extension = { label, kind: "${extension.isApp ? "app" : "plugin"}" };\nif (import.meta.hot) import.meta.hot.accept();`,
        );
        await writeFile(
            path.join(extension.root, "src/label.ts"),
            `export default "${extension.label}";`,
        );

        for (let index = 0; index < mix.ts; index += 1) {
            const fileName = `module-${String(index + 1).padStart(2, "0")}.ts`;
            const previousImport =
                index > 0
                    ? `import previous from "./module-${String(index).padStart(2, "0")}.ts";\n`
                    : "";
            const previousValue = index > 0 ? "previous.length" : "1";

            await writeFile(
                path.join(extension.root, "src/modules", fileName),
                `${previousImport}export default Object.freeze({ extension: "${extension.technicalName}", index: ${index}, payload: "${"x".repeat(4096)}", previous: ${previousValue} });`,
            );
        }

        for (let index = 0; index < mix.twig; index += 1) {
            const fileName = `view-${String(index + 1).padStart(2, "0")}.html.twig`;
            await writeFile(
                path.join(extension.root, "src/views", fileName),
                `<section data-extension="${extension.technicalName}"><h2>Administration module ${index + 1}</h2><p>${"Twig fixture content ".repeat(125)}</p></section>`,
            );
        }

        for (let index = 0; index < mix.scss; index += 1) {
            const selectors = Array.from(
                { length: 16 },
                (_, rule) =>
                    `.view-${index}-${rule} { padding: ${rule + 1}px; margin: ${rule}px; color: #${String((rule + 1) * 100).padStart(6, "0")}; }`,
            ).join("\n");
            await writeFile(
                path.join(
                    extension.root,
                    "src/styles",
                    `style-${String(index + 1).padStart(2, "0")}.scss`,
                ),
                `$spacing: ${index + 1}px;\n[data-extension="${extension.technicalName}"] { ${selectors} }`,
            );
        }

        for (let index = 0; index < mix.json; index += 1) {
            await writeFile(
                path.join(
                    extension.root,
                    "src/data",
                    `config-${String(index + 1).padStart(2, "0")}.json`,
                ),
                JSON.stringify({
                    name: extension.technicalName,
                    index,
                    enabled: true,
                    featureFlags: Array.from({ length: 80 }, (_, flag) => ({
                        name: `feature-${flag}`,
                        enabled: flag % 2 === 0,
                    })),
                }),
            );
        }

        for (let index = 0; index < mix.vue; index += 1) {
            await writeFile(
                path.join(
                    extension.root,
                    "src/components",
                    `Component-${String(index + 1).padStart(2, "0")}.vue`,
                ),
                `<script setup>import { ref } from "vue"; const title = ref("${extension.technicalName}-${index}"); const data = "${"x".repeat(1024)}";</script><template><section><h2>{{ title }}</h2><p>${"Component fixture content ".repeat(80)}</p></section></template>`,
            );
        }

        if (extension.isApp) {
            manifest[extension.folderName] = {
                basePath: path.join("custom", "apps", extension.folderName),
                technicalName: extension.technicalName,
                isTheme: false,
                administration: {
                    path: "Resources/app/administration/src",
                    entryFilePath: null,
                },
            };
            await writeFile(
                path.join(extension.root, "index.html"),
                '<!doctype html><html><body><div id="app"></div><script type="module" src="/src/main.ts"></script></body></html>',
            );
            pluginDevManifest[extension.technicalName] = {
                html: `/_internal_ext/${extension.technicalName}/index.html`,
            };
        } else {
            const configPath = path.join(extension.root, "vite.config.mjs");
            await writeFile(
                configPath,
                `const sourceFile = ${JSON.stringify(path.join(extension.root, "src/label.ts"))};\nexport default { plugins: [{ name: "fixture-${extension.technicalName}-config", transform(code, id) { if (id !== sourceFile) return null; return { code: ${JSON.stringify(`export default "${extension.label}";`)}, map: null }; } }] };`,
            );
            manifest[extension.folderName] = {
                basePath: path.join(
                    "custom",
                    "plugins",
                    extension.folderName,
                    "src",
                ),
                technicalName: extension.technicalName,
                isTheme: false,
                administration: {
                    path: "Resources/app/administration/src",
                    entryFilePath: path.join(
                        "Resources/app/administration/src",
                        "main.ts",
                    ),
                },
            };
            pluginDevManifest[extension.technicalName] = {
                js: `/_internal_ext/${extension.technicalName}/${path.basename(extension.entry)}`,
                hmrSrc: `/_internal_ext/${extension.technicalName}/@vite/client`,
            };
        }
    }

    await writeFile(
        path.join(fixtureRoot, "var/plugins.json"),
        JSON.stringify(manifest, null, 2),
    );
    await writeFile(
        path.join(fixtureRoot, "sw-plugin-dev.json"),
        JSON.stringify(pluginDevManifest, null, 2),
    );
    await writeFile(
        path.join(fixtureRoot, "vue-global.js"),
        "module.exports = window['Shopware']?.['Vue'];",
    );
    await fs.mkdir(path.join(fixtureRoot, "node_modules"), { recursive: true });

    for (const packageName of ["vue", "sass"]) {
        await fs.symlink(
            path.join(administrationRoot, "node_modules", packageName),
            path.join(fixtureRoot, "node_modules", packageName),
            "dir",
        );
    }

    return extensions;
}

async function discoverFixtureExtensions(fixtureRoot, generatedExtensions) {
    const manifest = JSON.parse(
        await fs.readFile(path.join(fixtureRoot, "var/plugins.json"), "utf8"),
    );
    const generatedByName = new Map(
        generatedExtensions.map((extension) => [
            extension.folderName,
            extension,
        ]),
    );
    const discoveredApps = [];
    for (const [name, definition] of Object.entries(manifest)) {
        if (!definition.administration?.path) continue;
        const appEntryPath = path.resolve(
            fixtureRoot,
            definition.basePath,
            definition.administration.path,
            "../..",
            "meteor-app",
        );
        try {
            const stat = await fs.stat(path.join(appEntryPath, "index.html"));
            if (stat.isFile()) discoveredApps.push([name, definition]);
        } catch {
            // The Shopware loader only treats this manifest entry as an app if its HTML exists.
        }
    }
    const apps = [];

    for (const [name, definition] of discoveredApps) {
        const technicalName =
            definition.technicalName ||
            name.replace(/([a-z])([A-Z])/g, "$1-$2").toLowerCase();
        const root = path.resolve(
            fixtureRoot,
            definition.basePath,
            definition.administration.path,
            "../..",
            "meteor-app",
        );
        const generated = generatedByName.get(name);
        assert.ok(generated, `App ${name} must come from the fixture manifest`);
        apps.push({
            ...generated,
            technicalName,
            root,
            viteRoot: root,
            entry: path.join(root, "src/main.ts"),
            isApp: true,
        });
    }

    const plugins = [];
    for (const [name, definition] of Object.entries(manifest)) {
        if (!definition.administration?.entryFilePath) continue;
        const technicalName =
            definition.technicalName ||
            name.replace(/([a-z])([A-Z])/g, "$1-$2").toLowerCase();
        const root = path.resolve(
            fixtureRoot,
            definition.basePath,
            definition.administration.path,
        );
        const entry = path.resolve(
            fixtureRoot,
            definition.basePath,
            definition.administration.entryFilePath,
        );
        try {
            await fs.access(entry);
        } catch {
            continue;
        }
        const generated = generatedByName.get(name);
        assert.ok(
            generated,
            `Plugin ${name} must come from the fixture manifest`,
        );
        plugins.push({
            ...generated,
            technicalName,
            root: path.dirname(root),
            viteRoot: root,
            entry,
            isApp: false,
        });
    }

    const discovered = [...plugins, ...apps];
    assert.equal(
        discovered.length,
        generatedExtensions.length,
        "fixture discovery should include each plugin and app exactly once",
    );
    return discovered.map((extension, index) => ({ ...extension, index }));
}

function twigFixturePlugin() {
    return {
        name: "shopware-vite-plugin-twig-fixture",
        transform(source, id) {
            if (!/\.(twig|html)$/.test(id)) return null;
            const value = source
                .replace(/<!--[^]*?-->/g, "")
                .replace(/\s+/g, " ")
                .trim();
            return `export default ${JSON.stringify(value)};`;
        },
    };
}

function extensionRoutingPlugin(extensions) {
    const roots = extensions.map((extension) => ({
        root: extension.root,
        technicalName: extension.technicalName,
    }));

    return {
        name: "shopware-fixture-extension-routing",
        resolveId(source, importer) {
            if (source === "@extension-label" && importer) {
                const extension = roots.find((candidate) =>
                    importer.startsWith(candidate.root),
                );
                return extension
                    ? path.join(extension.root, "src/label.ts")
                    : null;
            }

            const match = source.match(/^\/__poc_ext__\/([^/]+)\/(.+)$/);
            if (!match) return null;
            const [, technicalName, relativeFile] = match;
            const extension = roots.find(
                (candidate) => candidate.technicalName === technicalName,
            );
            assert.ok(extension, `Unknown fixture extension ${technicalName}`);
            return path.resolve(extension.root, relativeFile);
        },
    };
}

async function loadExtensionPlugins(extensions) {
    const pluginArrays = await Promise.all(
        extensions
            .filter((extension) => !extension.isApp)
            .map(async (extension) => {
                const configPath = path.join(extension.root, "vite.config.mjs");
                const config = await import(pathToFileURL(configPath).href);
                return config.default.plugins ?? [];
            }),
    );
    return pluginArrays.flat();
}

function environmentName(extension) {
    return `extension_${extension.technicalName.replaceAll("-", "_")}`;
}

function parseProcStatus(statusText) {
    const fields = Object.fromEntries(
        statusText
            .split("\n")
            .map((line) => line.match(/^([^:]+):\s*(.*)$/))
            .filter(Boolean)
            .map(([, key, value]) => [key, value]),
    );
    return {
        parent: Number(fields.PPid ?? 0),
        rssKb: Number.parseInt(fields.VmRSS ?? "0", 10),
        highWaterKb: Number.parseInt(fields.VmHWM ?? "0", 10),
        threads: Number(fields.Threads ?? 0),
    };
}

async function readProcProcess(pid) {
    try {
        const [statusText, statText, fdNames] = await Promise.all([
            fs.readFile(`/proc/${pid}/status`, "utf8"),
            fs.readFile(`/proc/${pid}/stat`, "utf8"),
            fs.readdir(`/proc/${pid}/fd`),
        ]);
        const closeParen = statText.lastIndexOf(")");
        const stat = statText.slice(closeParen + 2).split(/\s+/);
        const status = parseProcStatus(statusText);
        const fdInfo = await Promise.all(
            fdNames.map((fdName) =>
                fs
                    .readFile(`/proc/${pid}/fdinfo/${fdName}`, "utf8")
                    .catch(() => ""),
            ),
        );
        const watchCount = fdInfo.reduce(
            (sum, info) => sum + (info.match(/^inotify wd:/gm)?.length ?? 0),
            0,
        );

        return {
            pid,
            parent: status.parent,
            rssKb: status.rssKb,
            highWaterKb: status.highWaterKb,
            threads: status.threads,
            fds: fdNames.length,
            watches: watchCount,
            userTicks: Number(stat[11] ?? 0),
            systemTicks: Number(stat[12] ?? 0),
        };
    } catch {
        return null;
    }
}

async function sampleProcessTree(rootPid) {
    const tree = [];
    const pending = [rootPid];
    const seen = new Set();

    while (pending.length) {
        const pid = pending.pop();
        if (seen.has(pid)) continue;
        seen.add(pid);
        const record = await readProcProcess(pid);
        if (record) tree.push(record);
        const childrenText = await fs
            .readFile(`/proc/${pid}/task/${pid}/children`, "utf8")
            .catch(() => "");
        pending.push(...childrenText.split(/\s+/).filter(Boolean).map(Number));
    }

    return tree;
}

function summarizeTree(tree, previous, elapsedMs) {
    const hz = 100;
    const current = new Map(tree.map((record) => [record.pid, record]));
    let userTicks = 0;
    let systemTicks = 0;

    for (const record of tree) {
        const before = previous?.get(record.pid);
        if (before) {
            userTicks += Math.max(0, record.userTicks - before.userTicks);
            systemTicks += Math.max(0, record.systemTicks - before.systemTicks);
        }
    }

    return {
        rssKb: tree.reduce((sum, record) => sum + record.rssKb, 0),
        highWaterKb: tree.reduce((sum, record) => sum + record.highWaterKb, 0),
        fds: tree.reduce((sum, record) => sum + record.fds, 0),
        watches: tree.reduce((sum, record) => sum + record.watches, 0),
        processCount: tree.length,
        threads: tree.reduce((sum, record) => sum + record.threads, 0),
        cpuMs: ((userTicks + systemTicks) * 1000) / hz,
        elapsedMs,
        current,
    };
}

function buildPluginStack(extensions, extensionPlugins, mode) {
    const selectedExtensionPlugins =
        mode === "per-extension" ? extensionPlugins : extensionPlugins;
    return [
        vuePluginFactory({
            template: {
                compilerOptions: { compatConfig: { MODE: 2 } },
            },
        }),
        twigFixturePlugin(),
        ...selectedExtensionPlugins,
        extensionRoutingPlugin(extensions),
    ];
}

async function workerRun({
    count,
    mode,
    appEvery,
    sourceProfile,
    sourceMultiplier,
}) {
    assert.ok(Number.isInteger(count) && count > 0, "count must be positive");
    assert.ok(enabledModes.has(mode), `unsupported mode ${mode}`);
    assert.ok(sourceProfile === "shopware-shaped", "unknown source profile");

    const fixtureRoot = await fs.mkdtemp(
        path.join(os.tmpdir(), "shopware-admin-vite-benchmark-"),
    );
    const adminRoot = path.join(
        fixtureRoot,
        "src/Administration/Resources/app/administration",
    );
    await fs.mkdir(adminRoot, { recursive: true });

    let servers = [];
    let sampleTimer;
    let peak = null;
    let previousTree = new Map();
    let cpuTotalMs = 0;
    let sampling = false;
    const fixtureStartedAt = performance.now();
    const generatedExtensions = await makeFixtures(
        fixtureRoot,
        adminRoot,
        count,
        appEvery,
        sourceMultiplier,
    );
    const extensions = await discoverFixtureExtensions(
        fixtureRoot,
        generatedExtensions,
    );
    const fixtureReadyMs = Math.round(performance.now() - fixtureStartedAt);
    for (const extension of extensions) {
        extension.fixtureStats = await measureFixtureTree(extension.root);
    }
    const extensionPlugins = await loadExtensionPlugins(extensions);
    const { createServer } = await import(
        pathToFileURL(vite8Require.resolve("vite")).href
    );
    const benchmarkStarted = performance.now();
    const startCpu = process.cpuUsage();
    const environments = Object.fromEntries(
        extensions.map((extension) => [
            environmentName(extension),
            {
                consumer: "client",
                optimizeDeps: {
                    entries: [
                        extension.isApp
                            ? path.join(extension.root, "index.html")
                            : extension.entry,
                    ],
                    ...(mode === "environments-no-discovery"
                        ? { noDiscovery: true, include: [] }
                        : {}),
                },
            },
        ]),
    );
    const sample = async () => {
        if (sampling) return;
        sampling = true;
        try {
            const tree = await sampleProcessTree(process.pid);
            const summary = summarizeTree(tree, previousTree, 0);
            previousTree = summary.current;
            cpuTotalMs += summary.cpuMs;
            if (!peak || summary.rssKb > peak.rssKb) peak = summary;
            peak.fds = Math.max(peak.fds, summary.fds);
            peak.watches = Math.max(peak.watches, summary.watches);
            peak.processCount = Math.max(
                peak.processCount,
                summary.processCount,
            );
            peak.threads = Math.max(peak.threads, summary.threads);
        } finally {
            sampling = false;
        }
    };

    await sample();
    sampleTimer = setInterval(() => {
        void sample();
    }, 100);

    try {
        const serverStartedAt = performance.now();
        const commonOptions = {
            configFile: false,
            appType: "mpa",
            server: {
                host: "127.0.0.1",
                port: 0,
                strictPort: false,
                fs: { allow: [fixtureRoot, administrationRoot] },
            },
        };

        if (mode === "per-extension") {
            const adminServer = await createServer({
                ...commonOptions,
                root: adminRoot,
                plugins: [extensionRoutingPlugin(extensions)],
            });
            await adminServer.listen();
            servers.push(adminServer);

            for (const extension of extensions) {
                const scopedPlugins = extension.isApp
                    ? [extensionRoutingPlugin([extension])]
                    : [
                          vuePluginFactory({
                              template: {
                                  compilerOptions: {
                                      compatConfig: { MODE: 2 },
                                  },
                              },
                          }),
                          twigFixturePlugin(),
                          ...(await loadExtensionPlugins([extension])),
                          extensionRoutingPlugin([extension]),
                      ];
                const server = await createServer({
                    ...commonOptions,
                    root: extension.viteRoot,
                    base: `/_internal_ext/${extension.technicalName}/`,
                    plugins: scopedPlugins,
                    resolve: {
                        alias: [
                            { find: /^src\//, replacement: "/src/" },
                            {
                                find: /^vue$/,
                                replacement: path.join(
                                    fixtureRoot,
                                    "vue-global.js",
                                ),
                            },
                        ],
                        preserveSymlinks: true,
                    },
                    cacheDir: path.join(extension.viteRoot, "..", ".tmp/vite"),
                });
                await server.listen();
                servers.push(server);
            }
        } else {
            const server = await createServer({
                ...commonOptions,
                root: adminRoot,
                ...(mode.startsWith("environments-") ? { environments } : {}),
                plugins: buildPluginStack(extensions, extensionPlugins, mode),
                resolve: {
                    alias: [
                        { find: /^src\//, replacement: "/src/" },
                        {
                            find: /^vue$/,
                            replacement: path.join(
                                fixtureRoot,
                                "vue-global.js",
                            ),
                        },
                    ],
                    preserveSymlinks: true,
                },
            });
            await server.listen();
            servers.push(server);
        }

        const serverStartupMs = Math.round(performance.now() - serverStartedAt);
        await sample();
        const transformStartedAt = performance.now();
        let transformedFiles = 0;
        const errors = [];

        for (const extension of extensions) {
            const paths = [
                extension.entry,
                path.join(extension.root, "src/label.ts"),
                ...Array.from({ length: extension.sourceMix.ts }, (_, index) =>
                    path.join(
                        extension.root,
                        "src/modules",
                        `module-${String(index + 1).padStart(2, "0")}.ts`,
                    ),
                ),
                ...Array.from(
                    { length: extension.sourceMix.twig },
                    (_, index) =>
                        path.join(
                            extension.root,
                            "src/views",
                            `view-${String(index + 1).padStart(2, "0")}.html.twig`,
                        ),
                ),
                ...Array.from(
                    { length: extension.sourceMix.scss },
                    (_, index) =>
                        path.join(
                            extension.root,
                            "src/styles",
                            `style-${String(index + 1).padStart(2, "0")}.scss`,
                        ),
                ),
                ...Array.from(
                    { length: extension.sourceMix.json },
                    (_, index) =>
                        path.join(
                            extension.root,
                            "src/data",
                            `config-${String(index + 1).padStart(2, "0")}.json`,
                        ),
                ),
                ...Array.from({ length: extension.sourceMix.vue }, (_, index) =>
                    path.join(
                        extension.root,
                        "src/components",
                        `Component-${String(index + 1).padStart(2, "0")}.vue`,
                    ),
                ),
            ];

            let server;
            let environment;
            if (mode === "per-extension") {
                server = servers[extension.index + 1];
                environment = server.environments.client;
            } else {
                server = servers[0];
                environment = mode.startsWith("environments-")
                    ? server.environments[environmentName(extension)]
                    : server.environments.client;
            }

            for (const filePath of paths) {
                try {
                    const transformed =
                        await environment.transformRequest(filePath);
                    assert.ok(transformed, `failed to transform ${filePath}`);
                    transformedFiles += 1;
                } catch (error) {
                    errors.push({ filePath, error: String(error) });
                    throw error;
                }
            }
        }

        const transformMs = Math.round(performance.now() - transformStartedAt);
        await sample();
        const output = {
            mode,
            status: "ok",
            count,
            appEvery,
            appCount: extensions.filter((extension) => extension.isApp).length,
            sourceProfile,
            nodeVersion: process.version,
            platform: `${process.platform}-${process.arch}`,
            cpuModel: await readCpuModel(),
            cpuCount: os.availableParallelism(),
            pluginSourceFilesPerExtension:
                Object.values(
                    scaleSourceMix(sourceMix, sourceMultiplier),
                ).reduce((sum, value) => sum + value, 0) + 2,
            appSourceFilesPerExtension:
                Object.values(
                    scaleSourceMix(appSourceMix, sourceMultiplier),
                ).reduce((sum, value) => sum + value, 0) + 2,
            sourceMultiplier,
            transformedFiles,
            fixtureFileCount: extensions.reduce(
                (sum, extension) => sum + extension.fixtureStats.files,
                0,
            ),
            fixtureBytes: extensions.reduce(
                (sum, extension) => sum + extension.fixtureStats.bytes,
                0,
            ),
            serverInstances: servers.length,
            listeners: servers.length,
            environments: servers.reduce(
                (sum, server) => sum + Object.keys(server.environments).length,
                0,
            ),
            fixtureReadyMs,
            serverStartupMs,
            transformMs,
            totalMs: Math.round(performance.now() - benchmarkStarted),
            peakTreeRssMiB: Number((peak.rssKb / 1024).toFixed(1)),
            sumProcessHighWaterMiB: Number(
                (peak.highWaterKb / 1024).toFixed(1),
            ),
            peakFileDescriptors: peak.fds,
            peakInotifyWatches: peak.watches,
            peakProcessCount: peak.processCount,
            peakThreads: peak.threads,
            processTreeCpuMs: Math.round(cpuTotalMs),
            processCpuMs: (() => {
                const cpu = process.cpuUsage(startCpu);
                return Math.round((cpu.user + cpu.system) / 1000);
            })(),
            errors,
        };

        console.log(`POC_RESULT ${JSON.stringify(output)}`);
    } finally {
        clearInterval(sampleTimer);
        await Promise.allSettled(servers.map((server) => server.close()));
        await fs.rm(fixtureRoot, { recursive: true, force: true });
    }
}

function median(values) {
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    const value =
        sorted.length % 2 === 0
            ? (sorted[middle - 1] + sorted[middle]) / 2
            : sorted[middle];
    return Number(value.toFixed(2));
}

function aggregateResults(rows) {
    const groups = new Map();
    for (const row of rows) {
        const key = `${row.mode}:${row.count}:${row.appEvery}:${row.sourceProfile}:${row.sourceMultiplier}`;
        const group = groups.get(key) ?? [];
        group.push(row);
        groups.set(key, group);
    }

    return [...groups.values()].map((group) => {
        const validRuns = group.filter((row) => row.status === "ok");
        if (validRuns.length === 0) {
            return { ...group[0], runs: group.length };
        }
        const result = { ...validRuns[0], runs: validRuns.length };
        for (const key of [
            "serverStartupMs",
            "transformMs",
            "totalMs",
            "peakTreeRssMiB",
            "peakFileDescriptors",
            "peakInotifyWatches",
            "peakProcessCount",
            "peakThreads",
            "processTreeCpuMs",
        ]) {
            result[key] = median(validRuns.map((row) => row[key]));
        }
        return result;
    });
}

function csvFor(rows) {
    const columns = [
        "mode",
        "status",
        "nodeVersion",
        "platform",
        "cpuModel",
        "cpuCount",
        "count",
        "appEvery",
        "sourceProfile",
        "appCount",
        "runs",
        "pluginSourceFilesPerExtension",
        "appSourceFilesPerExtension",
        "sourceMultiplier",
        "transformedFiles",
        "fixtureFileCount",
        "fixtureBytes",
        "serverInstances",
        "listeners",
        "environments",
        "serverStartupMs",
        "transformMs",
        "totalMs",
        "peakTreeRssMiB",
        "observedRssAtAbortMiB",
        "reason",
        "peakFileDescriptors",
        "peakInotifyWatches",
        "peakProcessCount",
        "peakThreads",
        "processTreeCpuMs",
    ];
    return [
        columns.join(","),
        ...rows.map((row) => columns.map((column) => row[column]).join(",")),
    ].join("\n");
}

function chartSvg(rows) {
    const metrics = [
        { key: "peakTreeRssMiB", label: "Peak process-tree RSS (MiB)" },
        { key: "processTreeCpuMs", label: "Process-tree CPU (ms)" },
        { key: "serverStartupMs", label: "Server startup (ms)" },
        { key: "peakFileDescriptors", label: "Peak open file descriptors" },
    ];
    const colors = {
        "per-extension": "#d84a4a",
        "environments-default": "#e5a323",
        "environments-no-discovery": "#8f65bb",
        "shared-client": "#2673c8",
    };
    const width = 1200;
    const height = 760;
    const panelWidth = 570;
    const panelHeight = 350;
    const panelMarkup = metrics
        .map((metric, index) => {
            const originX = 30 + (index % 2) * 590;
            const originY = 35 + Math.floor(index / 2) * 370;
            const plotX = originX + 58;
            const plotY = originY + 45;
            const plotWidth = panelWidth - 88;
            const plotHeight = panelHeight - 95;
            const successfulRows = rows.filter((row) => row.status === "ok");
            const values = successfulRows.map((row) => Number(row[metric.key]));
            const maxValue = Math.max(1, ...values) * 1.12;
            const counts = [
                ...new Set(successfulRows.map((row) => Number(row.count))),
            ].sort((a, b) => a - b);
            const xFor = (count) =>
                plotX +
                (counts.length === 1
                    ? plotWidth / 2
                    : (counts.indexOf(count) / (counts.length - 1)) *
                      plotWidth);
            const yFor = (value) =>
                plotY + plotHeight - (value / maxValue) * plotHeight;
            const grid = Array.from({ length: 5 }, (_, tick) => {
                const value = (maxValue * tick) / 4;
                const y = yFor(value);
                return `<line x1="${plotX}" y1="${y}" x2="${plotX + plotWidth}" y2="${y}" stroke="#d9dee7"/><text x="${plotX - 8}" y="${y + 4}" text-anchor="end" font-size="11">${value.toFixed(0)}</text>`;
            }).join("");
            const lines = modes
                .map((mode) => {
                    const points = successfulRows
                        .filter((row) => row.mode === mode)
                        .sort((a, b) => a.count - b.count)
                        .map(
                            (row) =>
                                `${xFor(row.count)},${yFor(row[metric.key])}`,
                        )
                        .join(" ");
                    if (!points) return "";
                    const circles = points
                        .split(" ")
                        .map((point) => {
                            const [x, y] = point.split(",");
                            return `<circle cx="${x}" cy="${y}" r="4" fill="${colors[mode]}"/>`;
                        })
                        .join("");
                    return `<polyline fill="none" stroke="${colors[mode]}" stroke-width="2.5" points="${points}"/>${circles}`;
                })
                .join("");
            const xLabels = counts
                .map(
                    (count) =>
                        `<text x="${xFor(count)}" y="${plotY + plotHeight + 22}" text-anchor="middle" font-size="11">${count}</text>`,
                )
                .join("");
            return `<g><text x="${originX}" y="${originY + 18}" font-size="16" font-weight="bold">${metric.label}</text>${grid}<line x1="${plotX}" y1="${plotY}" x2="${plotX}" y2="${plotY + plotHeight}" stroke="#5b6573"/><line x1="${plotX}" y1="${plotY + plotHeight}" x2="${plotX + plotWidth}" y2="${plotY + plotHeight}" stroke="#5b6573"/>${lines}${xLabels}</g>`;
        })
        .join("");
    const legend = modes
        .map(
            (mode, index) =>
                `<g transform="translate(${40 + index * 190},735)"><line x1="0" y1="0" x2="24" y2="0" stroke="${colors[mode]}" stroke-width="3"/><text x="32" y="4" font-size="13">${mode}</text></g>`,
        )
        .join("");
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="white"/><text x="30" y="22" font-size="12" fill="#606b78">Synthetic Shopware-shaped fixture · median per extension count</text>${panelMarkup}${legend}</svg>`;
}

async function runWorker(
    count,
    mode,
    appEvery,
    sourceProfile,
    sourceMultiplier,
) {
    const maxRssMiB = Number(process.env.POC_MAX_RSS_MIB ?? "4096");
    const timeoutMs = Number(process.env.POC_WORKER_TIMEOUT_MS ?? "180000");
    return new Promise((resolve, reject) => {
        const child = spawn(
            process.execPath,
            [
                scriptPath,
                "--worker=true",
                `--count=${count}`,
                `--mode=${mode}`,
                `--app-every=${appEvery}`,
                `--profile=${sourceProfile}`,
                `--source-multiplier=${sourceMultiplier}`,
            ],
            { cwd: repositoryRoot, stdio: ["ignore", "pipe", "pipe"] },
        );
        let stdout = "";
        let stderr = "";
        let abortReason = null;
        let rssAtAbortMiB = null;
        let monitoring = false;
        const monitor = setInterval(async () => {
            if (monitoring || abortReason || child.exitCode !== null) return;
            monitoring = true;
            try {
                const tree = await sampleProcessTree(child.pid);
                const rssMiB =
                    tree.reduce((sum, item) => sum + item.rssKb, 0) / 1024;
                if (rssMiB > maxRssMiB) {
                    abortReason = `RSS safety ceiling ${maxRssMiB} MiB exceeded at ${rssMiB.toFixed(1)} MiB`;
                    rssAtAbortMiB = Number(rssMiB.toFixed(1));
                    child.kill("SIGTERM");
                    setTimeout(() => child.kill("SIGKILL"), 1500).unref();
                }
            } finally {
                monitoring = false;
            }
        }, 250);
        const timeout = setTimeout(() => {
            if (abortReason || child.exitCode !== null) return;
            abortReason = `Worker timed out after ${timeoutMs} ms`;
            child.kill("SIGTERM");
            setTimeout(() => child.kill("SIGKILL"), 1500).unref();
        }, timeoutMs);
        child.stdout
            .setEncoding("utf8")
            .on("data", (chunk) => (stdout += chunk));
        child.stderr
            .setEncoding("utf8")
            .on("data", (chunk) => (stderr += chunk));
        child.on("error", reject);
        child.on("close", (code) => {
            clearInterval(monitor);
            clearTimeout(timeout);
            const resultLine = stdout
                .split("\n")
                .find((line) => line.startsWith("POC_RESULT "));
            if (code !== 0 || !resultLine) {
                resolve({
                    mode,
                    count,
                    appEvery,
                    status: abortReason ? "aborted" : "failed",
                    reason:
                        abortReason ||
                        stderr.slice(-2000) ||
                        stdout.slice(-2000),
                    observedRssAtAbortMiB: rssAtAbortMiB,
                });
                return;
            }
            resolve(JSON.parse(resultLine.slice("POC_RESULT ".length)));
        });
    });
}

const options = readOptions(process.argv.slice(2));

if (options.worker === "true") {
    await workerRun({
        count: Number(options.count),
        mode: options.mode,
        appEvery: Number(options["app-every"] ?? 0),
        sourceProfile: options.profile ?? "shopware-shaped",
        sourceMultiplier: Number(options["source-multiplier"] ?? 1),
    });
} else {
    const counts = listFromOption(options.counts, "1,5,10,20,40").map(Number);
    const selectedModes = listFromOption(options.modes, modes.join(","));
    const runs = Number(options.runs ?? 1);
    const appEvery = Number(options["app-every"] ?? 0);
    const sourceProfile = options.profile ?? "shopware-shaped";
    const sourceMultiplier = Number(options["source-multiplier"] ?? 1);
    const outputPath = path.resolve(
        repositoryRoot,
        options.output ?? "dev-tools/admin-vite-environment-poc/results/latest",
    );
    assert.ok(counts.every((count) => Number.isInteger(count) && count > 0));
    assert.ok(selectedModes.every((mode) => enabledModes.has(mode)));
    assert.ok(Number.isInteger(runs) && runs > 0);
    assert.ok(Number.isInteger(sourceMultiplier) && sourceMultiplier > 0);
    const results =
        options.append === "true" || options["summary-only"] === "true"
            ? JSON.parse(
                  await fs
                      .readFile(path.join(outputPath, "raw.json"), "utf8")
                      .catch(() => "[]"),
              )
            : [];
    await fs.mkdir(outputPath, { recursive: true });

    async function saveRawResults() {
        await fs.writeFile(
            path.join(outputPath, "raw.json"),
            JSON.stringify(results, null, 2),
        );
    }

    for (const count of options["summary-only"] === "true" ? [] : counts) {
        for (const mode of selectedModes) {
            for (let run = 1; run <= runs; run += 1) {
                process.stderr.write(
                    `Benchmarking ${mode}, ${count} extensions, run ${run}/${runs}\n`,
                );
                const result = await runWorker(
                    count,
                    mode,
                    appEvery,
                    sourceProfile,
                    sourceMultiplier,
                );
                results.push({ ...result, run });
                await saveRawResults();
                if (result.status !== "ok") {
                    process.stderr.write(
                        `  ${result.status}: ${result.reason ?? "worker failed"}\n`,
                    );
                }
            }
        }
    }

    const summary = aggregateResults(results);
    await saveRawResults();
    await fs.writeFile(
        path.join(outputPath, "summary.csv"),
        `${csvFor(summary)}\n`,
    );
    await fs.writeFile(path.join(outputPath, "graphs.svg"), chartSvg(summary));
    await fs.writeFile(
        path.join(outputPath, "README.md"),
        `# Benchmark results\n\nThese measurements were generated on ${new Date().toISOString()} with Vite ${vite8Require("vite/package.json").version}.\n\nThe fixture uses the ${sourceProfile} profile at a ${sourceMultiplier}x source multiplier and models ${fixtureSourceMix()} per plugin extension.\n\nThe runs record process-tree RSS, CPU time, file descriptors, inotify watches, threads, server startup time, and module transform time.\n\nA worker is stopped if process-tree RSS exceeds ${process.env.POC_MAX_RSS_MIB ?? "4096"} MiB or it runs longer than ${process.env.POC_WORKER_TIMEOUT_MS ?? "180000"} ms.\n\nThese results do not include a browser process, customer extension configs, or a booted Shopware runtime.\n\nThe raw observations are in [raw.json](raw.json), the median summary is in [summary.csv](summary.csv), and the plots are in [graphs.svg](graphs.svg).\n`,
    );
    process.stdout.write(
        `Wrote ${results.length} benchmark runs to ${path.relative(repositoryRoot, outputPath)}\n`,
    );
}
