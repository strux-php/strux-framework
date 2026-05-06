<?php

declare(strict_types=1);

namespace Strux\Component\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiRoute extends Route
{
}
