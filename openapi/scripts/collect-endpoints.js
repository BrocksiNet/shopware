const { spawnSync } = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

function readSymfonyRoutes() {
	const result = spawnSync(
		"bin/console",
		["debug:router", "--format=json", "--no-ansi", "-q"],
		{ encoding: "utf8" },
	);
	if (result.status !== 0) {
		throw new Error(`Failed to read routes: ${result.stderr || result.stdout}`);
	}
	let out = result.stdout || "";
	const start = out.indexOf("{");
	const end = out.lastIndexOf("}");
	if (start === -1 || end === -1 || end <= start) {
		throw new Error("Router output did not contain valid JSON object");
	}
	out = out.slice(start, end + 1);
	const json = JSON.parse(out);
	return Object.values(json).map((r) => ({
		path: r.path,
		methods: r.methods || [],
	}));
}

function filterByPrefix(routes, prefix) {
	return routes.filter(
		(r) => typeof r.path === "string" && r.path.startsWith(prefix),
	);
}

function readFromCode(mode, prefix) {
	const rootDir = path.resolve(__dirname, "..", "..");
	const baseDir = path.join(
		rootDir,
		"src/Core/Framework/Api/ApiDefinition/Generator/Schema",
		mode === "admin" ? "AdminApi" : "StoreApi",
		"paths",
	);
	const results = [];
	if (fs.existsSync(baseDir)) {
		const files = fs.readdirSync(baseDir).filter((f) => f.endsWith(".json"));
		for (const file of files) {
			const json = JSON.parse(
				fs.readFileSync(path.join(baseDir, file), "utf8"),
			);
			for (const logical of Object.keys(json.paths || {})) {
				const runtimePath = `${prefix}${logical}`;
				const methods = Object.keys(json.paths[logical] || {}).filter((m) =>
					["get", "post", "put", "patch", "delete"].includes(m),
				);
				results.push({ path: runtimePath, methods });
			}
		}
	}
	return results;
}

function readFromControllerCode(prefix) {
	// Scan PHP files under src/ for Symfony #[Route(...)] attributes with /api paths
	const rootDir = path.resolve(__dirname, "..", "..");
	const srcDir = path.join(rootDir, "src");
	const results = [];
	function walk(dir) {
		let entries;
		try {
			entries = fs.readdirSync(dir, { withFileTypes: true });
		} catch {
			return;
		}
		for (const ent of entries) {
			const full = path.join(dir, ent.name);
			if (ent.isDirectory()) {
				// Skip vendor and node_modules just in case
				if (ent.name === "vendor" || ent.name === "node_modules") continue;
				walk(full);
			} else if (ent.isFile() && ent.name.endsWith(".php")) {
				let content;
				try {
					content = fs.readFileSync(full, "utf8");
				} catch {
					continue;
				}
				const attrRe = /#\[\s*Route\(([^\)]*)\)\]/g;
				let m;
				while (true) {
					m = attrRe.exec(content);
					if (!m) break;
					const inner = m[1] || "";
					const pathMatch = inner.match(/path:\s*(["'])(.*?)\1/);
					if (!pathMatch) continue;
					const p = pathMatch[2];
					if (!p.startsWith("/api")) continue;
					const methodsMatch = inner.match(/methods:\s*\[([^\]]*)\]/);
					let methods = [];
					if (methodsMatch) {
						methods = methodsMatch[1]
							.split(",")
							.map((s) => s.replace(/['"\s]/g, ""))
							.filter(Boolean)
							.map((s) => s.toUpperCase());
					}
					results.push({ path: p, methods });
				}
			}
		}
	}

	walk(srcDir);
	// Deduplicate by path
	const byPath = new Map();
	for (const r of results) {
		const existing = byPath.get(r.path);
		if (!existing) {
			byPath.set(r.path, { path: r.path, methods: new Set(r.methods) });
		} else {
			for (const m of r.methods) existing.methods.add(m);
		}
	}
	return Array.from(byPath.values()).map((e) => ({
		path: e.path,
		methods: Array.from(e.methods),
	}));
}

function main() {
	const mode = process.argv[2] || "store";
	const prefix = mode === "admin" ? "/api" : "/store-api";
	try {
		let routes = [];
		if (mode === "admin") {
			// Prefer code scan for admin
			routes = readFromControllerCode(prefix);
			if (!routes || routes.length === 0) {
				// fallback to router
				routes = filterByPrefix(readSymfonyRoutes(), prefix);
			}
		} else {
			routes = filterByPrefix(readSymfonyRoutes(), prefix);
		}
		if (!routes || routes.length === 0) {
			routes = readFromCode(mode, prefix);
		}
		process.stdout.write(JSON.stringify(routes, null, 2));
	} catch (e) {
		// If router output couldn't be parsed, try code-defined paths before minimal fallback
		try {
			const routes = readFromCode(mode, prefix);
			if (routes.length > 0) {
				process.stdout.write(JSON.stringify(routes, null, 2));
				return;
			}
		} catch {}
		const fallback =
			mode === "admin"
				? [{ path: "/api/_info/config", methods: ["GET"] }]
				: [{ path: "/store-api/context", methods: ["GET"] }];
		process.stderr.write(`collect-endpoints fallback: ${e.message || e}\n`);
		process.stdout.write(JSON.stringify(fallback, null, 2));
	}
}

if (require.main === module) {
	main();
}

module.exports = { readSymfonyRoutes, filterByPrefix };
