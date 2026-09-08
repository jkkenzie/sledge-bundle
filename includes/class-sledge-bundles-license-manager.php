<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores license state, controls grace-period behavior, and runs revalidation.
 */
final class Sledge_Bundles_License_Manager
{
    const KEY_OPTION   = 'sledge_bundles_license_key';
    const STATE_OPTION = 'sledge_bundles_license_state';
    const CRON_HOOK    = 'sledge_bundles_validate_license';

    private $config;
    private $client;

    public function __construct(array $config, Sledge_Bundles_License_Client $client)
    {
        $this->config = $config;
        $this->client = $client;
        add_action(self::CRON_HOOK, array($this, 'cron_validate'));
    }

    public static function schedule()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + wp_rand(300, 1800), 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function cron_validate()
    {
        $this->validate('validate');
    }

    public function activate($license_key)
    {
        $license_key = $this->sanitize_key($license_key);
        if (!$license_key) {
            return new WP_Error('sledge_bundles_license_key', __('Enter a license key.', 'sledge-bundles'));
        }

        $result = $this->remote_check($license_key, 'activate');
        if (is_wp_error($result)) {
            $this->record_error($result);
            return $result;
        }

        if (!$result['valid']) {
            $this->store_invalid_state($result);
            return new WP_Error(
                'sledge_bundles_license_invalid',
                $this->message_for_status(isset($result['status']) ? $result['status'] : 'invalid')
            );
        }

        $domain_error = $this->validate_response_claims($result);
        if (is_wp_error($domain_error)) {
            $this->record_error($domain_error);
            return $domain_error;
        }

        update_option(self::KEY_OPTION, $license_key, false);
        $this->store_valid_state($result);
        self::schedule();
        self::bust_plugin_update_cache();

        return true;
    }

    public function validate($action = 'validate')
    {
        $license_key = $this->get_license_key();
        if (!$license_key) {
            return new WP_Error('sledge_bundles_license_missing', __('No license key is configured.', 'sledge-bundles'));
        }

        $result = $this->remote_check($license_key, $action);
        if (is_wp_error($result)) {
            $this->record_error($result);
            return $result;
        }

        if (!$result['valid']) {
            $this->store_invalid_state($result);
            return new WP_Error(
                'sledge_bundles_license_invalid',
                $this->message_for_status(isset($result['status']) ? $result['status'] : 'invalid')
            );
        }

        $claim_error = $this->validate_response_claims($result);
        if (is_wp_error($claim_error)) {
            $this->store_invalid_state(array('status' => $claim_error->get_error_code()));
            return $claim_error;
        }

        $this->store_valid_state($result);
        self::bust_plugin_update_cache();
        return true;
    }

    public function deactivate()
    {
        $license_key = $this->get_license_key();
        if (!$license_key) {
            $this->clear();
            return true;
        }

        $result = $this->remote_check($license_key, 'deactivate');
        if (is_wp_error($result)) {
            $this->record_error($result);
            return $result;
        }

        $this->clear();
        self::bust_plugin_update_cache();
        return true;
    }

    public function clear()
    {
        delete_option(self::KEY_OPTION);
        delete_option(self::STATE_OPTION);
        self::bust_plugin_update_cache();
    }

    public static function bust_plugin_update_cache()
    {
        delete_site_transient('update_plugins');
        do_action('sledge_bundles_flush_plugin_update_cache');
    }

    /**
     * Free and development editions are always unlocked.
     * Premium requires a valid signed license.
     */
    public function is_premium_enabled()
    {
        if (in_array($this->config['edition'], array('development', 'free'), true)) {
            return true;
        }

        $status = $this->get_status();
        return !empty($status['valid']);
    }

    public function get_status()
    {
        $state = get_option(self::STATE_OPTION, array());
        $status = array(
            'valid'        => false,
            'status'       => 'inactive',
            'domain'       => $this->get_domain(),
            'expires'      => '',
            'last_checked' => 0,
            'last_error'   => '',
            'in_grace'     => false,
            'has_key'      => (bool) $this->get_license_key(),
        );

        if (!is_array($state)) {
            return $status;
        }

        $status = array_merge($status, array_intersect_key($state, $status));
        if (
            empty($state['valid'])
            || empty($state['last_success'])
            || empty($state['signed_response'])
            || !is_array($state['signed_response'])
            || !$this->client->verify_signed_response($state['signed_response'])
            || empty($state['signed_response']['domain'])
            || $this->normalize_domain($state['signed_response']['domain']) !== $this->get_domain()
        ) {
            $status['valid'] = false;
            return $status;
        }

        $now = time();
        $expiry = $this->expiry_timestamp(isset($state['expires']) ? $state['expires'] : '');
        if ($expiry && $now > $expiry) {
            $status['valid'] = false;
            $status['status'] = 'expired';
            return $status;
        }

        $interval = (int) $this->config['validation_interval'];
        $grace = (int) apply_filters('sledge_bundles_license_grace_period', $this->config['grace_period']);
        $fresh_until = (int) $state['last_success'] + $interval;
        $grace_until = $fresh_until + max(0, $grace);

        $status['valid'] = $now <= $grace_until;
        $status['in_grace'] = $now > $fresh_until && $now <= $grace_until;
        if (!$status['valid']) {
            $status['status'] = 'unreachable';
        }

        return $status;
    }

    public function get_masked_key()
    {
        $key = $this->get_license_key();
        if (!$key) {
            return '';
        }

        return str_repeat('•', max(0, strlen($key) - 4)) . substr($key, -4);
    }

    public function get_domain()
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = strtolower(rtrim((string) $host, '.'));
        return preg_replace('/^www\./i', '', $host);
    }

    public function get_stored_license_key()
    {
        return $this->get_license_key();
    }

    public function get_client()
    {
        return $this->client;
    }

    public function get_plugin_slug()
    {
        return isset($this->config['plugin']) ? (string) $this->config['plugin'] : 'sledge-bundles';
    }

    private function remote_check($license_key, $action)
    {
        return $this->client->request(
            array(
                'action'         => $action,
                'license_key'    => $license_key,
                'domain'         => $this->get_domain(),
                'plugin'         => $this->config['plugin'],
                'plugin_version' => SLEDGE_BUNDLES_VERSION,
                'request_nonce'  => wp_generate_uuid4(),
            )
        );
    }

    private function validate_response_claims(array $response)
    {
        if (empty($response['domain']) || $this->normalize_domain($response['domain']) !== $this->get_domain()) {
            return new WP_Error('sledge_bundles_license_domain', __('The signed license response is for a different domain.', 'sledge-bundles'));
        }

        if (empty($response['plugin']) || $response['plugin'] !== $this->config['plugin']) {
            return new WP_Error('sledge_bundles_license_plugin', __('The signed license response is for a different plugin.', 'sledge-bundles'));
        }

        if (empty($response['status']) || 'active' !== sanitize_key($response['status'])) {
            return new WP_Error('sledge_bundles_license_status', __('The license is not active.', 'sledge-bundles'));
        }

        $issued_at = isset($response['issued_at']) ? absint($response['issued_at']) : 0;
        if (!$issued_at || abs(time() - $issued_at) > 300) {
            return new WP_Error('sledge_bundles_license_stale', __('The signed license response is stale.', 'sledge-bundles'));
        }

        if (!empty($response['expires']) && $this->expiry_timestamp($response['expires']) < time()) {
            return new WP_Error('sledge_bundles_license_expired', __('The license has expired.', 'sledge-bundles'));
        }

        return true;
    }

    private function store_valid_state(array $response)
    {
        $now = time();
        update_option(
            self::STATE_OPTION,
            array(
                'valid'           => true,
                'status'          => isset($response['status']) ? sanitize_key($response['status']) : 'active',
                'domain'          => $this->normalize_domain($response['domain']),
                'expires'         => isset($response['expires']) ? sanitize_text_field($response['expires']) : '',
                'license_id'      => isset($response['license_id']) ? absint($response['license_id']) : 0,
                'customer_id'     => isset($response['customer_id']) ? absint($response['customer_id']) : 0,
                'last_checked'    => $now,
                'last_success'    => $now,
                'last_error'      => '',
                'in_grace'        => false,
                'signed_response' => $response,
            ),
            false
        );
    }

    private function store_invalid_state(array $response)
    {
        update_option(
            self::STATE_OPTION,
            array(
                'valid'        => false,
                'status'       => isset($response['status']) ? sanitize_key($response['status']) : 'invalid',
                'domain'       => $this->get_domain(),
                'expires'      => isset($response['expires']) ? sanitize_text_field($response['expires']) : '',
                'last_checked' => time(),
                'last_error'   => '',
                'in_grace'     => false,
            ),
            false
        );
    }

    private function record_error(WP_Error $error)
    {
        $state = get_option(self::STATE_OPTION, array());
        $state = is_array($state) ? $state : array();
        $state['last_checked'] = time();
        $state['last_error'] = sanitize_text_field($error->get_error_message());
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Map license API status codes to admin-facing messages.
     *
     * @param string $status Server status (e.g. product_mismatch).
     */
    private function message_for_status($status)
    {
        $status = sanitize_key((string) $status);
        $messages = array(
            'product_mismatch'          => __('This license key is for a different product (plugin slug mismatch). Issue or assign a key with plugin slug “sledge-bundles”.', 'sledge-bundles'),
            'activation_limit_reached'  => __('This license has reached its activation limit for other domains. Deactivate one first.', 'sledge-bundles'),
            'domain_mismatch'           => __('This license is not activated for this domain.', 'sledge-bundles'),
            'expired'                   => __('This license has expired.', 'sledge-bundles'),
            'revoked'                   => __('This license has been revoked.', 'sledge-bundles'),
            'suspended'                 => __('This license is suspended.', 'sledge-bundles'),
            'invalid'                   => __('This license key was not found.', 'sledge-bundles'),
            'version_not_allowed'       => __('This plugin version is not allowed for this license.', 'sledge-bundles'),
            'rate_limited'              => __('Too many license requests. Try again shortly.', 'sledge-bundles'),
        );

        if (isset($messages[$status])) {
            return $messages[$status];
        }

        return __('The license is invalid or cannot be activated for this domain.', 'sledge-bundles');
    }

    private function sanitize_key($license_key)
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper(trim((string) $license_key)));
    }

    private function get_license_key()
    {
        return $this->sanitize_key(get_option(self::KEY_OPTION, ''));
    }

    private function normalize_domain($domain)
    {
        $domain = strtolower(trim((string) $domain));
        if (false !== strpos($domain, '://')) {
            $domain = (string) wp_parse_url($domain, PHP_URL_HOST);
        }
        return preg_replace('/^www\./i', '', rtrim($domain, './'));
    }

    private function expiry_timestamp($expires)
    {
        if (!$expires) {
            return 0;
        }

        $timestamp = strtotime((string) $expires . ' 23:59:59 UTC');
        return $timestamp ? $timestamp : 0;
    }
}
