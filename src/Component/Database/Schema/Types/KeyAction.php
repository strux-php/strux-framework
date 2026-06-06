<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Types;

/**
 * Defines the valid actions for foreign key constraints.
 */
enum KeyAction: string
{
    case CASCADE = 'CASCADE';
    case SET_NULL = 'SET NULL';
    case RESTRICT = 'RESTRICT';
    case NO_ACTION = 'NO ACTION';
}