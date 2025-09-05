const { spawnSync } = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

function parseDotEnv(filePath) {
	const env = {};
	if (!fs.existsSync(filePath)) return env;
	const lines = fs.readFileSync(filePath, "utf8").split(/\r?\n/);
	for (const line of lines) {
		if (!line || line.trim().startsWith("#")) continue;
		const idx = line.indexOf("=");
		if (idx === -1) continue;
		const key = line.slice(0, idx).trim();
		const value = line.slice(idx + 1).trim();
		if (key) env[key] = value;
	}
	return env;
}

function run(cmd, args, opts = {}) {
	const res = spawnSync(cmd, args, { stdio: "inherit", ...opts });
	if (res.status !== 0) {
		throw new Error(
			`${cmd} ${args.join(" ")} failed with exit code ${res.status}`,
		);
	}
}

function generateAndValidate(mode) {
	const apiType = mode === "admin" ? "admin" : "store";

	const rootEnvPath = path.resolve(".env");
	const envVars = { ...process.env, ...parseDotEnv(rootEnvPath) };

	const apiGenBin = path.resolve("openapi/node_modules/.bin/api-gen");
	const redoclyBin = path.resolve("openapi/node_modules/.bin/redocly");

	run(apiGenBin, ["loadSchema", `--apiType=${apiType}`], { env: envVars });
	run(apiGenBin, ["generate", `--apiType=${apiType}`], { env: envVars });

	const consoleApiType = mode === "admin" ? "admin-api" : "store-api";
	run(
		"bin/console",
		["open-api:validate", `--api-type=${consoleApiType}`, "-vvv"],
		{ env: envVars },
	);

	const schemaPath =
		mode === "admin"
			? "./api-types/adminApiSchema.json"
			: "./api-types/storeApiSchema.json";
	run(
		redoclyBin,
		[
			"lint",
			"--skip-rule",
			"operation-4xx-response",
			"--skip-rule",
			"no-server-example.com",
			"--skip-rule",
			"no-unused-components",
			schemaPath,
		],
		{ env: envVars },
	);
}

if (require.main === module) {
	const mode = process.argv[2] || "store";
	generateAndValidate(mode);
}

module.exports = { generateAndValidate };
