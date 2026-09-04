<?php

/**
 * WinrmProxyTest.php
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

namespace LibreNMS\Tests\Unit;

use App\ApiClients\WinrmProxy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LibreNMS\Tests\TestCase;

final class WinrmProxyTest extends TestCase
{
    private function tokenConfig(): array
    {
        return [
            'url' => 'https://proxy.example.com:8443',
            'auth' => 'token',
            'token' => 'super-secret-token',
        ];
    }

    public function testSendsCorrectRequestShapeAndBearerToken(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'value' => ['reboot_pending' => false]], 200)]);

        $client = new WinrmProxy($this->tokenConfig());
        $client->check('winsrv01', 'reboot-pending');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://proxy.example.com:8443/check'
                && $request['host'] === 'winsrv01'
                && $request['check_name'] === 'reboot-pending'
                && count($request->data()) === 2 // never send extra/free-form fields
                && $request->hasHeader('Authorization', 'Bearer super-secret-token');
        });
    }

    public function testParsesSuccessfulResponse(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true,
            'value' => ['reboot_pending' => true],
            'message' => null,
            'error' => null,
        ], 200)]);

        $result = (new WinrmProxy($this->tokenConfig()))->check('winsrv01', 'reboot-pending');

        $this->assertTrue($result->ok);
        $this->assertSame(['reboot_pending' => true], $result->value);
        $this->assertNull($result->error);
    }

    public function testParsesProxySideCheckFailure(): void
    {
        // Proxy responded successfully (HTTP 200) but the check itself
        // failed (e.g. Kerberos/JEA error) -- ok=false with an error,
        // distinct from a transport-level failure.
        Http::fake(['*' => Http::response([
            'ok' => false,
            'value' => null,
            'message' => null,
            'error' => 'kerberos authentication failed',
        ], 200)]);

        $result = (new WinrmProxy($this->tokenConfig()))->check('winsrv01', 'reboot-pending');

        $this->assertFalse($result->ok);
        $this->assertSame('kerberos authentication failed', $result->error);
    }

    public function testNonSuccessfulHttpStatusBecomesError(): void
    {
        Http::fake(['*' => Http::response('unauthorized', 401)]);

        $result = (new WinrmProxy($this->tokenConfig()))->check('winsrv01', 'reboot-pending');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('401', $result->error);
    }

    public function testConnectionExceptionBecomesError(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $result = (new WinrmProxy($this->tokenConfig()))->check('winsrv01', 'reboot-pending');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('proxy connection failed', $result->error);
    }

    public function testUnexpectedResponseShapeBecomesError(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

        $result = (new WinrmProxy($this->tokenConfig()))->check('winsrv01', 'reboot-pending');

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
    }

    public function testMtlsModeDoesNotSendBearerToken(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'value' => ['reboot_pending' => false]], 200)]);

        $config = [
            'url' => 'https://proxy.example.com:8443',
            'auth' => 'mtls',
            'client_cert' => '/tmp/does-not-need-to-exist-for-this-assertion.crt',
            'client_key' => '/tmp/does-not-need-to-exist-for-this-assertion.key',
        ];

        (new WinrmProxy($config))->check('winsrv01', 'reboot-pending');

        Http::assertSent(function (Request $request) {
            return ! $request->hasHeader('Authorization');
        });
    }

    /**
     * @return array{pem: string, fingerprint: string}
     */
    private function makeTestCertPem(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test-ca'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $pem);

        return ['pem' => $pem, 'fingerprint' => openssl_x509_fingerprint($pem, 'sha256')];
    }

    public function testMtlsWithRemoteCaButNoFingerprintConfiguredFailsClosed(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ca = $this->makeTestCertPem();
        $caFile = tempnam(sys_get_temp_dir(), 'winrm-test-ca-');
        file_put_contents($caFile, $ca['pem']);

        $config = [
            'url' => 'https://proxy.example.com:8443',
            'auth' => 'mtls',
            'remote_ca' => $caFile,
            // remote_ca_fingerprint deliberately omitted
        ];

        $result = (new WinrmProxy($config))->check('winsrv01', 'reboot-pending');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('remote_ca_fingerprint', $result->error);
        Http::assertNothingSent();

        unlink($caFile);
    }

    public function testMtlsWithCorrectFingerprintProceeds(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'value' => ['reboot_pending' => false]], 200)]);
        $ca = $this->makeTestCertPem();
        $caFile = tempnam(sys_get_temp_dir(), 'winrm-test-ca-');
        file_put_contents($caFile, $ca['pem']);

        $config = [
            'url' => 'https://proxy.example.com:8443',
            'auth' => 'mtls',
            'remote_ca' => $caFile,
            'remote_ca_fingerprint' => $ca['fingerprint'],
        ];

        $result = (new WinrmProxy($config))->check('winsrv01', 'reboot-pending');

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $request) => true);

        unlink($caFile);
    }

    public function testMtlsWithWrongFingerprintFailsClosed(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ca = $this->makeTestCertPem();
        $caFile = tempnam(sys_get_temp_dir(), 'winrm-test-ca-');
        file_put_contents($caFile, $ca['pem']);

        $config = [
            'url' => 'https://proxy.example.com:8443',
            'auth' => 'mtls',
            'remote_ca' => $caFile,
            'remote_ca_fingerprint' => str_repeat('0', 64), // wrong on purpose
        ];

        $result = (new WinrmProxy($config))->check('winsrv01', 'reboot-pending');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('fingerprint mismatch', $result->error);
        Http::assertNothingSent();

        unlink($caFile);
    }

    public function testMtlsFingerprintCheckSkippedWhenRemoteCaIsDash(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'value' => ['reboot_pending' => false]], 200)]);

        $config = [
            'url' => 'https://proxy.example.com:8443',
            'auth' => 'mtls',
            'remote_ca' => '-',
        ];

        $result = (new WinrmProxy($config))->check('winsrv01', 'reboot-pending');

        $this->assertTrue($result->ok);
    }
}
