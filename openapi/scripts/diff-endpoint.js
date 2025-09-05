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

function indexParameters(params = []) {
  const map = new Map();
  for (const p of params) {
    const key = `${p.in}:${p.name}`;
    map.set(key, p);
  }
  return map;
}

function diffParameters(codeParams = [], loadParams = [], options = {}) {
  const { codeHasListingGroup = false } = options;
  const diffs = { missingInLoaded: [], missingInCode: [], changed: [], providedByGroup: [] };
  const codeMap = indexParameters(codeParams);
  const loadMap = indexParameters(loadParams);
  const allKeys = new Set([...codeMap.keys(), ...loadMap.keys()]);
  for (const key of allKeys) {
    const c = codeMap.get(key);
    const l = loadMap.get(key);
    if (c && !l) {
      diffs.missingInLoaded.push(key);
      continue;
    }
    if (!c && l) {
      diffs.missingInCode.push(key);
      continue;
    }
    // Both exist - shallow compare important fields
    const fields = ["required", "description", "schema", "deprecated", "allowEmptyValue"];
    const changedFields = [];
    for (const f of fields) {
      if (!deepEqual(c[f], l[f])) {
        changedFields.push({ field: f, code: c[f], loaded: l[f] });
      }
    }
    if (changedFields.length) {
      diffs.changed.push({ key, changes: changedFields });
    }
  }
  // If the code uses productListingCriteria group, treat missing query params as provided by the group
  if (codeHasListingGroup && diffs.missingInCode.length) {
    const stillMissing = [];
    for (const key of diffs.missingInCode) {
      if (key.startsWith('query:')) {
        diffs.providedByGroup.push(key);
      } else {
        stillMissing.push(key);
      }
    }
    diffs.missingInCode = stillMissing;
  }

  if (!diffs.missingInLoaded.length && !diffs.missingInCode.length && !diffs.changed.length) {
    // If only providedByGroup entries exist, that's not a real diff
    if (diffs.providedByGroup.length === 0) return null;
  }
  return diffs;
}

function diffRequestBody(codeBody = {}, loadBody = {}) {
  if (deepEqual(codeBody, loadBody)) return null;
  const cTypes = Object.keys(codeBody?.content || {});
  const lTypes = Object.keys(loadBody?.content || {});
  const allTypes = new Set([...cTypes, ...lTypes]);
  const result = { missingInLoaded: [], missingInCode: [], schemaDiffs: [] };
  for (const t of allTypes) {
    const c = codeBody?.content?.[t]?.schema;
    const l = loadBody?.content?.[t]?.schema;
    if (c && !l) result.missingInLoaded.push(t);
    else if (!c && l) result.missingInCode.push(t);
    else if (!deepEqual(c, l)) result.schemaDiffs.push({ contentType: t, code: c, loaded: l });
  }
  if (!result.missingInLoaded.length && !result.missingInCode.length && !result.schemaDiffs.length) return null;
  return result;
}

function diffResponses(codeResponses = {}, loadResponses = {}) {
  if (deepEqual(codeResponses, loadResponses)) return null;
  const codes = new Set([...Object.keys(codeResponses || {}), ...Object.keys(loadResponses || {})]);
  const result = { missingInLoaded: [], missingInCode: [], contentDiffs: [] };
  for (const sc of codes) {
    const c = codeResponses?.[sc];
    const l = loadResponses?.[sc];
    if (c && !l) { result.missingInLoaded.push(sc); continue; }
    if (!c && l) { result.missingInCode.push(sc); continue; }
    const cTypes = new Set([
      ...Object.keys(c?.content || {}),
      ...Object.keys(l?.content || {}),
    ]);
    for (const t of cTypes) {
      const cSchema = c?.content?.[t]?.schema;
      const lSchema = l?.content?.[t]?.schema;
      if (!cSchema && lSchema) result.contentDiffs.push({ status: sc, contentType: t, missingInCode: true });
      else if (cSchema && !lSchema) result.contentDiffs.push({ status: sc, contentType: t, missingInLoaded: true });
      else if (!deepEqual(cSchema, lSchema)) result.contentDiffs.push({ status: sc, contentType: t, code: cSchema, loaded: lSchema });
    }
  }
  if (!result.missingInLoaded.length && !result.missingInCode.length && !result.contentDiffs.length) return null;
  return result;
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
    const codeHasListingGroup = Array.isArray(codeDef.parameters) && codeDef.parameters.some((p) => p && p['x-parameter-group'] === 'productListingCriteria');
    const p = diffParameters(codeDef.parameters, loadDef.parameters, { codeHasListingGroup });
    const rb = diffRequestBody(codeDef.requestBody, loadDef.requestBody);
    const rs = diffResponses(codeDef.responses, loadDef.responses);
    // Consider diff present only if meaningful (ignore providedByGroup-only case)
    const pIsMeaningful = p && (p.missingInLoaded.length || p.missingInCode.length || p.changed.length);
    if (pIsMeaningful || rb || rs) {
      hasDiff = true;
      console.log(`\n=== ${m.toUpperCase()} differences for ${logicalPath} ===`);
      if (p) {
        console.log("Parameters:");
        console.log(JSON.stringify(p, null, 2));
      }
      if (rb) {
        console.log("RequestBody:");
        console.log(JSON.stringify(rb, null, 2));
      }
      if (rs) {
        console.log("Responses:");
        console.log(JSON.stringify(rs, null, 2));
      }
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


