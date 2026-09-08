# Sledge Bundles

WooCommerce combo / bundle products with signed licensing (same system as
**iyi-elements**). Version **1.0.0**.

Canonical source lives here. Built output is copied into the Bedrock site at
`web/app/plugins/sledge-bundles/`.

## Free vs premium

A feature split is **not defined yet**. Free and premium production ZIPs
currently ship the same product code; they differ only by the generated
`includes/license-config.php` edition:

| Edition | License required | Features |
|---------|------------------|----------|
| `development` | no | unlocked (local source stub) |
| `free` | no | unlocked (until a split exists) |
| `premium` | yes (signed API) | locked until a valid license |

When you define free vs premium features, add relative paths to the
`premiumExclude` set in `scripts/build.mjs` so free builds omit them, then gate
runtime with `SLEDGE_BUNDLES_IS_PRO` / `is_premium_enabled()`.

## Source layout

```
sledge-bundles/
├── assets/                 Frontend / admin CSS & JS
├── css/                    License admin styles
├── includes/
│   ├── class-sledge-bundles-license-*.php
│   ├── class-sledge-bundles-plugin-updater.php
│   ├── class-wc-combo-*.php
│   ├── class-wc-combo-product-templates.php  Theme override locator
│   └── license-config.php  Dev stub (builds overwrite this)
├── templates/
│   └── woocommerce/        Overridable single-product templates
├── scripts/                Node build tooling
├── sledge-bundles.php      Bootstrap
└── dist/                   Build output (git-ignored)
```

## Theme template overrides

Combo single-product markup ships in the plugin and can be overridden by any
theme or **child theme** (WordPress `locate_template()` walks the child first).

### Lookup order

For a template such as `content-single-product-combo.php`, the plugin loads the
first file that exists:

1. `{active-theme}/sledge-bundles/content-single-product-combo.php`
2. `{active-theme}/woocommerce/content-single-product-combo.php`
3. `plugins/sledge-bundles/templates/woocommerce/content-single-product-combo.php`

The same order applies to partials under `single-product/`.

### Templates you can override

| Plugin file | Purpose |
|-------------|---------|
| `templates/woocommerce/content-single-product-combo.php` | Full combo single layout (gallery + items + tabs + related + sticky summary) |
| `templates/woocommerce/single-product/combo-items.php` | Bundle line items list (qty / remove / subtotals) |

### Example (child theme)

```text
your-child-theme/
├── sledge-bundles/
│   ├── content-single-product-combo.php   ← preferred plugin-specific path
│   └── single-product/
│       └── combo-items.php
└── woocommerce/                           ← also supported (WooCommerce-style path)
    ├── content-single-product-combo.php
    └── single-product/
        └── combo-items.php
```

Copy a file from the plugin `templates/woocommerce/` folder into one of those
theme paths, then edit the copy. Leave the plugin file alone so updates do not
overwrite your changes.

PowerShell example:

```powershell
$theme = "E:\wamp\www\slegde\site\web\app\themes\retailgrid"
$plugin = "E:\wamp\www\iYi\projects\sledge-bundles\templates\woocommerce"
New-Item -ItemType Directory -Force "$theme\sledge-bundles\single-product" | Out-Null
Copy-Item "$plugin\content-single-product-combo.php" "$theme\sledge-bundles\"
Copy-Item "$plugin\single-product\combo-items.php" "$theme\sledge-bundles\single-product\"
```

### Notes

- Overrides apply only to products with type **combo**. Simple / variable products keep the theme’s normal WooCommerce templates.
- Prefer the `sledge-bundles/` folder in the theme so overrides stay separate from core WooCommerce template copies.
- The floating / sticky `product-summary-side` is implemented **inside the plugin** (`assets/css/wc-combo-product.css` + `assets/js/wc-combo-product.js`). It measures the visible fixed header and sets `--sledge-combo-sticky-top` — no theme JS required.
- CSS/JS for the combo layout still load from the plugin. Theme styles can override those selectors; you do not need to dequeue the plugin assets unless you replace them entirely.
- After adding or changing theme overrides, clear any page / object cache and hard-refresh the product page.

## Build

Requires Node.js 18+.

```sh
npm install
npm run build:development
```

Development output: unpacked `dist-dev/sledge-bundles/` (source maps, no ZIP).

Production:

```sh
# Copy .env.example → .env and set the public key path (shared with iyi-elements)
npm run build:free
npm run build:premium
# or both:
npm run build
```

| Script | Output |
|--------|--------|
| `npm run build:free` | `dist/sledge-bundles-1.0.0-free.zip` + `dist/sledge-bundles/` |
| `npm run build:premium` | `dist/sledge-bundles-1.0.0-premium.zip` + folder |
| `npm run build` | free then premium (requires public key) |
| `npm run build:development` | `dist-dev/sledge-bundles/` |
| `npm run check:php` | PHP lint |

Premium builds embed the RSA public key and license endpoint. Env vars
`SLEDGE_BUNDLES_LICENSE_*` are preferred; `IYI_LICENSE_*` works as a fallback
so you can reuse the same `.env` as iyi-elements.

### Version tags (`npm run build` / `build:premium`)

A successful **premium** build (`npm run build` / `npm run build:premium`) reads
`version` from `package.json` (not from the last git tag). It then tags `HEAD`
as `v{version}` (for example `1.0.0` → `v1.0.0`), pushes that tag to `origin`,
and publishes a GitHub release with the zip. The iYi main site uses that tag
for **Licenses → Packages → Sync now**. Free builds (`npm run build:free`) do
not tag. `npm run build` runs free then premium; tagging runs when the
premium zip is created.

**Commit first, then build.** If `package.json` or `sledge-bundles.php` is
still dirty, the zip is still written, but tagging is skipped (the previous
tag is left unchanged). Rewriting only `dist/*.zip` is allowed and does not
block tagging. Also unset `IYI_SKIP_GIT_TAG` in the shell if a previous
session set it.

New version (creates `v1.0.1`, leaves `v1.0.0` in place):

```sh
# 1. Set version in package.json AND both places in sledge-bundles.php
#    (plugin header `Version:` and SLEDGE_BUNDLES_VERSION)
git add package.json sledge-bundles.php
git commit -m "Release version 1.0.1"
npm run build          # writes dist/sledge-bundles-1.0.1-premium.zip
                       # creates and pushes git tag v1.0.1
```

Same version rebuilt (moves `v1.0.0` to the new `HEAD` and force-pushes that
tag only — not `main`):

```sh
git commit ...         # source changes
npm run build          # retags v1.0.0 onto HEAD
```

How tagging works:

1. Commit (or stash) source changes first. A dirty working tree skips the tag
   and prints the dirty paths.
2. If `v{version}` does not exist, the build creates an annotated tag on `HEAD`
   and runs `git push origin v{version}`.
3. If `v{version}` already points at `HEAD`, the build leaves it in place and
   pushes it if needed.
4. If `v{version}` exists on an **older commit** (same version rebuilt), the
   build deletes the local tag, recreates it on `HEAD`, and force-pushes **that
   tag only** (`git push --force origin refs/tags/v{version}`). It does not
   force-push `main`.
5. When the tag moves, an existing GitHub release with the same name is replaced
   so the zip on the release matches `HEAD`.

`gh` must be installed and authenticated for the GitHub release step. The zip
is still built if tagging is skipped.

Skip flags (in `.env` or the environment):

| Flag | Effect |
| --- | --- |
| `IYI_SKIP_GIT_TAG=1` | Do not create, move, or push the tag |
| `IYI_SKIP_GIT_TAG_PUSH=1` | Create or move the tag locally only |
| `IYI_SKIP_GITHUB_RELEASE=1` | Push the tag but do not create/update the GitHub release |

## Updates

Premium installs check the iYi license server (same host as license validation:
`https://iyisolutions.com`). The store product **plugin slug** must be
`sledge-bundles`, **Updates** must be on for the license, and **Licenses →
Packages** must list the ZIP after **Sync now** (git tag `v{version}`).

### WordPress Plugins screen

1. Activate the license under **Settings → Sledge Bundles License**.
2. Confirm the key is active on this site’s public host (no `www.`).
3. Open **Plugins → Installed Plugins** or **Dashboard → Updates**.
4. If an update does not appear yet, use **Check for plugin updates** on the
   Sledge Bundles License screen, or open Plugins with `?force-check=1`.

The plugin calls `/wp-json/iyi/v1/licenses/update-check` (the pretty URL
`/api/licenses/update-check` is used only as a fallback). If a newer package
exists, an update row appears for Sledge Bundles. **Update now** downloads
`/api/licenses/package` (or the REST package route) with a short-lived token;
the plugin refreshes that token immediately before the download so it does
not expire.

After a successful update, the Plugins screen should not keep offering the
version that was just installed. The check uses the plugin header on disk
(not the in-memory previous version) and clears the WordPress update cache
when this plugin finishes upgrading. If a check fails, Plugins and Sledge
Bundles License show the error instead of a silent “no update.”

If the Plugins screen shows a warning that updates are not allowed, enable
**Updates** on that license in the iYi admin (or use a plan that includes
updates). Development and free builds (`edition` `development` / `free`) do
not call the update API.

### Composer updates (Bedrock)

On a Bedrock (or other Composer-managed) WordPress site, install and update
this plugin from the iYi Composer repository instead of uploading a ZIP.

Package name: `iyi/sledge-bundles` (`type: wordpress-plugin`).

## Deploy to the Sledge site

After a production build:

```powershell
robocopy "E:\wamp\www\iYi\projects\sledge-bundles\dist\sledge-bundles" `
  "E:\wamp\www\slegde\site\web\app\plugins\sledge-bundles" /MIR
```

Or unzip the matching `sledge-bundles-*-{free|premium}.zip` into `web/app/plugins/`.

## Admin

**Settings → Sledge Bundles License** — activate, check, and deactivate the
signed license (`plugin` id: `sledge-bundles`). License nag notices appear only
on the premium build when inactive.

## Licensing

Same contract as iyi-elements: `POST` JSON to
`https://iyisolutions.com/api/licenses/validate` with `plugin: "sledge-bundles"`.
See the iyi-elements README for RSA key setup and the API payload shape.
