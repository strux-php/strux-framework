<?php

declare(strict_types=1);

namespace Strux\Component\Database\ORM;

use RuntimeException;
use Strux\Support\Collection;

class Adhoc extends Model
{
    private ?string $_adhocTable = null;

    protected function from(string $table): static
    {
        $builder = parent::from($table);
        $builder->_adhocTable = $table;
        return $builder;
    }

    protected function get(): Collection
    {
        $state = $this->getQueryState();
        $table = $state['from'];
        $collection = parent::get();
        if ($table !== null) {
            foreach ($collection as $model) {
                if ($model instanceof self) {
                    $model->_adhocTable = $table;
                }
            }
        }
        return $collection;
    }

    public function getTable(): string
    {
        if ($this->_adhocTable !== null) {
            return $this->_adhocTable;
        }
        return parent::getTable();
    }

    public function __call(string $method, array $arguments)
    {
        try {
            return parent::__call($method, $arguments);
        } catch (RuntimeException $e) {
            $original = $this->getOriginal();
            if (empty($arguments) && array_key_exists($method, $original)) {
                return $original[$method];
            }
            throw $e;
        }
    }

    public function __debugInfo(): array
    {
        return $this->getOriginal();
    }

    public function toArray(): array
    {
        return $this->getOriginal();
    }
}
