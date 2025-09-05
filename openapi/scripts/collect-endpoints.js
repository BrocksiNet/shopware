const { spawnSync } = require("node:child_process");

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

function main() {
	const mode = process.argv[2] || "store";
	const prefix = mode === "admin" ? "/api" : "/store-api";
	try {
		const routes = filterByPrefix(readSymfonyRoutes(), prefix);
		process.stdout.write(JSON.stringify(routes, null, 2));
	} catch (e) {
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
