import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

run('free', false);
run('premium', true);

function run(edition, preserve) {
  const result = spawnSync(process.execPath, ['scripts/build.mjs'], {
    cwd: root,
    env: {
      ...process.env,
      NODE_ENV: 'production',
      SLEDGE_BUNDLES_BUILD_EDITION: edition,
      SLEDGE_BUNDLES_PRESERVE_DIST: preserve ? 'true' : 'false'
    },
    stdio: 'inherit'
  });

  if (result.status !== 0) {
    process.exit(result.status || 1);
  }
}
