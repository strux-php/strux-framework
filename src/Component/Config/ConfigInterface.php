<?php

declare(strict_types=1);

namespace Strux\Component\Config;

interface ConfigInterface
{
	/**
	 * Return the configuration as an associative array.
	 */
	public function toArray(): array;
}
