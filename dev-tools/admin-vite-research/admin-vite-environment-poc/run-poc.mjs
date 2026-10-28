import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { createRequire } from "node:module";
import { pathToFileURL } from "node:url";
import { performance } from "node:perf_hooks";

const repositoryRoot = path.resolve(import.meta.dirname, "../..");
const administrationRoot = path.join(
    repositoryRoot,
    "src/Administration/Resources/app/administration",
);
const vite8Package = path.join(administrationRoot, "build/vite-8/package.json");
const vite8Require = createRequire(vite8Package);
const viteEntry = vite8Require.resolve("vite");
const { createServer } = await import(pathToFileURL(viteEntry).href);
const fixtureRoot = await fs.mkdtemp(
    path.join(os.tmpdir(), "shopware-admin-vite-env-poc-"),
);
const adminRoot = path.join(
    fixtureRoot,
    "src/Administration/Resources/app/administration",
);
const extensionCount = Number(process.argv[2] ?? 2);
const mode = process.argv[3] ?? "environments";

assert.ok(
    Number.isInteger(extensionCount) && extensionCount > 0,
    "pass a positive extension count",
);
assert.ok(
    ["environments", "shared-client"].includes(mode),
    "mode must be environments or shared-client",
);

const extensions = Array.from({ length: extensionCount }, (_, index) => {
    const number = String(index + 1).padStart(2, "0");
    const folderName = `Plugin${number}`;

    return {
        name: `plugin-${number}`,
        folderName,
        root: path.join(
            fixtureRoot,
            "custom/plugins",
            folderName,
            "src/Resources/app/administration",
        ),
        label: `plugin-${number}-label`,
    };
});

async function writeFixture(filePath, contents) {
    await fs.mkdir(path.dirname(filePath), { recursive: true });
    await fs.writeFile(filePath, contents);
}

async function createFixtures() {
    const extensionImports = extensions
        .map(
            (extension, index) =>
                `import { extension as extension${index} } from "/_extensions/${extension.name}/src/main.ts";`,
        )
        .join("\n");

    await writeFixture(
        path.join(adminRoot, "index.html"),
        '<!doctype html><html><head><link rel="icon" href="data:,"></head><body><div id="app"></div><script type="module" src="/src/main.ts"></script></body></html>',
    );
    await writeFixture(
        path.join(adminRoot, "src/main.ts"),
        `export const administration = "administration-entry";\n${extensionImports}\nexport const loadedExtensions = [${extensions.map((_, index) => `extension${index}`).join(", ")}];\nwindow.__pocReloadCount = (window.__pocReloadCount || 0) + 1;\nconst app = document.querySelector("#app");\napp.innerHTML = '<p id="reload-count">reloads:' + window.__pocReloadCount + '</p>' + loadedExtensions.map((_, index) => '<p data-extension="plugin-' + String(index + 1).padStart(2, "0") + '"></p>').join("");\nloadedExtensions.forEach((extension, index) => extension.mount(app.querySelector('[data-extension="plugin-' + String(index + 1).padStart(2, "0") + '"]')));`,
    );

    for (const extension of extensions) {
        const extensionImports = [
            'import label from "@extension-label";',
            'import Example from "./components/Example.vue";',
            'import "./styles/plugin.scss";',
            'import configLabel from "./config-probe.ts";',
            'const globModules = import.meta.glob("./modules/*.ts", { eager: true, import: "default" });',
        ].join("\n");

        await writeFixture(
            path.join(extension.root, "src/main.ts"),
            `${extensionImports}\nexport const extension = { label, mount(element) { element.textContent = label; element.dataset.vue = Example.__name; element.dataset.glob = Object.values(globModules).join(","); element.dataset.config = configLabel; } };\nif (import.meta.hot) import.meta.hot.accept((module) => module?.extension.mount(document.querySelector('[data-extension="${extension.name}"]')));`,
        );
        await writeFixture(
            path.join(extension.root, "src/label.ts"),
            `export default "${extension.label}";`,
        );
        await writeFixture(
            path.join(extension.root, "src/config-probe.ts"),
            'export default "config-not-transformed";',
        );
        await writeFixture(
            path.join(extension.root, "src/modules/feature.ts"),
            `export default "${extension.name}-glob-loaded";`,
        );
        await writeFixture(
            path.join(extension.root, "src/components/Example.vue"),
            `<script setup>import { ref } from "vue"; const label = ref("${extension.name}-vue");</script><template><span>{{ label }}</span></template>`,
        );
        await writeFixture(
            path.join(extension.root, "src/styles/plugin.scss"),
            `[data-extension="${extension.name}"] { --fixture-color: #${String(indexColor(extension.name)).padStart(6, "0")}; }`,
        );
        await writeFixture(
            path.join(extension.root, "vite.config.mjs"),
            `const sourceFile = ${JSON.stringify(path.join(extension.root, "src/config-probe.ts"))};\nexport default { plugins: [{ name: "fixture-${extension.name}-config", transform(code, id) { if (id !== sourceFile) return null; return { code: ${JSON.stringify(`export default "${extension.name}-config-loaded";`)}, map: null }; } }] };`,
        );
    }

    await fs.mkdir(path.join(fixtureRoot, "node_modules"), { recursive: true });
    await fs.symlink(
        path.join(administrationRoot, "node_modules/vue"),
        path.join(fixtureRoot, "node_modules/vue"),
        "dir",
    );
    await fs.symlink(
        path.join(administrationRoot, "node_modules/sass"),
        path.join(fixtureRoot, "node_modules/sass"),
        "dir",
    );
}

function indexColor(extensionName) {
    return [...extensionName].reduce(
        (value, character) => value + character.charCodeAt(0),
        0,
    );
}

async function loadExtensionVitePlugins() {
    const configs = await Promise.all(
        extensions.map(async (extension) => {
            const configPath = path.join(extension.root, "vite.config.mjs");
            const config = await import(pathToFileURL(configPath).href);
            return config.default;
        }),
    );

    return configs.flatMap((config) => config.plugins ?? []);
}

async function writeHotUpdateFixture() {
    const extension = extensions[0];
    const contents = `import label from "@extension-label";\nimport Example from "./components/Example.vue";\nimport "./styles/plugin.scss";\nimport configLabel from "./config-probe.ts";\nconst globModules = import.meta.glob("./modules/*.ts", { eager: true, import: "default" });\nexport const extension = { label: label + "-updated", mount(element) { element.textContent = label + "-updated"; element.dataset.vue = Example.__name; element.dataset.glob = Object.values(globModules).join(","); element.dataset.config = configLabel; } };\nif (import.meta.hot) import.meta.hot.accept((module) => module?.extension.mount(document.querySelector('[data-extension="${extension.name}"]')));`;
    await fs.writeFile(path.join(extension.root, "src/main.ts"), contents);
}

function toEnvironmentName(extensionName) {
    return `extension_${extensionName.replaceAll("-", "_")}`;
}

const environments = Object.fromEntries(
    extensions.map((extension) => [
        toEnvironmentName(extension.name),
        {
            consumer: "client",
            optimizeDeps: {
                noDiscovery: true,
                include: [],
            },
        },
    ]),
);

function fixtureRoutingPlugin() {
    return {
        name: "shopware-fixture-extension-routing",

        configureServer(devServer) {
            devServer.middlewares.use(
                "/__poc__/edit-first",
                async (request, response, next) => {
                    if (request.method !== "POST") {
                        next();
                        return;
                    }

                    await writeHotUpdateFixture();
                    response.statusCode = 204;
                    response.end();
                },
            );
        },

        resolveId(source, importer) {
            if (source === "@extension-label" && importer) {
                const extension = extensions.find((candidate) =>
                    importer.startsWith(candidate.root),
                );

                if (extension) {
                    assert.ok(
                        this.environment.name === "client" ||
                            this.environment.name ===
                                toEnvironmentName(extension.name),
                        `unexpected ${this.environment.name} transform for ${extension.name}`,
                    );
                    return path.join(extension.root, "src/label.ts");
                }
            }

            const match = source.match(/^\/_extensions\/([^/]+)\/(.+)$/);

            if (!match) {
                return null;
            }

            const [, extensionName, relativeFile] = match;
            const extension = extensions.find(
                (candidate) => candidate.name === extensionName,
            );

            if (!extension) {
                throw new Error(`Unknown fixture extension "${extensionName}"`);
            }

            return path.resolve(extension.root, relativeFile);
        },
    };
}

let server;

try {
    await createFixtures();

    const startedAt = performance.now();
    const extensionPlugins =
        mode === "shared-client" ? await loadExtensionVitePlugins() : [];
    const vuePlugin = vite8Require("@vitejs/plugin-vue").default;

    server = await createServer({
        configFile: false,
        root: adminRoot,
        appType: "mpa",
        ...(mode === "environments" ? { environments } : {}),
        plugins: [
            ...(vuePlugin ? [vuePlugin()] : []),
            ...extensionPlugins,
            fixtureRoutingPlugin(),
        ],
        optimizeDeps: {
            noDiscovery: true,
            include: [],
        },
        server: {
            host: process.env.POC_HOST ?? "127.0.0.1",
            port: Number(process.env.POC_PORT ?? 0),
            strictPort: false,
            open: false,
            fs: {
                allow: [fixtureRoot, administrationRoot],
            },
        },
    });

    await server.listen();
    const startupMs = Math.round(performance.now() - startedAt);
    const address = server.httpServer.address();
    assert.ok(
        address && typeof address === "object",
        "Vite should have one listening HTTP server",
    );
    assert.equal(
        Object.keys(server.environments).length,
        mode === "environments" ? extensions.length + 2 : 2,
        "expected client and SSR, plus optional extension environments",
    );

    const transformResults = [];

    for (const extension of extensions) {
        const entryUrl = `/_extensions/${extension.name}/src/main.ts`;
        let environment;
        let transformed;
        let transformedLabel;

        if (mode === "environments") {
            environment =
                server.environments[toEnvironmentName(extension.name)];
            transformed = await environment.transformRequest(entryUrl);
            transformedLabel = await environment.transformRequest(
                path.join(extension.root, "src/label.ts"),
            );
        } else {
            environment = server.environments.client;
            const response = await fetch(
                `http://127.0.0.1:${address.port}${entryUrl}`,
            );
            assert.equal(
                response.status,
                200,
                `${extension.name} client module should be served by the shared server`,
            );
            transformed = { code: await response.text() };
            transformedLabel = await environment.transformRequest(
                path.join(extension.root, "src/label.ts"),
            );
        }

        assert.ok(transformed, `${extension.name} entry should transform`);
        assert.ok(
            transformedLabel,
            `${extension.name} label module should transform`,
        );
        if (mode === "environments") {
            assert.ok(
                transformed.code.includes(
                    `/custom/plugins/${extension.folderName}/`,
                ),
                `${extension.name} environment should resolve its own source`,
            );
        }
        assert.match(transformedLabel.code, new RegExp(extension.label));

        const labelPath = path.join(extension.root, "src/label.ts");
        const alias = await environment.pluginContainer.resolveId(
            "@extension-label",
            path.join(extension.root, "src/main.ts"),
        );
        assert.equal(
            alias?.id,
            labelPath,
            `${extension.name} alias should resolve inside its own root`,
        );

        transformResults.push({
            environment: environment.name,
            entry: extension.name,
            alias: alias.id,
        });
    }

    const htmlResponse = await fetch(`http://127.0.0.1:${address.port}/`);
    assert.equal(
        htmlResponse.status,
        200,
        "the single server should serve the Administration fixture",
    );
    const transformSamples =
        transformResults.length > 4
            ? [...transformResults.slice(0, 2), transformResults.at(-1)]
            : transformResults;

    console.log(
        JSON.stringify(
            {
                viteVersion: vite8Require("vite/package.json").version,
                mode,
                extensionCount,
                serverInstances: 1,
                listeners: 1,
                port: address.port,
                environments: Object.keys(server.environments),
                transformedExtensions: transformResults.length,
                extensionTransformSamples: transformSamples,
                startupMs,
                totalMs: Math.round(performance.now() - startedAt),
            },
            null,
            2,
        ),
    );

    const holdMs = Number(process.env.POC_HOLD_MS ?? 0);

    if (holdMs > 0) {
        await new Promise((resolve) => setTimeout(resolve, holdMs));
    }
} finally {
    if (server) {
        await server.close();
    }

    await fs.rm(fixtureRoot, { recursive: true, force: true });
}
