=== Sledge Bundles ===
Contributors: sledge, iyi
Tags: woocommerce, bundles, combo, products
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Create bundled / combo products in WooCommerce with optional reservation flows.

== Description ==

Sledge Bundles adds combo pack product types to WooCommerce, including cart
handling, order tracking helpers, and reservation options.

= Free edition =
Currently includes the full feature set. A free vs premium feature split will
be introduced in a later release.

= Premium edition =
Same feature set as free for now, but requires a valid license key under
**Settings → Sledge Bundles License**.

== Installation ==

1. Install and activate the plugin.
2. Ensure WooCommerce is active.
3. Create or edit a product and configure the combo / bundle options.
4. Premium builds: open **Settings → Sledge Bundles License** and activate your key.

== Frequently Asked Questions ==

= Where do I manage the license? =

**Settings → Sledge Bundles License**.

= Does the free edition need a license? =

No. Free and development editions stay unlocked until a feature split ships.

= How do I override the combo single product template in my theme? =

Copy a template from `plugins/sledge-bundles/templates/woocommerce/` into your
theme (child themes work). Lookup order:

1. `yourtheme/sledge-bundles/{template}`
2. `yourtheme/woocommerce/{template}`
3. Plugin default

Main templates:

* `content-single-product-combo.php` — full combo product layout
* `single-product/combo-items.php` — bundle items list

Example: copy to `yourtheme/sledge-bundles/content-single-product-combo.php`,
then edit the theme copy. See the project README for a full walkthrough.

== Changelog ==

= 1.0.0 =
* Standalone project with production builds (dist, minify, ZIP).
* Signed licensing client (same system as iYi Elements).
* Free / premium / development editions (features not split yet).
* Combo single-product layout with theme-overridable templates.
