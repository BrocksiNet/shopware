const fs = require("node:fs");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

function run(cmd, args, opts = {}) {
	const res = spawnSync(cmd, args, { stdio: "inherit", ...opts });
	if (res.status !== 0) {
		throw new Error(
			`${cmd} ${args.join(" ")} failed with exit code ${res.status}`,
		);
	}
}

function readJSON(file) {
	return JSON.parse(fs.readFileSync(file, "utf8"));
}

function writeJSON(file, obj) {
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, `${JSON.stringify(obj, null, 2)}\n`);
}

function slugify(input) {
	return input
		.replace(/[^a-zA-Z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "")
		.toLowerCase();
}

function getChangedFiles() {
	const res = spawnSync("git", ["status", "--porcelain"], { encoding: "utf8" });
	if (res.status !== 0) return [];
	return res.stdout
		.split("\n")
		.map((l) => l.trim())
		.filter(Boolean)
		.map((l) => l.slice(3));
}

function ensureEnvFromCiSnippet() {
	if (!fs.existsSync(".env")) {
		process.stderr.write(
			"Warning: .env not found. Run `npm --prefix openapi run bootstrap:env`.\n",
		);
	}
}

function branchAndPush(remote, branch, message) {
	run("git", ["switch", "-c", branch]);
	run("git", ["add", "openapi/diffs/"]);
	run("git", ["commit", "-m", message]);
	run("git", ["push", remote, branch]);
}

function main() {
	const mode = process.env.MODE || process.argv[2] || "store";
	const remote = process.env.REMOTE || process.argv[3] || "brocksinet";

	const progressPath = "openapi/progress.json";
	const configPath = "openapi/config.json";
	const progress = fs.existsSync(progressPath)
		? readJSON(progressPath)
		: { mode, lastProcessed: null, endpoints: {} };
	const config = fs.existsSync(configPath)
		? readJSON(configPath)
		: { defaultMode: mode, remote };

	const collect = spawnSync(
		"node",
		["openapi/scripts/collect-endpoints.js", mode],
		{ encoding: "utf8" },
	);
	if (collect.status !== 0) {
		throw new Error(collect.stderr || collect.stdout);
	}
	const allEndpoints = JSON.parse(collect.stdout);

	for (const ep of allEndpoints) {
		if (!progress.endpoints[ep.path]) {
			progress.endpoints[ep.path] = { status: "pending" };
		}
	}

	const next = allEndpoints.find(
		(ep) => progress.endpoints[ep.path]?.status === "pending",
	);
	if (!next) {
		process.stdout.write("No pending endpoints.\n");
		return;
	}

	progress.endpoints[next.path] = { status: "in_progress" };
	progress.lastProcessed = next.path;
	writeJSON(progressPath, progress);

	ensureEnvFromCiSnippet();

	try {
		run("node", ["openapi/scripts/generate.js", mode]);
	} catch (e) {
		const message = String(e.message || e);
		progress.endpoints[next.path] = { status: "error", error: message };
		writeJSON(progressPath, progress);
		throw e;
	}

	// Run comparator for the specific endpoint; only open branch if diffs exist
	const logicalPath = next.path.replace(/^\/(store-api|api)/, "");
	const compare = spawnSync(
		"node",
		["openapi/scripts/diff-endpoint.js", mode, logicalPath],
		{ encoding: "utf8" },
	);
	const compareOut = (compare.stdout || "") + (compare.stderr || "");

	if (compare.status === 0) {
		progress.endpoints[next.path] = { status: "done" };
		writeJSON(progressPath, progress);
		process.stdout.write(
			`Comparator: no differences for ${next.path}. Marking done.\n`,
		);
		return;
	}

	const diffsDir = path.join("openapi", "diffs", mode);
	fs.mkdirSync(diffsDir, { recursive: true });
	const slug = slugify(next.path.replace(/^\/+/, ""));
	const diffFile = path.join(diffsDir, `${slug}.txt`);
	fs.writeFileSync(
		diffFile,
		compareOut || `Differences detected for ${next.path}`,
		"utf8",
	);

	const branch = `feat/openapi-${mode}/${slug}`;
	const message = `OpenAPI(${mode === "admin" ? "Admin" : "Store"}): refine ${next.path}`;
	branchAndPush(config.remote || remote, branch, message);

	progress.endpoints[next.path] = {
		status: "done",
		branch,
		diff: path.relative(".", diffFile),
	};
	writeJSON(progressPath, progress);
}

if (require.main === module) {
	main();
}

module.exports = { main };
