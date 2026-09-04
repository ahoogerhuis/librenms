<?php

/**
 * WinrmProxy.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS
 */

namespace App\ApiClients;

use Illuminate\Http\Client\ConnectionException;
use LibreNMS\Util\Http;

/**
 * Client for the librenms-winrm-proxy daemon.
 *
 * Deliberately does NOT extend BaseApi: BaseApi::getClient() caches a
 * single client keyed to one base_uri set at construction, which fits
 * Oxidized's single fixed endpoint but not this -- different devices
 * resolve to different proxy entries (different url/auth/certs) per
 * call, via $config['winrm']['proxies'], so a fresh configured client
 * is built per call instead, on the same underlying
 * LibreNMS\Util\Http::client() primitive BaseApi itself uses.
 */
class WinrmProxy
{
    /**
     * @param  array<string, mixed>  $proxyConfig  one resolved entry from
     *                                             $config['winrm']['proxies']
     */
    public function __construct(private readonly array $proxyConfig)
    {
    }

    public function check(string $host, string $checkName): WinrmCheckResult
    {
        if ($caError = $this->verifyRemoteCaFingerprint()) {
            return WinrmCheckResult::error($caError);
        }

        $client = Http::client()
            ->timeout(30)
            ->withOptions($this->tlsOptions());

        if (($this->proxyConfig['auth'] ?? null) === 'token') {
            $client = $client->withToken((string) ($this->proxyConfig['token'] ?? ''));
        }

        $url = rtrim((string) ($this->proxyConfig['url'] ?? ''), '/') . '/check';

        try {
            $response = $client->post($url, [
                'host' => $host,
                'check_name' => $checkName,
            ]);
        } catch (ConnectionException $e) {
            return WinrmCheckResult::error("proxy connection failed: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return WinrmCheckResult::error(
                "proxy returned HTTP {$response->status()}: " . $response->body()
            );
        }

        $data = $response->json();
        if (! is_array($data) || ! array_key_exists('ok', $data)) {
            return WinrmCheckResult::error('proxy returned an unexpected response shape');
        }

        return new WinrmCheckResult(
            ok: (bool) $data['ok'],
            value: $data['value'] ?? null,
            message: $data['message'] ?? null,
            error: $data['error'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tlsOptions(): array
    {
        $options = [];
        $auth = $this->proxyConfig['auth'] ?? 'token';

        if ($auth === 'token') {
            if (! empty($this->proxyConfig['tls_verify'])) {
                $options['verify'] = $this->proxyConfig['tls_verify'];
            }
        } elseif ($auth === 'mtls') {
            if (! empty($this->proxyConfig['client_cert']) && ! empty($this->proxyConfig['client_key'])) {
                $options['cert'] = $this->proxyConfig['client_cert'];
                $options['ssl_key'] = $this->proxyConfig['client_key'];
            }

            // Standard chain validation via the CA bundle at this path.
            // This alone trusts whatever's currently on disk at that path;
            // verifyRemoteCaFingerprint() (called before this in check())
            // is what actually pins it against a swapped file, mirroring
            // the proxy's own allowed_client_ca_fingerprint check.
            if (! empty($this->proxyConfig['remote_ca']) && $this->proxyConfig['remote_ca'] !== '-') {
                $options['verify'] = $this->proxyConfig['remote_ca'];
            }
        }

        return $options;
    }

    /**
     * Fingerprint-pin the proxy's CA, mirroring the proxy's own
     * allowed_client_ca_fingerprint check (config.py) so the "a swapped
     * file at the same path must fail, not be silently trusted"
     * principle holds on both sides of the connection, not just the
     * proxy's validation of the poller's client cert. Found missing
     * during a security self-review -- remote_ca alone (see
     * tlsOptions()) only does standard chain validation against
     * whatever's currently at that path.
     *
     * @return string|null an error message if verification failed, null if OK
     *                     (or not applicable -- token mode, or mtls with no remote_ca set)
     */
    private function verifyRemoteCaFingerprint(): ?string
    {
        $auth = $this->proxyConfig['auth'] ?? 'token';
        if ($auth !== 'mtls') {
            return null;
        }

        $remoteCa = $this->proxyConfig['remote_ca'] ?? null;
        if (empty($remoteCa) || $remoteCa === '-') {
            return null;
        }

        $expected = $this->proxyConfig['remote_ca_fingerprint'] ?? null;
        if (empty($expected)) {
            return "remote_ca ($remoteCa) is set but remote_ca_fingerprint is not -- "
                . 'refusing to trust an unpinned CA file';
        }

        $pem = @file_get_contents($remoteCa);
        if ($pem === false) {
            return "remote_ca file not readable: $remoteCa";
        }

        $actual = openssl_x509_fingerprint($pem, 'sha256');
        if ($actual === false) {
            return "remote_ca ($remoteCa) is not a valid certificate file";
        }

        $expectedNormalized = strtolower(str_replace(':', '', (string) $expected));
        if (! hash_equals($expectedNormalized, strtolower($actual))) {
            return "remote_ca ($remoteCa) fingerprint mismatch: expected $expectedNormalized, "
                . "got $actual -- refusing to trust. If this CA rotation is intentional, "
                . 'update remote_ca_fingerprint to match.';
        }

        return null;
    }
}
