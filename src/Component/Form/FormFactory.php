<?php

declare(strict_types=1);

namespace Strux\Component\Form;

use Strux\Component\Form\Attributes\FieldAttribute;

class FormFactory
{
    public function create(string $formClass, mixed $data = null, array $options = []): Form
    {
        $form = new $formClass($data);

        if (isset($options['action'])) {
            $form->setAction($options['action']);
        }
        if (isset($options['method'])) {
            $form->setMethod($options['method']);
        }
        if (isset($options['enctype'])) {
            $form->setEnctype($options['enctype']);
        }
        if (isset($options['wrapperClass'])) {
            $form->setWrapperClass($options['wrapperClass']);
        }

        return $form;
    }

    public function auto(object $model, array $options = []): Form
    {
        $action = $options['action'] ?? '';
        $method = $options['method'] ?? 'POST';
        $enctype = $options['enctype'] ?? '';
        $wrapperClass = $options['wrapperClass'] ?? '';

        $form = new class extends Form
        {
            public function build(): void
            {
            }
        };

        $form->create($model, $options);

        return $form;
    }
}
