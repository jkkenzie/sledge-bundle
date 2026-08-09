<?php
/**
 * Development licensing configuration.
 *
 * Production builds replace this file inside dist/. Authorization relies on
 * signed server responses; the endpoint is not a secret.
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'edition'             => defined('SLEDGE_BUNDLES_EDITION') ? SLEDGE_BUNDLES_EDITION : 'development',
    'endpoint'            => defined('SLEDGE_BUNDLES_LICENSE_ENDPOINT')
        ? SLEDGE_BUNDLES_LICENSE_ENDPOINT
        : 'https://iyisolutions.com/api/licenses/validate',
    'public_key'          => defined('SLEDGE_BUNDLES_LICENSE_PUBLIC_KEY') ? SLEDGE_BUNDLES_LICENSE_PUBLIC_KEY : '',
    'plugin'              => 'sledge-bundles',
    'validation_interval' => DAY_IN_SECONDS,
    'grace_period'        => 3 * DAY_IN_SECONDS,
);
