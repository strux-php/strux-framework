<?php

declare(strict_types=1);

namespace Strux\Component\Mapper;

interface MapperInterface
{
    /**
     * Map data from an array to an object/entity.
     *
     * @param array $source
     * @param object|string $target Object instance or class name
     * @return object
     */
    public function map(array $source, object|string $target): object;

    /**
     * Reverse map an object/entity back to an array.
     *
     * @param object $source
     * @return array
     */
    public function reverseMap(object $source): array;
}
