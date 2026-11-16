<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

/** Read-only bounded source bytes; filesystem locations never become view or checkpoint data. */
interface ImportAsset
{
    public function size(): int;

    public function digest(): string;

    public function copy(mixed $destination): void;
}
