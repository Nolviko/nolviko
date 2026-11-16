<?php

declare(strict_types=1);

namespace Nolviko\Core\Runtime;

/**
 * Map a runtime diagnostic ID to its message ID and parameters in translations/<locale>/diagnostics.php.
 */
final class DiagnosticMessage
{
    private const DOMAIN_PREFIX = 'diagnostics.';

    /**
     * `extension_missing:intl` becomes `diagnostics.extension_missing` with `{extension: intl}`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function fromDiagnostic(string $diagnostic): array
    {
        [$id, $detail] = array_pad(explode(':', $diagnostic, 2), 2, '');

        $parameters = match ($id) {
            'extension_missing' => ['extension' => $detail],
            'php_version_unsupported' => ['version' => PHP_VERSION],
            'config_key_unknown', 'config_key_missing', 'config_type_invalid', 'config_value_invalid' => [
                'key' => $detail,
            ],
            'database_version_unsupported' => ['version' => $detail],
            'database_family_invalid' => ['family' => $detail],
            'database_backend_unverified', 'migration_backend_unsupported' => ['driver' => $detail],
            'migration_unknown', 'migration_checksum_mismatch', 'migration_step_failed',
            'migration_contract_requires_admin' => ['migration' => $detail],
            'installer_field_invalid', 'installer_field_missing' => ['field' => $detail],
            'permission_value_invalid', 'permission_unknown' => ['key' => $detail],
            'identity_taken' => ['field' => $detail],
            default => [],
        };

        return [self::DOMAIN_PREFIX . $id, $parameters];
    }
}
