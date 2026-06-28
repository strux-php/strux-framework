<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Strux\Component\Config\Config;
use Strux\Component\View\PlatesAdapter;
use Strux\Component\View\TwigAdapter;
use Strux\Component\View\ViewInterface;

class ViewRegistry extends ServiceRegistry
{
	public function build(): void
	{
		$this->container->singleton(ViewInterface::class, static function (ContainerInterface $c) {
			/** @var Config $config */
			$config = $c->get(Config::class);

			$engineType = $config->get('view.engine', 'none');
			$viewConfig = $config->get('view', []);

			$contextProviders = $viewConfig['context_providers'] ?? [];

			if ($engineType === 'none' || empty($viewConfig['template_paths']['default'])) {
				return new class implements ViewInterface {
					public function render(string $template, array $data = []): string
					{
						throw new RuntimeException("Attempted to render '$template' with no valid view engine etc.");
					}
				};
			}

			if ($engineType === 'twig') {
				return new TwigAdapter($c, $viewConfig, $contextProviders);
			}

			if ($engineType === 'plates') {
				return new PlatesAdapter($c, $viewConfig, $contextProviders);
			}

			throw new InvalidArgumentException("Unsupported view engine: $engineType");
		});
	}
}
