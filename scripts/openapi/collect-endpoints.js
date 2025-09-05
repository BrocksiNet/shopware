'use strict';

const { spawnSync } = require('node:child_process');

function readSymfonyRoutes() {
  const result = spawnSync('bin/console', ['debug:router', '--format=json'], { encoding: 'utf8' });
  if (result.status !== 0) {
    throw new Error(`Failed to read routes: ${result.stderr || result.stdout}`);
  }
  const json = JSON.parse(result.stdout);
  return Object.values(json).map(r => ({ path: r.path, methods: r.methods || [] }));
}

function filterByPrefix(routes, prefix) {
  return routes.filter(r => typeof r.path === 'string' && r.path.startsWith(prefix));
}

function main() {
  const mode = process.argv[2] || 'store';
  const prefix = mode === 'admin' ? '/api' : '/store-api';
  const routes = filterByPrefix(readSymfonyRoutes(), prefix);
  process.stdout.write(JSON.stringify(routes, null, 2));
}

if (require.main === module) {
  main();
}

module.exports = { readSymfonyRoutes, filterByPrefix };


