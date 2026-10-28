import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { spawn } from "node:child_process";
import process from "node:process";
import { setTimeout as delay } from "node:timers/promises";

const projectRoot = path.resolve(import.meta.dirname, "../..");
const administrationRoot = path.join(
    projectRoot,
    "src/Administration/Resources/app/administration",
);
const date = new Date().toISOString().slice(0, 10);
const outputRoot = path.resolve(
    projectRoot,
    process.env.VITE_POC_OUTPUT ??
        `dev-tools/admin-vite-environment-poc/results/shopware-vite8-40-extensions-${date}`,
);
const durationMs = Number.parseInt(
    process.env.VITE_POC_SAMPLE_MS ?? "20000",
    10,
);
const intervalMs = Number.parseInt(
    process.env.VITE_POC_SAMPLE_INTERVAL_MS ?? "500",
    10,
);
const safetyLimitBytes = 4 * 1024 * 1024 * 1024;
const logs = [];
await fs.mkdir(outputRoot, { recursive: true });
const watcherLogPath = path.join(outputRoot, "watcher.log");
await fs.writeFile(watcherLogPath, "");

function parseStat(stat) {
    const closeParen = stat.lastIndexOf(")");
    const fields = stat
        .slice(closeParen + 2)
        .trim()
        .split(/\s+/);
    return {
        parentPid: Number(fields[1]),
        cpuTicks: Number(fields[11]) + Number(fields[12]),
    };
}

async function readProcess(pid) {
    try {
        const [status, stat, cmdline, descriptors, tasks] = await Promise.all([
            fs.readFile(`/proc/${pid}/status`, "utf8"),
            fs.readFile(`/proc/${pid}/stat`, "utf8"),
            fs.readFile(`/proc/${pid}/cmdline`, "utf8"),
            fs.readdir(`/proc/${pid}/fd`).catch(() => []),
            fs.readdir(`/proc/${pid}/task`).catch(() => []),
        ]);
        const parent = parseStat(stat);
        const rssKb = Number(status.match(/^VmRSS:\s+(\d+)/m)?.[1] ?? 0);
        let inotifyWatches = 0;
        for (const descriptor of descriptors) {
            const info = await fs
                .readFile(`/proc/${pid}/fdinfo/${descriptor}`, "utf8")
                .catch(() => "");
            inotifyWatches += (info.match(/^inotify wd:/gm) ?? []).length;
        }
        return {
            pid,
            parentPid: parent.parentPid,
            cpuTicks: parent.cpuTicks,
            rssBytes: rssKb * 1024,
            descriptors: descriptors.length,
            threads: tasks.length,
            inotifyWatches,
            cmdline: cmdline.replaceAll("\0", " ").trim(),
        };
    } catch {
        return null;
    }
}

async function processTree() {
    const pids = (await fs.readdir("/proc"))
        .filter((entry) => /^\d+$/.test(entry))
        .map(Number);
    const processes = (await Promise.all(pids.map(readProcess))).filter(
        Boolean,
    );
    const root = processes.find((item) => item.cmdline.includes("npm run dev"));
    if (!root) return [];

    const result = [];
    const queue = [root.pid];
    const visited = new Set(queue);
    while (queue.length > 0) {
        const current = queue.shift();
        result.push(processes.find((item) => item.pid === current));
        for (const child of processes) {
            if (child.parentPid === current && !visited.has(child.pid)) {
                visited.add(child.pid);
                queue.push(child.pid);
            }
        }
    }
    for (const item of processes) {
        if (item.cmdline.includes("@esbuild/") && !visited.has(item.pid))
            result.push(item);
    }
    return result.filter(Boolean);
}

async function readCgroup() {
    const memory = Number(
        await fs
            .readFile("/sys/fs/cgroup/memory.current", "utf8")
            .catch(() => "0"),
    );
    const cpuStat = await fs
        .readFile("/sys/fs/cgroup/cpu.stat", "utf8")
        .catch(() => "");
    const usageUsec = Number(cpuStat.match(/^usage_usec\s+(\d+)/m)?.[1] ?? 0);
    return { memoryBytes: memory, cpuUsec: usageUsec };
}

async function listenerCount() {
    const files = await Promise.all(
        ["/proc/net/tcp", "/proc/net/tcp6"].map((file) =>
            fs.readFile(file, "utf8").catch(() => ""),
        ),
    );
    const ports = new Set();
    for (const contents of files) {
        for (const row of contents.split("\n").slice(1)) {
            const fields = row.trim().split(/\s+/);
            if (fields[3] !== "0A") continue;
            const port = Number.parseInt(fields[1].split(":").at(-1), 16);
            if (port >= 5173 && port <= 5300) ports.add(port);
        }
    }
    return ports.size;
}

async function sample(elapsedMs, previousCgroup) {
    const [cgroup, tree, listeners] = await Promise.all([
        readCgroup(),
        processTree(),
        listenerCount(),
    ]);
    const treeCpuTicks = tree.reduce((total, item) => total + item.cpuTicks, 0);
    const treeRssBytes = tree.reduce((total, item) => total + item.rssBytes, 0);
    const treeCpuPercent = previousCgroup
        ? (treeCpuTicks - previousCgroup.treeCpuTicks) /
          Math.max((elapsedMs - previousCgroup.elapsedMs) / 1000, 0.001)
        : 0;
    const containerCpuPercent = previousCgroup
        ? ((cgroup.cpuUsec - previousCgroup.cpuUsec) /
              Math.max((elapsedMs - previousCgroup.elapsedMs) * 1000, 1)) *
          100
        : 0;
    return {
        elapsedMs,
        containerRssMiB: cgroup.memoryBytes / 1024 / 1024,
        containerCpuPercent,
        viteTreeRssMiB: treeRssBytes / 1024 / 1024,
        viteTreeCpuTicksPerSecond: treeCpuPercent,
        processCount: tree.length,
        openFds: tree.reduce((total, item) => total + item.descriptors, 0),
        threads: tree.reduce((total, item) => total + item.threads, 0),
        inotifyWatches: tree.reduce(
            (total, item) => total + item.inotifyWatches,
            0,
        ),
        listeners,
        cgroupCpuUsec: cgroup.cpuUsec,
        treeCpuTicks,
    };
}

function svgChart(title, key, color, samples, top, units) {
    const width = 820;
    const height = 230;
    const left = 68;
    const right = 20;
    const topMargin = 38;
    const bottom = 35;
    const plotWidth = width - left - right;
    const plotHeight = height - topMargin - bottom;
    const values = samples.map((item) => item[key]);
    const max = Math.max(top ?? 0, ...values, 1);
    const points = values
        .map((value, index) => {
            const x =
                left + (index / Math.max(values.length - 1, 1)) * plotWidth;
            const y = topMargin + plotHeight - (value / max) * plotHeight;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(" ");
    return `<g><text x="${left}" y="22" class="title">${title}</text><line x1="${left}" y1="${topMargin + plotHeight}" x2="${left + plotWidth}" y2="${topMargin + plotHeight}" class="axis"/><line x1="${left}" y1="${topMargin}" x2="${left}" y2="${topMargin + plotHeight}" class="axis"/><polyline points="${points}" fill="none" stroke="${color}" stroke-width="2.4"/><text x="8" y="${topMargin + 8}" class="label">${max.toFixed(0)} ${units}</text><text x="${left}" y="${height - 9}" class="label">0s</text><text x="${left + plotWidth}" y="${height - 9}" text-anchor="end" class="label">${(samples.at(-1).elapsedMs / 1000).toFixed(0)}s</text></g>`;
}

function makeSvg(samples) {
    const width = 860;
    const height = 1450;
    const charts = [
        svgChart(
            "Shopware web container RSS",
            "containerRssMiB",
            "#155eef",
            samples,
            undefined,
            "MiB",
        ),
        svgChart(
            "Vite watcher process tree RSS",
            "viteTreeRssMiB",
            "#7f56d9",
            samples,
            undefined,
            "MiB",
        ),
        svgChart(
            "Container CPU",
            "containerCpuPercent",
            "#079455",
            samples,
            undefined,
            "%",
        ),
        svgChart(
            "Vite listener count",
            "listeners",
            "#e04f16",
            samples,
            undefined,
            "listeners",
        ),
        svgChart(
            "Vite process-tree open file descriptors",
            "openFds",
            "#b54708",
            samples,
            undefined,
            "fds",
        ),
        svgChart(
            "Vite process-tree inotify watches",
            "inotifyWatches",
            "#c11574",
            samples,
            undefined,
            "watches",
        ),
    ];
    const placements = charts
        .map(
            (chart, index) =>
                `<g transform="translate(20 ${20 + index * 235})">${chart}</g>`,
        )
        .join("\n");
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><style>text{font-family:Inter,Arial,sans-serif;fill:#344054}.title{font-size:15px;font-weight:600}.label{font-size:11px}.axis{stroke:#d0d5dd;stroke-width:1}</style><rect width="100%" height="100%" fill="#fff"/>${placements}</svg>`;
}

function median(values) {
    const sorted = [...values].sort((a, b) => a - b);
    return sorted[Math.floor(sorted.length / 2)];
}

async function runVerification() {
    return new Promise((resolve, reject) => {
        const verifier = spawn(
            process.execPath,
            [
                "dev-tools/admin-vite-environment-poc/verify-shopware-fixture.mjs",
                "--all-graphs",
                "--hmr",
            ],
            {
                cwd: projectRoot,
                env: process.env,
                stdio: ["ignore", "pipe", "pipe"],
            },
        );
        let output = "";
        let peakMemoryMiB = 0;
        let safetyLimitHit = false;
        const monitor = setInterval(() => {
            void readCgroup().then(({ memoryBytes }) => {
                peakMemoryMiB = Math.max(
                    peakMemoryMiB,
                    memoryBytes / 1024 / 1024,
                );
                if (memoryBytes > safetyLimitBytes && !safetyLimitHit) {
                    safetyLimitHit = true;
                    verifier.kill("SIGTERM");
                }
            });
        }, 500);
        const timeout = setTimeout(() => verifier.kill("SIGTERM"), 120_000);
        verifier.stdout.on("data", (chunk) => {
            output += chunk.toString();
        });
        verifier.stderr.on("data", (chunk) => {
            output += chunk.toString();
        });
        verifier.once("error", reject);
        verifier.once("close", (code) => {
            clearInterval(monitor);
            clearTimeout(timeout);
            if (safetyLimitHit) {
                reject(
                    new Error(
                        `Verification exceeded the 4 GiB safety limit. Peak container RSS: ${peakMemoryMiB.toFixed(1)} MiB.`,
                    ),
                );
            } else if (code === 0) {
                resolve({ output, peakMemoryMiB });
            } else {
                reject(
                    new Error(
                        output || `Verification exited with code ${code}.`,
                    ),
                );
            }
        });
    });
}

await fs.mkdir(outputRoot, { recursive: true });
const features = JSON.parse(
    await fs.readFile(
        path.join(projectRoot, "var/config_js_features.json"),
        "utf8",
    ),
);
assert.equal(
    features.ADMIN_VITE_8,
    true,
    "Enable ADMIN_VITE_8 and run feature:dump in the isolated lane first.",
);
const extensionDefinitions = Object.entries(
    JSON.parse(
        await fs.readFile(path.join(projectRoot, "var/plugins.json"), "utf8"),
    ),
).filter(([, item]) => item.administration?.entryFilePath);
const expectedExtensions = extensionDefinitions.length;
const expectedExtensionNames = extensionDefinitions.map(([name]) => name);

const startedAt = Date.now();
let readyAt;
let extensionReadyAt;
let injectedExtensions = 0;
let logsText = "";
const server = spawn("npm", ["run", "dev"], {
    cwd: administrationRoot,
    env: {
        ...process.env,
        VITE_MODE: "development",
        PROJECT_ROOT: projectRoot,
        APP_URL: process.env.VITE_POC_BACKEND_URL ?? "http://127.0.0.1:8000",
        SHOPWARE_ADMIN_SKIP_SOURCEMAP_GENERATION: "1",
        DISABLE_DEVSERVER_OPEN: "1",
    },
    stdio: ["ignore", "pipe", "pipe"],
});

function consumeLog(chunk) {
    const value = chunk.toString();
    logsText += value;
    logs.push(value);
    void fs.appendFile(watcherLogPath, value);
    if (!readyAt && /VITE v8\.\d+\.\d+\s+ready in/.test(logsText))
        readyAt = Date.now();
    injectedExtensions = expectedExtensionNames.filter(
        (name) =>
            logsText.includes(
                `[Extensions] # Plugin "${name}": Injected successfully`,
            ) ||
            logsText.includes(
                `[Extensions] # App "${name}": Injected successfully`,
            ),
    ).length;
    if (!extensionReadyAt && injectedExtensions === expectedExtensions)
        extensionReadyAt = Date.now();
}

server.stdout.on("data", consumeLog);
server.stderr.on("data", consumeLog);

const timeoutAt = startedAt + 90_000;
try {
    while ((!readyAt || !extensionReadyAt) && Date.now() < timeoutAt) {
        if (server.exitCode !== null)
            throw new Error(
                `Watcher exited before readiness.\n${logsText.slice(-6000)}`,
            );
        await delay(250);
    }
    assert.ok(
        readyAt && extensionReadyAt,
        `Watcher readiness timed out. Saw ${injectedExtensions}/${expectedExtensions} extension servers.\n${logsText.slice(-6000)}`,
    );

    const mapping = JSON.parse(
        await fs.readFile(
            path.join(
                administrationRoot,
                "build/vite-plugins/utils/vite-server-mapping.json",
            ),
            "utf8",
        ),
    );
    const verification = await runVerification();
    const verificationOutput = verification.output;
    await fs.writeFile(
        path.join(outputRoot, "verification.log"),
        verificationOutput,
    );

    const samples = [];
    let previous;
    const sampleStartedAt = Date.now();
    while (Date.now() - sampleStartedAt <= durationMs) {
        const elapsedMs = Date.now() - sampleStartedAt;
        const current = await sample(elapsedMs, previous);
        samples.push(current);
        previous = {
            elapsedMs,
            cpuUsec: current.cgroupCpuUsec,
            treeCpuTicks: current.treeCpuTicks,
        };
        if (current.containerRssMiB * 1024 * 1024 > safetyLimitBytes) {
            throw new Error(
                `Safety limit reached: container RSS exceeded 4 GiB (${current.containerRssMiB.toFixed(0)} MiB).`,
            );
        }
        await delay(intervalMs);
    }

    const summaries = Object.fromEntries(
        [
            "containerRssMiB",
            "viteTreeRssMiB",
            "containerCpuPercent",
            "viteTreeCpuTicksPerSecond",
            "processCount",
            "openFds",
            "threads",
            "inotifyWatches",
            "listeners",
        ].map((key) => {
            const values = samples.map((item) => item[key]);
            return [
                key,
                {
                    median: median(values),
                    max: Math.max(...values),
                    min: Math.min(...values),
                },
            ];
        }),
    );
    const result = {
        date,
        node: process.version,
        cpuModel: os.cpus()[0]?.model ?? "unknown",
        extensionEntries: expectedExtensions,
        extensionViteServers: Object.keys(mapping.extensions).length,
        verificationPeakContainerRssMiB: verification.peakMemoryMiB,
        mainVitePort: mapping.vitePort,
        startupMs: {
            mainReady: readyAt - startedAt,
            extensionsReady: extensionReadyAt - startedAt,
        },
        durationMs,
        intervalMs,
        summaries,
        samples,
        safetyLimitMiB: safetyLimitBytes / 1024 / 1024,
    };

    await fs.writeFile(
        path.join(outputRoot, "raw.json"),
        `${JSON.stringify(result, null, 2)}\n`,
    );
    const columns = [
        "elapsedMs",
        "containerRssMiB",
        "viteTreeRssMiB",
        "containerCpuPercent",
        "viteTreeCpuTicksPerSecond",
        "processCount",
        "openFds",
        "threads",
        "inotifyWatches",
        "listeners",
    ];
    await fs.writeFile(
        path.join(outputRoot, "samples.csv"),
        `${columns.join(",")}\n${samples.map((item) => columns.map((column) => item[column]).join(",")).join("\n")}\n`,
    );
    await fs.writeFile(path.join(outputRoot, "graphs.svg"), makeSvg(samples));
    await fs.writeFile(watcherLogPath, logsText);

    const readout = [
        "# Shopware Vite 8 watcher run",
        "",
        `This run used ${expectedExtensions} active Shopware Administration entries with Vite 8 enabled through ADMIN_VITE_8.`,
        "",
        `- Main Vite server readiness: ${result.startupMs.mainReady} ms.`,
        `- Extension Vite servers ready: ${result.startupMs.extensionsReady} ms.`,
        `- Extension Vite server instances: ${result.extensionViteServers}.`,
        `- Container RSS median / peak: ${summaries.containerRssMiB.median.toFixed(1)} / ${summaries.containerRssMiB.max.toFixed(1)} MiB.`,
        `- Vite process-tree RSS median / peak: ${summaries.viteTreeRssMiB.median.toFixed(1)} / ${summaries.viteTreeRssMiB.max.toFixed(1)} MiB.`,
        `- Container CPU median / peak: ${summaries.containerCpuPercent.median.toFixed(1)} / ${summaries.containerCpuPercent.max.toFixed(1)}%.`,
        `- Process / fd / thread median: ${summaries.processCount.median} / ${summaries.openFds.median} / ${summaries.threads.median}.`,
        `- Inotify watches in the Vite process tree: ${summaries.inotifyWatches.median}.`,
        `- Listening Vite ports: ${summaries.listeners.median}.`,
        `- Peak container RSS while transforming all extension graphs: ${verification.peakMemoryMiB.toFixed(1)} MiB.`,
        "",
        "The run fetched all 41 extension graphs and verified a Twig HMR event isolated to plugin 1.",
        "",
        "RSS and CPU include the Shopware web container services for container metrics and the npm dev process tree for Vite metrics.",
        "",
        `The Vite watcher used ${process.env.VITE_POC_BACKEND_URL ?? "http://127.0.0.1:8000"} as its container-local backend proxy target.`,
        "",
        "The generated plugin source trees are synthetic Shopware plugins with the real directory shape, plugin lifecycle, extension dump, and Administration Vite plugin stack.",
        "",
        "The browser UI and its memory use are outside this measurement.",
        "",
        "![Shopware Vite watcher resource graph](graphs.svg)",
        "",
        "The raw samples, CSV series, and logs are saved alongside this note.",
        "",
    ].join("\n");
    await fs.writeFile(path.join(outputRoot, "README.md"), readout);
    console.log(`Saved Shopware Vite 8 run to ${outputRoot}.`);
    console.log(
        `Readiness: ${result.startupMs.mainReady} ms main, ${result.startupMs.extensionsReady} ms extensions.`,
    );
    console.log(
        `Memory: ${summaries.containerRssMiB.median.toFixed(1)} MiB container median, ${summaries.viteTreeRssMiB.median.toFixed(1)} MiB Vite tree median.`,
    );
    console.log(
        `Verification: ${verificationOutput.trim().replaceAll("\n", " | ")}`,
    );
} finally {
    if (server.exitCode === null) {
        server.kill("SIGINT");
        await Promise.race([
            new Promise((resolve) => server.once("close", resolve)),
            delay(8000),
        ]);
        if (server.exitCode === null) server.kill("SIGTERM");
    }
}
