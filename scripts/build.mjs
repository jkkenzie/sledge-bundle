import fs from 'node:fs/promises';
import fsSync from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { ZipArchive } from 'archiver';
import { transform as transformJs } from 'esbuild';
import { transform as transformCss } from 'lightningcss';
import sharp from 'sharp';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
loadDotEnv(path.join(root, '.env'));
const production = process.env.NODE_ENV !== 'development';
const edition =
  process.env.SLEDGE_BUNDLES_BUILD_EDITION || process.env.IYI_BUILD_EDITION || 'premium';
const pluginVersion = JSON.parse(await fs.readFile(path.join(root, 'package.json'), 'utf8')).version;
const outputRoot = path.join(root, production ? 'dist' : 'dist-dev');
const pluginRoot = path.join(outputRoot, 'sledge-bundles');
/**
 * Paths excluded from free builds once a free/premium feature split exists.
 * Empty for now — free and premium ZIPs ship the same feature code; they differ
 * only by the generated license-config edition.
 */
const premiumExclude = new Set([]);
const buildStats = {
  jsMinified: 0,
  cssMinified: 0,
  cssFallback: 0,
  skippedUnminifiedCompanions: 0,
  imagesOptimized: 0
};

if (!['free', 'premium', 'development'].includes(edition)) {
  throw new Error(`Unknown SLEDGE_BUNDLES_BUILD_EDITION "${edition}".`);
}

const defaultLicenseEndpoint = 'https://iyisolutions.com/api/licenses/validate';
const endpoint =
  edition === 'premium'
    ? String(
        process.env.SLEDGE_BUNDLES_LICENSE_ENDPOINT ||
          process.env.IYI_LICENSE_ENDPOINT ||
          defaultLicenseEndpoint
      ).trim()
    : '';
const publicKey = edition === 'premium' ? await readPublicKey() : '';

if (edition === 'premium') {
  if (!endpoint.startsWith('https://')) {
    throw new Error(
      'SLEDGE_BUNDLES_LICENSE_ENDPOINT / IYI_LICENSE_ENDPOINT must be an HTTPS URL for premium builds.'
    );
  }
  if (!publicKey.includes('BEGIN PUBLIC KEY')) {
    throw new Error(
      'Set SLEDGE_BUNDLES_LICENSE_PUBLIC_KEY(_FILE) or IYI_LICENSE_PUBLIC_KEY(_FILE) to an RSA public key.'
    );
  }
}

if (process.env.SLEDGE_BUNDLES_PRESERVE_DIST === 'true' || process.env.IYI_PRESERVE_DIST === 'true') {
  await fs.rm(pluginRoot, { recursive: true, force: true });
  await fs.rm(path.join(outputRoot, `sledge-bundles-${pluginVersion}-${edition}.zip`), { force: true });
} else {
  await fs.rm(outputRoot, { recursive: true, force: true });
}
await fs.mkdir(pluginRoot, { recursive: true });
await copyTree(root);
await writeLicenseConfig();
await syncPluginVersion();

if (production) {
  const zipPath = path.join(outputRoot, `sledge-bundles-${pluginVersion}-${edition}.zip`);
  await createZip(zipPath);
  console.log(`Built ${edition} plugin: ${path.relative(root, zipPath)}`);
} else {
  console.log(`Built development plugin: ${path.relative(root, pluginRoot)}`);
}

console.log(
  [
    `JS minified: ${buildStats.jsMinified}`,
    `CSS minified: ${buildStats.cssMinified}` +
      (buildStats.cssFallback ? ` (${buildStats.cssFallback} via esbuild fallback)` : ''),
    production
      ? `Dropped unminified companions: ${buildStats.skippedUnminifiedCompanions}`
      : null,
    production ? `Images optimized: ${buildStats.imagesOptimized}` : null
  ]
    .filter(Boolean)
    .join(' | ')
);

async function copyTree(directory) {
  const entries = await fs.readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name);
    const relative = normalize(path.relative(root, absolute));
    if (shouldExclude(relative, entry.isDirectory())) continue;

    if (entry.isDirectory()) {
      await copyTree(absolute);
    } else {
      await emitFile(absolute, relative);
    }
  }
}

function shouldExclude(relative, isDirectory) {
  const lower = relative.toLowerCase();
  const first = lower.split('/')[0];
  if (['.git', '.github', '.idea', '.vscode', 'node_modules', 'dist', 'dist-dev', 'scripts', 'tests'].includes(first)) {
    return true;
  }
  if (
    ['package.json', 'package-lock.json', '.gitignore', 'readme.md', 'readme-reservation-fixes.md', 'documentation.html'].includes(
      lower
    ) ||
    first.startsWith('.env')
  ) {
    return true;
  }
  if (/\.(map|log|config\.(js|mjs|cjs)|lock)$/i.test(lower) || /(^|\/).+ copy\.js$/i.test(relative)) {
    return true;
  }
  // Production ships only the .min sibling when both foo.js and foo.min.js exist.
  if (production && !isDirectory && isUnminifiedCompanion(relative)) {
    buildStats.skippedUnminifiedCompanions += 1;
    return true;
  }
  if (edition === 'free') {
    for (const premiumPath of premiumExclude) {
      if (lower === premiumPath || lower.startsWith(`${premiumPath}/`)) return true;
    }
  }
  return false;
}

/**
 * True when relative is foo.js/css and foo.min.js/css also exists in the source tree.
 */
function isUnminifiedCompanion(relative) {
  const lower = relative.toLowerCase();
  const match = lower.match(/^(.*)\.(js|css)$/);
  if (!match || lower.endsWith('.min.js') || lower.endsWith('.min.css')) {
    return false;
  }
  const minSibling = `${match[1]}.min.${match[2]}`;
  return fsSync.existsSync(path.join(root, ...minSibling.split('/')));
}

async function emitFile(source, relative) {
  // This file is generated from environment-specific release settings.
  if (relative === 'includes/license-config.php') return;

  const destination = path.join(pluginRoot, ...relative.split('/'));
  await fs.mkdir(path.dirname(destination), { recursive: true });
  const extension = path.extname(source).toLowerCase();

  if (extension === '.js') {
    const input = await fs.readFile(source, 'utf8');
    const result = await transformJs(input, {
      minify: production,
      treeShaking: true,
      legalComments: 'none',
      sourcemap: production ? false : 'external',
      sourcefile: path.basename(source),
      target: ['es2017'],
      charset: 'utf8'
    });
    await fs.writeFile(destination, result.code);
    if (production) buildStats.jsMinified += 1;
    if (!production && result.map) await fs.writeFile(`${destination}.map`, result.map);
    return;
  }

  if (extension === '.css') {
    await emitCss(source, destination, relative);
    return;
  }

  if (production && ['.png', '.jpg', '.jpeg', '.webp', '.gif'].includes(extension)) {
    try {
      let image = sharp(source, { animated: extension === '.gif' });
      if (extension === '.png') image = image.png({ compressionLevel: 9 });
      if (extension === '.jpg' || extension === '.jpeg') image = image.jpeg({ quality: 85, mozjpeg: true });
      if (extension === '.webp') image = image.webp({ quality: 85 });
      if (extension === '.gif') image = image.gif({ effort: 7 });
      await image.toFile(destination);
      buildStats.imagesOptimized += 1;
      return;
    } catch (error) {
      console.warn(`Image optimization skipped for ${relative}: ${error.message}`);
    }
  }

  await fs.copyFile(source, destination);
}

async function emitCss(source, destination, relative) {
  const input = await fs.readFile(source);

  if (!production) {
    try {
      const result = transformCss({
        filename: source,
        code: input,
        minify: false,
        sourceMap: true
      });
      await fs.writeFile(destination, result.code);
      if (result.map) await fs.writeFile(`${destination}.map`, result.map);
      return;
    } catch {
      await fs.copyFile(source, destination);
      return;
    }
  }

  try {
    const result = transformCss({
      filename: source,
      code: input,
      minify: true,
      sourceMap: false
    });
    await fs.writeFile(destination, result.code);
    buildStats.cssMinified += 1;
    return;
  } catch (lightningError) {
    try {
      const result = await transformJs(input.toString('utf8'), {
        loader: 'css',
        minify: true,
        legalComments: 'none'
      });
      await fs.writeFile(destination, result.code);
      buildStats.cssMinified += 1;
      buildStats.cssFallback += 1;
      return;
    } catch (esbuildError) {
      console.warn(
        `CSS minify skipped for ${relative}: ${lightningError.message}; esbuild: ${esbuildError.message}`
      );
      await fs.copyFile(source, destination);
    }
  }
}

async function writeLicenseConfig() {
  const encodedEndpoint = Buffer.from(endpoint, 'utf8').toString('base64');
  const encodedKey = Buffer.from(publicKey, 'utf8').toString('base64');
  const php = `<?php
if (!defined('ABSPATH')) {
    exit;
}
return array(
    'edition'             => '${edition}',
    'endpoint'            => base64_decode('${encodedEndpoint}'),
    'public_key'          => base64_decode('${encodedKey}'),
    'plugin'              => 'sledge-bundles',
    'validation_interval' => DAY_IN_SECONDS,
    'grace_period'        => 3 * DAY_IN_SECONDS,
);
`;
  const destination = path.join(pluginRoot, 'includes', 'license-config.php');
  await fs.mkdir(path.dirname(destination), { recursive: true });
  await fs.writeFile(destination, php);
}

/** Keep the main plugin header Version in sync with package.json. */
async function syncPluginVersion() {
  const mainFile = path.join(pluginRoot, 'sledge-bundles.php');
  if (!fsSync.existsSync(mainFile)) return;
  let contents = await fs.readFile(mainFile, 'utf8');
  contents = contents.replace(
    /(Version:\s*)[^\r\n]+/i,
    `$1${pluginVersion}`
  );
  contents = contents.replace(
    /define\(\s*'SLEDGE_BUNDLES_VERSION'\s*,\s*'[^']*'\s*\)/,
    `define('SLEDGE_BUNDLES_VERSION', '${pluginVersion}')`
  );
  await fs.writeFile(mainFile, contents);
}

async function readPublicKey() {
  const keyFile =
    process.env.SLEDGE_BUNDLES_LICENSE_PUBLIC_KEY_FILE || process.env.IYI_LICENSE_PUBLIC_KEY_FILE;
  if (keyFile) {
    return fs.readFile(path.resolve(keyFile), 'utf8');
  }
  const inline =
    process.env.SLEDGE_BUNDLES_LICENSE_PUBLIC_KEY || process.env.IYI_LICENSE_PUBLIC_KEY || '';
  return String(inline).replace(/\\n/g, '\n');
}

async function createZip(zipPath) {
  await new Promise((resolve, reject) => {
    const output = fsSync.createWriteStream(zipPath);
    const archive = new ZipArchive({ zlib: { level: 9 } });
    output.on('close', resolve);
    output.on('error', reject);
    archive.on('error', reject);
    archive.pipe(output);
    archive.directory(pluginRoot, 'sledge-bundles');
    archive.finalize();
  });
}

function normalize(value) {
  return value.split(path.sep).join('/');
}

function loadDotEnv(file) {
  if (!fsSync.existsSync(file)) return;
  for (const line of fsSync.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
    if (!match || line.trimStart().startsWith('#')) continue;
    let value = match[2];
    if ((value.startsWith("'") && value.endsWith("'")) || (value.startsWith('"') && value.endsWith('"'))) {
      value = value.slice(1, -1);
    }
    if (!(match[1] in process.env)) {
      process.env[match[1]] = value;
    }
  }
}
