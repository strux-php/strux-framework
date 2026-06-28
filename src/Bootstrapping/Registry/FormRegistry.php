<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Strux\Component\Form\FormFactory;

class FormRegistry extends ServiceRegistry
{
	public function build(): void
	{
		$this->container->singleton(FormFactory::class, FormFactory::class);
	}
}
