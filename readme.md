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
│   ├── class-wc-combo-*.php
│   └── license-config.php  Dev stub (builds overwrite this)
├── templates/
├── scripts/                Node build tooling
├── sledge-bundles.php      Bootstrap
└── dist/                   Build output (git-ignored)
```

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
