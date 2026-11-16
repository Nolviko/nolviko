<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TwigFactory;

/** Fresh-process render with no persistent compiled cache; the probe never reads installed configuration. */
final class ThemeColdProbe
{
    public static function run(string $app): array
    {
        $start = hrtime(true);
        $views = TwigFactory::create(
            $app,
            sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(12)),
            TranslatorFactory::create($app . '/translations', 'en'),
            CspNonce::generate()
        );
        $html = $views->render('forums/index.twig', ['title' => 'Cold theme fixture', 'forums' => []]);
        return ['milliseconds' => (hrtime(true) - $start) / 1000000,
            'peak' => memory_get_peak_usage(true), 'hash' => hash('sha256', $html)];
    }
}
