<?php

declare(strict_types=1);

namespace Strux\Support\Helpers;

/**
 * Class Utils
 * General purpose utility helper methods.
 */
class Utils
{
	/**
	 * Generates a unique random string identifier.
	 * *
	 * @param int $length Length of the string
	 * @param bool $upperChars Include uppercase letters
	 * @param bool $lowerChars Include lowercase letters
	 * @param bool $digits Include numbers
	 * @return string|int
	 */
	public static function generateId(
		int  $length = 10,
		bool $upperChars = true,
		bool $lowerChars = true,
		bool $digits = true
	): string|int {
		$characters = '';

		if ($upperChars) {
			$characters .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		}

		if ($lowerChars) {
			$characters .= 'abcdefghijklmnopqrstuvwxyz';
		}

		if ($digits) {
			$characters .= '0123456789';
		}

		if (empty($characters)) {
			return '';
		}

		$randomString = '';
		$maxIndex = strlen($characters) - 1;

		try {
			for ($i = 0; $i < $length; $i++) {
				$randomString .= $characters[random_int(0, $maxIndex)];
			}
		} catch (\Exception $e) {
			// Fallback to rand() if random_int fails (rare)
			for ($i = 0; $i < $length; $i++) {
				$randomString .= $characters[rand(0, $maxIndex)];
			}
		}

		if (!$upperChars && !$lowerChars && $digits) return (int)$randomString;

		return $randomString;
	}

	/**
	 * Converts a CamelCase or PascalCase string to snake_case.
	 * * @param string $input e.g., "UserActivity"
	 * @return string e.g., "user_activity"
	 */
	public static function toSnakeCase(string $input): string
	{
		if (empty($input)) {
			return '';
		}
		return strtolower(preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $input));
	}

	/**
	 * Pluralizes a simple English word (basic implementation).
	 * * @param string $input e.g., "Category"
	 * @return string e.g., "categories"
	 */
	public static function getPluralName(string $input): string
	{
		if (empty($input)) {
			return '';
		}

		$baseName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));

		if (str_ends_with($baseName, 'y') && !in_array(substr($baseName, -2, 1), ['a', 'e', 'i', 'o', 'u'])) {
			return substr($baseName, 0, -1) . 'ies';
		}

		if (str_ends_with($baseName, 's')) {
			return $baseName . 'es';
		}

		return $baseName . 's';
	}

	/**
	 * Generate an RFC 4122 v4 UUID using cryptographically secure randomness.
	 *
	 * @return string e.g., "f47ac10b-58cc-4372-a567-0e02b2c3d479"
	 */
	public static function uuid(): string
	{
		$bytes = random_bytes(16);

		// Set version to 0100 (UUID v4)
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

		// Set variant to 10xx (RFC 4122)
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	/**
	 * Generate a ULID (Universally Unique Lexicographically Sortable Identifier).
	 *
	 * 26-character Crockford Base32 encoded value:
	 *   - First 10 chars: 48-bit millisecond timestamp
	 *   - Last 16 chars: 80-bit random value
	 *
	 * @return string e.g., "01ARZ3NDEKTSV4RRFFQ69G5FAV"
	 */
	public static function ulid(): string
	{
		$timestamp = (int) floor(microtime(true) * 1000);

		// 48-bit timestamp as big-endian (take last 6 bytes from 8-byte pack)
		$timestampBytes = substr(pack('J', $timestamp), 2, 6);

		// 80 bits of randomness
		$randomBytes = random_bytes(10);

		return self::encodeCrockfordBase32($timestampBytes . $randomBytes, 26);
	}

	/**
	 * Encode binary data as Crockford Base32.
	 *
	 * Crockford alphabet: 0123456789ABCDEFGHJKMNPQRSTVWXYZ
	 * (excludes I, L, O, U to avoid confusion)
	 *
	 * @param string $bytes Binary input
	 * @param int    $charCount Number of base32 characters to produce
	 * @return string Crockford Base32 encoded string
	 */
	private static function encodeCrockfordBase32(string $bytes, int $charCount): string
	{
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$result = '';
		$buffer = 0;
		$bits = 0;
		$len = strlen($bytes);

		for ($i = 0; $i < $len; $i++) {
			$buffer = ($buffer << 8) | ord($bytes[$i]);
			$bits += 8;

			while ($bits >= 5) {
				$bits -= 5;
				$result .= $alphabet[($buffer >> $bits) & 0x1f];
			}
		}

		if ($bits > 0) {
			$buffer <<= (5 - $bits);
			$result .= $alphabet[$buffer & 0x1f];
		}

		return $result;
	}

	/**
	 * Helper to cast a value to a specific type.
	 * @param mixed $value The value to cast.
	 * @param string $type The target type ('int', 'string', 'bool', 'float', 'array').
	 * @return mixed The casted value.
	 */
	public static function castValue(mixed $value, string $type): mixed
	{
		if ($value === null)
			return null;

		return match (strtolower($type)) {
			'int', 'integer' => (int) $value,
			'str', 'string' => (string) $value,
			'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
			'float', 'double' => (float) $value,
			'array' => (array) $value,
			default => $value
		};
	}
}
