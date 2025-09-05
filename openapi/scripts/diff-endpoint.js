const fs = require("node:fs");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

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
    throw new Error(`${cmd} ${args.join(" ")} failed with exit code ${res.status}`);
  }
}

function readJSON(file) {
  return JSON.parse(fs.readFileSync(file, "utf8"));
}

function normalizePathKey(key) {
  // Remove optional '/store-api' prefix and normalize template params to '{}'
  const withoutPrefix = key.replace(/^\/store-api/, "");
  return withoutPrefix.replace(/\{[^}]+\}/g, "{}");
}

function findLoadedPathDef(loadedPaths, runtimePath, logicalPath) {
  // Try direct matches first
  if (loadedPaths[runtimePath]) return loadedPaths[runtimePath];
  if (loadedPaths[logicalPath]) return loadedPaths[logicalPath];
  // Try normalized matches (ignore '/store-api' prefix and param names)
  const targetNorms = new Set([
    normalizePathKey(runtimePath),
    normalizePathKey(logicalPath),
  ]);
  for (const key of Object.keys(loadedPaths || {})) {
    if (targetNorms.has(normalizePathKey(key))) {
      return loadedPaths[key];
    }
  }
  return null;
}

function findCodePathDef(mode, logicalPath) {
  const rootDir = path.resolve(__dirname, '..', '..');
  const baseDir = path.join(
    rootDir,
    'src/Core/Framework/Api/ApiDefinition/Generator/Schema',
    mode === 'admin' ? 'AdminApi' : 'StoreApi',
    'paths',
  );
  const files = fs.readdirSync(baseDir).filter((f) => f.endsWith(".json"));
  for (const file of files) {
    const full = path.join(baseDir, file);
    const json = readJSON(full);
    if (json && json.paths && Object.prototype.hasOwnProperty.call(json.paths, logicalPath)) {
      return json.paths[logicalPath];
    }
  }
  return null;
}

function deepEqual(a, b) {
  return JSON.stringify(a) === JSON.stringify(b);
}

function main() {
  const mode = (process.argv[2] || "store").toLowerCase();
  const logicalPath = process.argv[3];
  const method = (process.argv[4] || "").toLowerCase();
  if (!logicalPath) {
    console.error("Usage: node openapi/scripts/diff-endpoint.js <store|admin> <logicalPath e.g. /cms/{id}> [method]");
    process.exitCode = 2;
    return;
  }

  const rootEnvPath = path.resolve(".env");
  const envVars = { ...process.env, ...parseDotEnv(rootEnvPath) };

  const rootDir = path.resolve(__dirname, "..", "..");
  const apiGenBin = path.join(rootDir, "openapi", "node_modules", ".bin", "api-gen");
  const schemaFile = path.join(rootDir, `api-types/${mode === "admin" ? "admin" : "store"}ApiSchema.json`);
  if (!fs.existsSync(schemaFile)) {
    // ensure schema is loaded
    run(apiGenBin, ["loadSchema", `--apiType=${mode === "admin" ? "admin" : "store"}`], { env: envVars });
  }

  const loaded = readJSON(schemaFile);
  const runtimePathPrefix = mode === "admin" ? "/api" : "/store-api";
  const runtimePath = `${runtimePathPrefix}${logicalPath}`;
  const loadedPathDef = findLoadedPathDef(loaded.paths || {}, runtimePath, logicalPath);
  const codePathDef = findCodePathDef(mode, logicalPath);

  if (!codePathDef) {
    console.error(`Code schema not found for logical path: ${logicalPath}`);
    process.exitCode = 1;
    return;
  }
  if (!loadedPathDef) {
    console.error(`Loaded schema missing path: ${runtimePath}`);
    process.exitCode = 1;
    return;
  }

  const methodsToCheck = method ? [method] : Array.from(
    new Set([
      ...Object.keys(codePathDef || {}).map((m) => m.toLowerCase()),
      ...Object.keys(loadedPathDef || {}).map((m) => m.toLowerCase()),
    ]),
  ).filter((m) => ["get", "post", "put", "patch", "delete"].includes(m));

  let hasDiff = false;
  for (const m of methodsToCheck) {
    const codeDef = codePathDef[m];
    const loadDef = loadedPathDef[m];
    if (!codeDef || !loadDef) {
      console.log(`Method ${m.toUpperCase()}: present in ${codeDef ? "code" : "-"}/${loadDef ? "loaded" : "-"}`);
      hasDiff = true;
      continue;
    }
    if (!deepEqual(codeDef.parameters || [], loadDef.parameters || [])) {
      console.log(`Method ${m.toUpperCase()} parameters differ.`);
      hasDiff = true;
    }
    if (!deepEqual(codeDef.requestBody || {}, loadDef.requestBody || {})) {
      console.log(`Method ${m.toUpperCase()} requestBody differs.`);
      hasDiff = true;
    }
    if (!deepEqual(codeDef.responses || {}, loadDef.responses || {})) {
      console.log(`Method ${m.toUpperCase()} responses differ.`);
      hasDiff = true;
    }
  }

  if (!hasDiff) {
    console.log(`No differences for ${runtimePath}${method ? " " + method.toUpperCase() : ""}`);
  } else {
    process.exitCode = 1;
  }
}

if (require.main === module) {
  main();
}


