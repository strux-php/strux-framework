<?php

declare(strict_types=1);

namespace Strux\Component\Mapper;

interface ConverterInterface
{
    /**
     * Convert an incoming value for mapping into an entity property.
     *
     * @param mixed $value
     * @return mixed
     */
    public function convert(mixed $value): mixed;

    /**
     * Reverse convert an entity property value back to an array representation.
     *
     * @param mixed $value
     * @return mixed
     */
    public function reverse(mixed $value): mixed;
}
