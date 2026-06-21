<?php

declare(strict_types=1);

namespace Strux\Component\Http;

use Strux\Component\Http\Traits\SanitizesData;
use Strux\Support\Helpers\Utils;

class SafeInput
{
	use SanitizesData;

	private Request $request;

	public function __construct(Request $request)
	{
		$this->request = $request;
	}

	/**
	 * Get a sanitized input value from the request body.
	 */
	public function input(string $key, mixed $default = null, ?string $type = null): mixed
	{
		$value = $this->request->input($key, $default);
		$sanitizedValue = ($value === $default) ? $default : $this->sanitize($value);

		if ($type) {
			return Utils::castValue($sanitizedValue, $type);
		}
		return $sanitizedValue;
	}

	/**
	 * Get a sanitized query parameter.
	 */
	public function query(string $key, mixed $default = null, ?string $type = null): mixed
	{
		$value = $this->request->query($key, $default);
		$sanitizedValue = ($value === $default) ? $default : $this->sanitize($value);

		if ($type) {
			return Utils::castValue($sanitizedValue, $type);
		}
		return $sanitizedValue;
	}

	/**
	 * Get all sanitized POST data.
	 */
	public function allPost(): array
	{
		return $this->sanitize($this->request->allPost());
	}

	/**
	 * Get all sanitized GET data.
	 */
	public function allQuery(): array
	{
		return $this->sanitize($this->request->allQuery());
	}
}
