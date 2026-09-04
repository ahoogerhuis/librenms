<?php

/**
 * WinrmCheckResult.php
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

/**
 * Result of a single WinrmProxy::check() call. Mirrors the proxy
 * daemon's CheckResult wire model (app/models.py in the
 * librenms-winrm-stack proxy) plus an ok=false/error path for
 * failures that never got a structured response from the proxy at all
 * (connection failure, non-2xx, unparseable body).
 */
final readonly class WinrmCheckResult
{
    public function __construct(
        public bool $ok,
        public ?array $value = null,
        public ?string $message = null,
        public ?string $error = null,
    ) {
    }

    public static function error(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
