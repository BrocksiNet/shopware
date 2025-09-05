'use strict';

const { spawnSync } = require('node:child_process');

function run(cmd, args, opts = {}) {
  const res = spawnSync(cmd, args, { stdio: 'inherit', ...opts });
  if (res.status !== 0) {
    throw new Error(`${cmd} ${args.join(' ')} failed with exit code ${res.status}`);
  }
}

function generateAndValidate(mode) {
  const apiType = mode === 'admin' ? 'admin' : 'store';
  run('api-gen', ['loadSchema', `--apiType=${apiType}`]);
  run('api-gen', ['generate', `--apiType=${apiType}`]);

  const consoleApiType = mode === 'admin' ? 'admin-api' : 'store-api';
  run('bin/console', ['open-api:validate', `--api-type=${consoleApiType}`, '-vvv']);

  const schemaPath = mode === 'admin' ? './api-types/adminApiSchema.json' : './api-types/storeApiSchema.json';
  run('redocly', ['lint', '--skip-rule', 'operation-4xx-response', '--skip-rule', 'no-server-example.com', '--skip-rule', 'no-unused-components', schemaPath]);
}

if (require.main === module) {
  const mode = process.argv[2] || 'store';
  generateAndValidate(mode);
}

module.exports = { generateAndValidate };


