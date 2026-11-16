<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Updates\FileSwitchPlan;
use Nolviko\Core\Updates\SignedReleaseManifest;
use RuntimeException;
use ZipArchive;

final class FileSwitchFixture
{
    public readonly TemporaryDirectory $root;

    public readonly string $live;

    public readonly string $private;

    public readonly string $stage;

    public readonly string $archive;

    public readonly SignedReleaseManifest $manifest;

    /** @var array<string, string> */
    public readonly array $installed;

    /**
     * @param array<string, string> $old
     * @param array<string, string> $new
     */
    public function __construct(
        array $old = ['public/a.php' => 'old-a', 'public/obsolete.php' => 'old-obsolete'],
        array $new = ['public/a.php' => 'new-a', 'vendor/autoload.php' => 'new-vendor'],
    ) {
        $this->root = new TemporaryDirectory();
        $this->live = $this->root->path . '/live';
        $this->private = $this->root->path . '/private';
        $this->stage = $this->private . '/stage';
        mkdir($this->private, 0700);
        mkdir($this->stage, 0700);
        $this->root->write('live/config/maintenance.lock', '');
        $this->root->write('live/config/local.php', 'private-config');
        $this->root->write('live/config/install.lock', 'installed');
        $this->root->write('live/storage/uploads/member.bin', 'member-data');
        $this->root->write('live/themes/custom/user.css', 'user-theme');
        $installed = [];

        foreach ($old as $path => $bytes) {
            $this->root->write('live/' . $path, $bytes);
            $installed[$path] = hash('sha256', $bytes);
        }

        $this->installed = $installed;
        $this->archive = $this->private . '/release.zip';
        $zip = new ZipArchive();

        if ($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('fixture_archive_failed');
        }

        $total = 0;

        foreach ($new as $path => $bytes) {
            $zip->addFromString($path, $bytes);
            $zip->setCompressionName($path, ZipArchive::CM_STORE);
            $this->root->write('private/stage/' . $path, $bytes);
            $total += strlen($bytes);
        }

        $zip->close();
        $this->manifest = ReleaseSigningFixture::verify(ReleaseSigningFixture::body([
            'archive_bytes' => filesize($this->archive),
            'archive_sha256' => hash_file('sha256', $this->archive),
            'file_count' => count($new),
            'unpacked_bytes' => $total,
        ]));
    }

    public function plan(): FileSwitchPlan
    {
        return FileSwitchPlan::prepare($this->archive, $this->manifest, $this->stage, $this->installed);
    }
}
