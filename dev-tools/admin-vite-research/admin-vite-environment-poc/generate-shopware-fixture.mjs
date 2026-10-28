import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";

const projectRoot = path.resolve(import.meta.dirname, "../..");
const pluginsRoot = path.join(projectRoot, "custom/plugins");
const marker = ".shopware-vite-poc-fixture";

function readOptions(args) {
    const options = {};
    for (const argument of args) {
        const [key, value] = argument.replace(/^--/, "").split("=");
        options[key] = value ?? "true";
    }
    return options;
}

function pluginName(index) {
    return `VitePocPlugin${String(index).padStart(2, "0")}`;
}

function behavioralTemplate(index, name) {
    const fixtures = {
        1: {
            component: "sw-dashboard-index",
            block: "sw_dashboard_index_content_info_grid_inner",
            markup: `<article class="sw-dashboard-index__card" data-vite-poc-extension="${name}"><h2>${name}</h2><p>Dashboard Twig extension: edit this text to test HMR.</p></article>`,
        },
        2: {
            component: "sw-manufacturer-detail",
            block: "sw_manufacturer_detail_content_language_info",
            markup: `<p class="vite-poc-extension-note" data-vite-poc-extension="${name}">${name}: manufacturer detail Twig extension.</p>`,
        },
        3: {
            component: "sw-product-detail",
            block: "sw_product_detail_content_language_info",
            markup: `<p class="vite-poc-extension-note" data-vite-poc-extension="${name}">${name}: product detail Twig extension.</p>`,
        },
        4: {
            component: "sw-order-detail",
            block: "sw_order_detail_content_view",
            markup: `<p class="vite-poc-extension-note" data-vite-poc-extension="${name}">${name}: order detail Twig extension.</p>`,
        },
    };
    const fixture = fixtures[index];
    if (!fixture) return null;

    return {
        ...fixture,
        template: `{% block ${fixture.block} %}{% parent %}${fixture.markup}{% endblock %}`,
    };
}

async function writeFile(filePath, contents) {
    await fs.mkdir(path.dirname(filePath), { recursive: true });
    await fs.writeFile(filePath, contents);
}

async function generate(count, sourceMultiplier) {
    await fs.mkdir(pluginsRoot, { recursive: true });

    for (let index = 1; index <= count; index += 1) {
        const name = pluginName(index);
        const behavioral = behavioralTemplate(index, name);
        const pluginRoot = path.join(pluginsRoot, name);
        const markerPath = path.join(pluginRoot, marker);
        try {
            await fs.access(pluginRoot);
            await fs.access(markerPath);
        } catch {
            try {
                await fs.access(pluginRoot);
                throw new Error(
                    `${pluginRoot} exists without the fixture marker; refusing to overwrite it.`,
                );
            } catch (error) {
                if (
                    error.message.includes("exists without the fixture marker")
                ) {
                    throw error;
                }
            }
        }

        await fs.mkdir(pluginRoot, { recursive: true });
        const namespace = `VitePoc\\${name}\\`;
        const entryDir = path.join(
            pluginRoot,
            "src/Resources/app/administration/src",
        );
        const moduleCount = 36 * sourceMultiplier;
        const twigCount = 14 * sourceMultiplier;
        const scssCount = 6 * sourceMultiplier;
        const jsonCount = 4 * sourceMultiplier;
        const vueCount = 2 * sourceMultiplier;
        const imports = [
            'import label from "./label.ts";',
            'import.meta.glob("./modules/*.ts", { eager: true });',
            'import.meta.glob(["./views/*.twig", "!./views/view-01.twig"], { eager: true, import: "default" });',
            'import.meta.glob("./data/*.json", { eager: true });',
            'import.meta.glob("./components/*.vue", { eager: true });',
            ...Array.from(
                { length: scssCount },
                (_, styleIndex) =>
                    `import "./styles/style-${String(styleIndex + 1).padStart(2, "0")}.scss";`,
            ),
        ];
        const componentRegistration = behavioral
            ? `Shopware.Component.override("${behavioral.component}", () => import("./components/override"));\n`
            : "";
        const moduleHmrBoundary = behavioral
            ? ""
            : "if (import.meta.hot) import.meta.hot.accept();\n";

        await writeFile(
            path.join(pluginRoot, "composer.json"),
            `${JSON.stringify(
                {
                    name: `vite-poc/${name.toLowerCase()}`,
                    description: `Generated Administration HMR fixture ${name}.`,
                    type: "shopware-platform-plugin",
                    license: "MIT",
                    autoload: { "psr-4": { [namespace]: "src/" } },
                    extra: {
                        "shopware-plugin-class": `${namespace}${name}`,
                        label: { "en-GB": name, "de-DE": name },
                    },
                },
                null,
                4,
            )}\n`,
        );
        await writeFile(
            markerPath,
            "Generated by generate-shopware-fixture.mjs.\n",
        );
        await writeFile(
            path.join(pluginRoot, "src", `${name}.php`),
            `<?php declare(strict_types=1);\n\nnamespace ${namespace.slice(0, -1)};\n\nuse Shopware\\Core\\Framework\\Plugin;\n\nclass ${name} extends Plugin\n{\n}\n`,
        );
        await writeFile(
            path.join(entryDir, "main.ts"),
            `${imports.join("\n")}\n${componentRegistration}export const fixture = { label, plugin: "${name}" };\n${moduleHmrBoundary}`,
        );
        if (behavioral) {
            await writeFile(
                path.join(entryDir, "components", "override.ts"),
                `import template from "../views/view-01.twig";\nexport default Shopware.Component.wrapComponentConfig({ template });\n`,
            );
        }
        if (index === 1) {
            await writeFile(
                path.join(entryDir, "sw-dashboard-index.override.vue"),
                `<template>\n    <sw-block extends="sw_dashboard_index_content_intro_welcome_message">\n        <button class="vite-poc-native-note" type="button" @click="nativeHmrCount++">{{ nativeHmrText }} {{ nativeHmrCount }}: edit this text to test HMR.</button>\n    </sw-block>\n</template>\n\n<script setup lang="ts">\nimport { ref } from 'vue';\n\nconst nativeHmrText = 'Native SFC state counter';\nconst nativeHmrCount = ref(0);\nswDefineOverride({ nativeHmrText, nativeHmrCount });\n</script>\n`,
            );
        }
        await writeFile(
            path.join(entryDir, "label.ts"),
            `export default "${name}";\n`,
        );

        for (
            let moduleIndex = 1;
            moduleIndex <= moduleCount;
            moduleIndex += 1
        ) {
            await writeFile(
                path.join(
                    entryDir,
                    "modules",
                    `module-${String(moduleIndex).padStart(2, "0")}.ts`,
                ),
                `export default Object.freeze({ plugin: "${name}", index: ${moduleIndex}, payload: "${"x".repeat(4096)}" });\n`,
            );
        }

        for (let viewIndex = 1; viewIndex <= twigCount; viewIndex += 1) {
            const template =
                viewIndex === 1 && behavioral
                    ? behavioral.template
                    : `<section data-extension="${name}"><h2>Administration module ${viewIndex}</h2><p>${"Twig fixture content ".repeat(125)}</p></section>`;
            await writeFile(
                path.join(
                    entryDir,
                    "views",
                    `view-${String(viewIndex).padStart(2, "0")}.twig`,
                ),
                `${template}\n`,
            );
        }

        for (let styleIndex = 1; styleIndex <= scssCount; styleIndex += 1) {
            const selectors = Array.from(
                { length: 16 },
                (_, rule) =>
                    `.view-${styleIndex}-${rule} { padding: ${rule + 1}px; margin: ${rule}px; color: #123456; }`,
            ).join("\n");
            await writeFile(
                path.join(
                    entryDir,
                    "styles",
                    `style-${String(styleIndex).padStart(2, "0")}.scss`,
                ),
                `$spacing: ${styleIndex}px;\n[data-extension="${name}"] { ${selectors} }\n`,
            );
        }

        for (let dataIndex = 1; dataIndex <= jsonCount; dataIndex += 1) {
            await writeFile(
                path.join(
                    entryDir,
                    "data",
                    `config-${String(dataIndex).padStart(2, "0")}.json`,
                ),
                `${JSON.stringify({
                    name,
                    index: dataIndex,
                    enabled: true,
                    featureFlags: Array.from({ length: 80 }, (_, flag) => ({
                        name: `feature-${flag}`,
                        enabled: flag % 2 === 0,
                    })),
                })}\n`,
            );
        }

        for (
            let componentIndex = 1;
            componentIndex <= vueCount;
            componentIndex += 1
        ) {
            await writeFile(
                path.join(
                    entryDir,
                    "components",
                    `Component-${String(componentIndex).padStart(2, "0")}.vue`,
                ),
                `<script setup>import { ref } from "vue"; const title = ref("${name}-${componentIndex}"); const data = "${"x".repeat(1024)}"; swDefinePublic({});</script><template><section><h2>{{ title }}</h2><p>${"Component fixture content ".repeat(80)}</p></section></template>\n`,
            );
        }
    }

    console.log(
        `Generated ${count} Shopware-discoverable plugin fixtures in ${pluginsRoot}.`,
    );
    console.log(
        "Each generated directory has a marker file; remove only those marked directories after the experiment.",
    );
}

async function clean() {
    const entries = await fs.readdir(pluginsRoot, { withFileTypes: true });
    let removed = 0;
    for (const entry of entries) {
        if (!entry.isDirectory() || !entry.name.startsWith("VitePocPlugin")) {
            continue;
        }
        const pluginRoot = path.join(pluginsRoot, entry.name);
        try {
            await fs.access(path.join(pluginRoot, marker));
        } catch {
            continue;
        }
        await fs.rm(pluginRoot, { recursive: true, force: true });
        removed += 1;
    }
    console.log(`Removed ${removed} marked Shopware Vite POC plugin fixtures.`);
}

const options = readOptions(process.argv.slice(2));
if (options.clean === "true") {
    await clean();
} else {
    const count = Number.parseInt(options.count ?? "40", 10);
    const sourceMultiplier = Number.parseInt(
        options["source-multiplier"] ?? "1",
        10,
    );
    if (!Number.isInteger(count) || count < 1 || count > 40) {
        throw new Error("--count must be between 1 and 40.");
    }
    if (
        !Number.isInteger(sourceMultiplier) ||
        sourceMultiplier < 1 ||
        sourceMultiplier > 4
    ) {
        throw new Error("--source-multiplier must be between 1 and 4.");
    }
    await generate(count, sourceMultiplier);
}
