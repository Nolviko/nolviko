<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

/**
 * Expand/migrate/contract (DATABASE.md): only `contract` may remove or narrow schema, and it never runs during
 * an unattended update (ADR-026).
 */
enum MigrationPhase: string
{
    case Expand = 'expand';
    case Migrate = 'migrate';
    case Contract = 'contract';
}
