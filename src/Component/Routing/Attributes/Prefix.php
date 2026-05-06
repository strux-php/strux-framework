<?php

declare(strict_types=1);

namespace Strux\Component\Routing\Attributes;

use Attribute;

/**
 * Class Prefix
 *
 * Defines a route prefix attribute for controller classes.
 * It Can be applied multiple times to a class.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Prefix
{
    public string $path;
    public array $defaults;

    /**
     * Prefix constructor.
     *
     * @param string $path The common URI prefix for routes in the controller.
     * @param array $defaults Default values for parameters within this prefix group.
     */
    public function __construct(string $path, array $defaults = [])
    {
        $trimmedPath = trim($path, '/');
        if ($trimmedPath === '') {
            $this->path = '';
        } else {
            $this->path = '/' . $trimmedPath;
        }
        $this->defaults = $defaults;
    }
}
