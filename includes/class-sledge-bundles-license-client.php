<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTPS client and signature verifier for the iYi licensing service.
 */
final class Sledge_Bundles_License_Client
{
    private $endpoint;
    private $public_key;

    public function __construct($endpoint, $public_key)
    {
        $this->endpoint = trim((string) $endpoint);
        $this->public_key = trim((string) $public_key);
    }

    /**
     * Sibling of `/licenses/validate`, e.g. `update-check` or `plugin-info`.
     *
     * Prefer `/wp-json/iyi/v1/licenses/{action}` so checks work even when the
     * pretty `/api/licenses/{action}` rewrite has not been flushed yet.
     */
    public function sibling_endpoint($action)
    {
        $action = trim((string) $action, '/');
        $rest = $this->licenses_rest_url($action);
        if ($rest !== '') {
            return $rest;
        }

        $endpoint = rtrim($this->endpoint, '/');
        if (preg_match('#/licenses/validate$#i', $endpoint)) {
            return (string) preg_replace('#/validate$#i', '/' . $action, $endpoint);
        }

        return $endpoint . '/' . $action;
    }

    /**
     * REST URL for a licenses action on the same host as the validate endpoint.
     */
    public function licenses_rest_url($action)
    {
        $action = trim((string) $action, '/');
        $origin = $this->endpoint_origin();
        if ($origin === '' || $action === '') {
            return '';
        }

        return $origin . '/wp-json/iyi/v1/licenses/' . $action;
    }

    public function request(array $payload)
    {
        return $this->request_at($this->endpoint, $payload);
    }

    public function request_at($url, array $payload)
    {
        if (!$this->is_https_url($url)) {
            return new WP_Error('sledge_bundles_license_endpoint', __('The license server endpoint is not configured securely.', 'sledge-bundles'));
        }

        $response = wp_safe_remote_post(
            $url,
            array(
                'timeout'     => 15,
                'redirection' => 0,
                'sslverify'   => true,
                'headers'     => array(
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ),
                'body'        => wp_json_encode($payload),
                'data_format' => 'body',
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('sledge_bundles_license_network', __('The license server could not be reached.', 'sledge-bundles'));
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $fallback = $this->fallback_rest_url($url);
            if ($fallback !== '') {
                return $this->request_at($fallback, $payload);
            }

            return new WP_Error(
                'sledge_bundles_license_http',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The license server returned HTTP %d.', 'sledge-bundles'),
                    $status_code
                )
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['valid']) || !is_bool($data['valid'])) {
            return new WP_Error('sledge_bundles_license_response', __('The license server returned an invalid response.', 'sledge-bundles'));
        }

        if ($data['valid'] && !$this->verify_signed_response($data)) {
            return new WP_Error('sledge_bundles_license_signature', __('The license response signature is invalid.', 'sledge-bundles'));
        }
        if (
            $data['valid']
            && (
                empty($payload['request_nonce'])
                || empty($data['request_nonce'])
                || !hash_equals((string) $payload['request_nonce'], (string) $data['request_nonce'])
            )
        ) {
            return new WP_Error('sledge_bundles_license_nonce', __('The license response does not match this request.', 'sledge-bundles'));
        }

        return $data;
    }

    public function verify_signed_response(array $data)
    {
        return $this->verify_signature($data);
    }

    private function is_https_url($url)
    {
        if (!$url || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        return (bool) wp_http_validate_url($url);
    }

    private function endpoint_origin()
    {
        $parts = wp_parse_url($this->endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return '';
        }

        $origin = 'https://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . (string) $parts['port'];
        }

        return $origin;
    }

    /**
     * Map a pretty `/api/licenses/{action}` URL to REST. Empty when already REST.
     */
    private function fallback_rest_url($url)
    {
        if (strpos($url, '/wp-json/iyi/v1/licenses/') !== false) {
            return '';
        }

        if (!preg_match('#/licenses/([a-z0-9\\-]+)/?$#i', $url, $matches)) {
            return '';
        }

        $fallback = $this->licenses_rest_url((string) $matches[1]);
        if ($fallback === '' || strcasecmp($fallback, $url) === 0) {
            return '';
        }

        return $fallback;
    }

    private function verify_signature(array $data)
    {
        if (!$this->public_key || empty($data['signature']) || !function_exists('openssl_verify')) {
            return false;
        }

        $signature = base64_decode((string) $data['signature'], true);
        if ($signature === false) {
            return false;
        }

        unset($data['signature']);
        $canonical = wp_json_encode($this->canonicalize($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($canonical)) {
            return false;
        }

        $key = openssl_pkey_get_public($this->public_key);
        if (!$key) {
            return false;
        }

        $verified = openssl_verify($canonical, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
        if (is_resource($key)) {
            openssl_free_key($key);
        }

        return $verified;
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->is_associative($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function is_associative(array $value)
    {
        if (array() === $value) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
