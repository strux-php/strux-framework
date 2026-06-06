<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Index
{
    /**
     * @param string|array|null $columns The column(s) to index. If applied to a property and omitted, defaults to the property's column name.
     * @param string|null $name Optional custom name for the index.
     * @param bool $unique Whether this is a unique index.
     */
    public function __construct(
        public string|array|null $columns = null,
        public ?string $name = null,
        public bool $unique = false
    ) {
    }
}
