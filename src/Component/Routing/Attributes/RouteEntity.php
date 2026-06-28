<?php

declare(strict_types=1);

namespace Strux\Component\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RouteEntity
{
    /**
     * @param array<string, string> $mapping Route parameter to database column mapping (e.g. ['id' => 'id'])
     * @param array<int, string> $with Relations to eager load
     */
    public function __construct(
        public array $mapping = ['id' => 'id'],
        public array $with = [],
    ) {}
}
