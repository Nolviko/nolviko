<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\ConfigWriter;
use Nolviko\Core\Config\MailSettings;
use RuntimeException;

/** Optional installer mail settings; preserve the existing database, secret and unrelated configuration. */
final class MailSetup
{
    public const FIELDS = [
        'transport',
        'from_address',
        'from_name',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_user',
    ];

    public function __construct(
        private readonly string $configDirectory,
        private readonly string $publicDirectory,
    ) {
    }

    /**
     * @param array<string, mixed> $input SMTP passwords are never retained outside the private configuration.
     * @return array<string, array<string, mixed>>
     */
    public function configuration(array $input): array
    {
        $this->assertOpen();
        $file = $this->configDirectory . '/local.php';

        if (is_link($file) || !is_file($file)) {
            throw new RuntimeException('config_write_failed');
        }

        $settings = (static fn(string $path): mixed => require $path)($file);

        if (!is_array($settings)) {
            throw new RuntimeException('config_write_failed');
        }

        $mail = [];

        foreach ([...self::FIELDS, 'smtp_password'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                throw new ConfigException(['config_type_invalid:mail.' . $field]);
            }

            $value = $input[$field] ?? '';
            $limit = $field === 'smtp_password' ? 4096 : 320;

            if (strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new ConfigException(['config_value_invalid:mail.' . $field]);
            }

            $mail[$field] = $field === 'smtp_password' ? $value : trim($value);
        }

        $mail['transport'] = $mail['transport'] === '' ? MailSettings::MAIL : $mail['transport'];

        if ($mail['transport'] === MailSettings::SMTP && $mail['from_address'] === '') {
            throw new ConfigException(['config_value_invalid:mail.from_address']);
        }

        if ($mail['smtp_port'] !== '') {
            if (!ctype_digit($mail['smtp_port']) || strlen($mail['smtp_port']) > 5) {
                throw new ConfigException(['config_value_invalid:mail.smtp_port']);
            }

            $mail['smtp_port'] = (int) $mail['smtp_port'];
        }

        $mail = array_filter($mail, static fn(mixed $value): bool => $value !== '');

        if ($mail['transport'] === MailSettings::MAIL) {
            $mail = array_intersect_key($mail, array_flip(['transport', 'from_address', 'from_name']));
        }

        $settings['mail'] = $mail;
        ConfigLoader::fromArray($settings);

        return $settings;
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $settings = $this->configuration($input);
        $this->assertOpen();
        ConfigWriter::write($this->configDirectory . '/local.php', $settings, $this->publicDirectory);
    }

    /** Native-mail installs use the validated first administrator address when no sender was supplied. */
    public function ensureSender(?string $address): void
    {
        $this->assertOpen();
        $file = $this->configDirectory . '/local.php';

        if (is_link($file) || !is_file($file)) {
            throw new RuntimeException('config_write_failed');
        }

        $settings = (static fn(string $path): mixed => require $path)($file);

        if (!is_array($settings)) {
            throw new RuntimeException('config_write_failed');
        }

        $config = ConfigLoader::fromArray($settings);

        if ($config->mail->fromAddress !== null) {
            return;
        }

        if ($address === null || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('mail_not_configured');
        }

        $settings['mail']['from_address'] = $address;
        ConfigLoader::fromArray($settings);
        $this->assertOpen();
        ConfigWriter::write($file, $settings, $this->publicDirectory);
    }

    private function assertOpen(): void
    {
        if (
            file_exists($this->configDirectory . '/install.lock')
            || is_link($this->configDirectory . '/install.lock')
        ) {
            throw new RuntimeException('installer_closed');
        }
    }
}
