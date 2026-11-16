<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use Symfony\Component\HttpFoundation\Request;

/** The current request is bound before lazy views are built; no process globals or direct cookie reads. */
final class ThemeRequest
{
    public ?Request $request = null;

    private ?Request $resolved = null;

    private ?string $mode = null;

    public function __construct(private readonly ?Closure $preference = null)
    {
    }

    public function appearance(): string
    {
        if ($this->resolved === $this->request && $this->mode !== null) {
            return $this->mode;
        }
        $this->resolved = $this->request;
        $stored = $this->preference === null ? null : ($this->preference)();
        if (in_array($stored, ['system', 'light', 'dark'], true)) {
            return $this->mode = $stored;
        }
        $input = $this->request?->cookies->all() ?? [];
        return $this->mode = in_array($input['nolviko_appearance'] ?? null, ['system', 'light', 'dark'], true)
            ? $input['nolviko_appearance'] : 'system';
    }
}
