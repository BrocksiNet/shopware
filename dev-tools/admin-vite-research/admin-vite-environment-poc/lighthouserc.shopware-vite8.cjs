const { resolve } = require("node:path");

const resultDirectory = resolve(
    __dirname,
    "results/shopware-vite8-40-extensions-2026-10-27/lighthouse-vite8-unbundled",
);

module.exports = {
    ci: {
        collect: {
            url: ["http://127.0.0.1:5173/#/sw/product/index"],
            numberOfRuns: 3,
            puppeteerScript: resolve(
                __dirname,
                "../../src/Administration/Resources/app/administration/lighthouse-auth.js",
            ),
            puppeteerLaunchOptions: {
                args: ["--no-sandbox", "--disable-setuid-sandbox"],
                userDataDir: resolve(
                    __dirname,
                    "../../src/Administration/Resources/app/administration/.lighthouseci/shopware-vite-poc-profile",
                ),
            },
            settings: {
                preset: "desktop",
                throttling: {
                    cpuSlowdownMultiplier: 1,
                },
                skipAudits: [
                    "is-crawlable",
                    "robots-txt",
                    "canonical",
                    "structured-data",
                    "is-on-https",
                    "redirects-http",
                ],
            },
        },
        upload: {
            target: "filesystem",
            outputDir: resultDirectory,
        },
    },
};
