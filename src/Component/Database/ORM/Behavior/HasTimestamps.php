<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM\Behavior;

use ReflectionClass;
use ReflectionProperty;
use Strux\Component\Database\Schema\Attributes\Column;

trait HasTimestamps
{
    private ?bool $_hasTimestamps = null;
    private ?string $_createdAtColumn = null;
    private ?string $_updatedAtColumn = null;

    public function hasTimestamps(): bool
    {
        if ($this->_hasTimestamps !== null) {
            return $this->_hasTimestamps;
        }

        $hasCreated = false;
        $hasUpdated = false;
        foreach ($this->reflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $colAttr = $property->getAttributes(Column::class);
            if (!empty($colAttr)) {
                $col = $colAttr[0]->newInstance();
                if ($col->currentTimestamp) $hasCreated = true;
                if ($col->onUpdateCurrentTimestamp) $hasUpdated = true;
            }
        }

        return $this->_hasTimestamps = ($hasCreated || $hasUpdated);
    }

    public function getCreatedAtColumn(): ?string
    {
        if ($this->_createdAtColumn !== null) return $this->_createdAtColumn;

        foreach ($this->reflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $colAttr = $property->getAttributes(Column::class);
            if (!empty($colAttr)) {
                $col = $colAttr[0]->newInstance();
                if ($col->currentTimestamp && !$col->onUpdateCurrentTimestamp) {
                    return $this->_createdAtColumn = $property->getName();
                }
            }
        }
        return null;
    }

    public function getUpdatedAtColumn(): ?string
    {
        if ($this->_updatedAtColumn !== null) return $this->_updatedAtColumn;

        foreach ($this->reflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $colAttr = $property->getAttributes(Column::class);
            if (!empty($colAttr)) {
                $col = $colAttr[0]->newInstance();
                if ($col->onUpdateCurrentTimestamp) {
                    return $this->_updatedAtColumn = $property->getName();
                }
            }
        }
        return null;
    }

    private function handleTimestamps(array &$attributes): void
    {
        if (!$this->hasTimestamps()) {
            return;
        }

        $createdAtColumn = $this->getCreatedAtColumn();
        $updatedAtColumn = $this->getUpdatedAtColumn();

        if (!$this->_exists && $createdAtColumn) {
            if (array_key_exists($createdAtColumn, $attributes) && $attributes[$createdAtColumn] === null) {
                unset($attributes[$createdAtColumn]);
            }
        }

        if ($updatedAtColumn) {
            if (array_key_exists($updatedAtColumn, $attributes) && $attributes[$updatedAtColumn] === null) {
                unset($attributes[$updatedAtColumn]);
            }
        }
    }

    // Helper to get reflection if not available in trait context easily (though Model provides it)
    abstract protected function reflection(): ReflectionClass;
}
