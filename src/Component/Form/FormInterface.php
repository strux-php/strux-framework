<?php

declare(strict_types=1);

namespace Strux\Component\Form;

interface FormInterface
{
    public function isValid(): bool;

    public function getData(): array;

    public function get(?string $key = null, mixed $default = null): mixed;

    public function getErrors(): array;

    public function create(object $model, array $options = []): self;

    public function render(array $formAttributes = [], ?callable $layout = null): string;

    public function setAction(string $action): self;

    public function setMethod(string $method): self;

    public function setEnctype(string $enctype): self;

    public function setWrapperClass(string $class): self;
}
