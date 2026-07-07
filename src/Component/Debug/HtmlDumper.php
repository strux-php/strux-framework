<?php

declare(strict_types=1);

namespace Strux\Component\Debug;

use JetBrains\PhpStorm\NoReturn;
use ReflectionClass;
use stdClass;

class HtmlDumper
{
	private static int $maxDepth = 10;
	private static int $maxStringLength = 200;
	private static int $maxArrayElements = 50;
	private static string $indentation = '    ';
	private static ?string $theme = 'light';
	private static bool $cssRendered = false;

	private function __construct() {}

	public static function dump(...$vars): void
	{
		if (PHP_SAPI === 'cli') {
			foreach ($vars as $var) {
				var_dump($var);
			}
			return;
		}

		echo self::renderCss();

		foreach ($vars as $var) {
			echo '<div class="php-html-dumper">';
			echo self::formatVariable($var, 0);
			echo '</div>';
		}
	}

	#[NoReturn] public static function dd(...$vars): void
	{
		ob_start();
		$trace = ob_get_clean();
		echo '<pre>' . htmlspecialchars($trace) . '</pre>';

		self::dump(...$vars);
		die();
	}

	private static function formatVariable(mixed &$variable, int $depth): string
	{
		$type = gettype($variable);
		$id = 'dumper-' . uniqid();

		switch ($type) {
			case 'boolean':
				return '<span class="php-dumper-boolean">' . ($variable ? 'true' : 'false') . '</span>';
			case 'integer':
				return '<span class="php-dumper-integer">' . $variable . '</span>';
			case 'double':
				return '<span class="php-dumper-float">' . $variable . '</span>';
			case 'string':
				$len = strlen($variable);
				$safeString = htmlspecialchars($variable, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				if ($len > self::$maxStringLength) {
					$safeString = htmlspecialchars(substr($variable, 0, self::$maxStringLength), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '&hellip;';
				}
				return '<span class="php-dumper-string">"' . $safeString . '"</span> <span class="php-dumper-meta">(length=' . $len . ')</span>';
			case 'array':
				return self::formatArray($variable, $depth, $id);
			case 'object':
				return self::formatObject($variable, $depth, $id);
			case 'resource':
			case 'resource (closed)':
				return '<span class="php-dumper-resource">' . get_resource_type($variable) . '</span> <span class="php-dumper-meta">(resource id #' . (int)$variable . ')</span>';
			case 'NULL':
				return '<span class="php-dumper-null">null</span>';
			default:
				return '<span class="php-dumper-unknown">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span>';
		}
	}

	private static function formatArray(array &$arr, int $depth, string $id): string
	{
		$count = count($arr);
		if ($depth >= self::$maxDepth || $count === 0) {
			return '<span class="php-dumper-array-keyword">array</span><span class="php-dumper-meta">(size=' . $count . ')</span>' . ' <span class="php-dumper-punctuation">[]</span>';
		}

		$output = '<span class="php-dumper-array-keyword">array</span><span class="php-dumper-meta">(size=' . $count . ')</span> <label class="php-dumper-toggle" for="' . $id . '">▼</label><input type="checkbox" id="' . $id . '" class="php-dumper-toggle-checkbox" checked><span class="php-dumper-punctuation">[</span><div class="php-dumper-collapsible">';
		$elementsRendered = 0;
		foreach ($arr as $key => &$value) {
			if ($elementsRendered >= self::$maxArrayElements) {
				$output .= str_repeat(self::$indentation, $depth + 1) . '<span class="php-dumper-meta">&hellip; (' . ($count - $elementsRendered) . ' more elements)</span><br>';
				break;
			}
			$output .= str_repeat(self::$indentation, $depth + 1);
			$output .= '<span class="php-dumper-key">' . (is_int($key) ? $key : '"' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '"') . '</span> <span class="php-dumper-punctuation">=&gt;</span> ';
			$output .= self::formatVariable($value, $depth + 1) . '<br>';
			$elementsRendered++;
		}
		$output .= str_repeat(self::$indentation, $depth) . '</div><span class="php-dumper-punctuation">]</span>';
		return $output;
	}

	private static function formatObject(object &$obj, int $depth, string $id): string
	{
		$className = get_class($obj);
		if ($depth >= self::$maxDepth) {
			return '<span class="php-dumper-object-keyword">object</span>(<span class="php-dumper-classname">' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</span>) <span class="php-dumper-punctuation">{&hellip;}</span>';
		}

		$output = '<span class="php-dumper-object-keyword">object</span>(<span class="php-dumper-classname">' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</span>) <label class="php-dumper-toggle" for="' . $id . '">▼</label><input type="checkbox" id="' . $id . '" class="php-dumper-toggle-checkbox" checked><span class="php-dumper-punctuation">{</span><div class="php-dumper-collapsible">';

		$reflection = new ReflectionClass($obj);
		$properties = $reflection->getProperties();
		$elementsRendered = 0;

		// Handle stdClass or general iterable objects
		if (method_exists($obj, '__debugInfo')) {
			$debugData = $obj->__debugInfo();
			$count = count($debugData);
			$output = '<span class="php-dumper-object-keyword">object</span>(<span class="php-dumper-classname">' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</span>)<span class="php-dumper-meta">(size=' . $count . ')</span> <label class="php-dumper-toggle" for="' . $id . '">▼</label><input type="checkbox" id="' . $id . '" class="php-dumper-toggle-checkbox" checked><span class="php-dumper-punctuation">{</span><div class="php-dumper-collapsible">';
			
			foreach ($debugData as $key => &$value) {
				if ($elementsRendered >= self::$maxArrayElements) {
					$output .= str_repeat(self::$indentation, $depth + 1) . '<span class="php-dumper-meta">&hellip; (' . (count($debugData) - $elementsRendered) . ' more properties)</span><br>';
					break;
				}
				$output .= str_repeat(self::$indentation, $depth + 1);
				$output .= '<span class="php-dumper-property-web">debug</span> <span class="php-dumper-key">$' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '</span> <span class="php-dumper-punctuation">=</span> ';
				$output .= self::formatVariable($value, $depth + 1) . '<br>';
				$elementsRendered++;
			}
		} elseif ($obj instanceof stdClass || empty($properties)) {
			$objectVars = get_object_vars($obj);
			$count = count($objectVars);
			$output = '<span class="php-dumper-object-keyword">object</span>(<span class="php-dumper-classname">' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '</span>)<span class="php-dumper-meta">(size=' . $count . ')</span> <label class="php-dumper-toggle" for="' . $id . '">▼</label><input type="checkbox" id="' . $id . '" class="php-dumper-toggle-checkbox" checked><span class="php-dumper-punctuation">{</span><div class="php-dumper-collapsible">';

			foreach ($objectVars as $key => &$value) {
				if ($elementsRendered >= self::$maxArrayElements) {
					$output .= str_repeat(self::$indentation, $depth + 1) . '<span class="php-dumper-meta">&hellip; (' . (count($objectVars) - $elementsRendered) . ' more properties)</span><br>';
					break;
				}
				$output .= str_repeat(self::$indentation, $depth + 1);
				$output .= '<span class="php-dumper-property-web">web</span> <span class="php-dumper-key">$' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</span> <span class="php-dumper-punctuation">=</span> ';
				$output .= self::formatVariable($value, $depth + 1) . '<br>';
				$elementsRendered++;
			}
		} else {
			// Handle specific properties using reflection
			foreach ($properties as $prop) {
				if ($elementsRendered >= self::$maxArrayElements) {
					$output .= str_repeat(self::$indentation, $depth + 1) . '<span class="php-dumper-meta">&hellip; (' . (count($properties) - $elementsRendered) . ' more properties)</span><br>';
					break;
				}

				// Make property accessible to read its value
				if (!$prop->isPublic()) {
					$prop->setAccessible(true);
				}
				$value = $prop->getValue($obj);

				$output .= str_repeat(self::$indentation, $depth + 1);
				$visibility = '';
				if ($prop->isPublic()) $visibility = 'web';
				elseif ($prop->isProtected()) $visibility = 'protected';
				elseif ($prop->isPrivate()) $visibility = 'private';

				$output .= '<span class="php-dumper-property-' . $visibility . '">' . $visibility . '</span> ';
				if ($prop->isStatic()) {
					$output .= '<span class="php-dumper-modifier">static</span> ';
				}
				$output .= '<span class="php-dumper-key">$' . htmlspecialchars($prop->getName(), ENT_QUOTES, 'UTF-8') . '</span> <span class="php-dumper-punctuation">=</span> ';
				$output .= self::formatVariable($value, $depth + 1) . '<br>';
				$elementsRendered++;
			}
		}

		$output .= str_repeat(self::$indentation, $depth) . '</div><span class="php-dumper-punctuation">}</span>';
		return $output;
	}

	private static function renderCss(): string
	{
		if (self::$cssRendered && PHP_SAPI !== 'cli') {
			return '';
		}
		self::$cssRendered = true;

		$lightTheme = "
            --dumper-bg: #f8f8f8;
            --dumper-text: #222;
            --dumper-border: #ddd;
            --dumper-string: #008000; /* Green */
            --dumper-integer: #1a0dab; /* Blue */
            --dumper-float: #aa0fff; /* Purple */
            --dumper-boolean: #800080; /* Purple */
            --dumper-null: #800080; /* Purple */
            --dumper-array-keyword: #aa0fff; /* Dark Purple */
            --dumper-object-keyword: #aa0fff; /* Dark Purple */
            --dumper-resource: #555;
            --dumper-classname: #2a7f97; /* Tealish */
            --dumper-property-web: #0077aa; /* Dark Blue */
            --dumper-property-protected: #dd7800; /* Orange */
            --dumper-property-private: #cc0000; /* Red */
            --dumper-modifier: #777;
            --dumper-key: #555;
            --dumper-punctuation: #888;
            --dumper-meta: #777;
            --dumper-collapsible-bg: #fff;
        ";
		$darkTheme = "
            --dumper-bg: #2b2b2b;
            --dumper-text: #d0d0d0;
            --dumper-border: #444;
            --dumper-string: #6a8759; /* Green */
            --dumper-integer: #6897bb; /* Blue */
            --dumper-float: #ae81ff; /* Purple */
            --dumper-boolean: #cc7832; /* Orange */
            --dumper-null: #cc7832; /* Orange */
            --dumper-array-keyword: #c9c940; /* Yellowish */
            --dumper-object-keyword: #c9c940; /* Yellowish */
            --dumper-resource: #aaa;
            --dumper-classname: #a9b7c6; /* Light Grey Blue */
            --dumper-property-web: #9876aa; /* Purple */
            --dumper-property-protected: #bbb529; /* Yellow */
            --dumper-property-private: #ff6b68; /* Red */
            --dumper-modifier: #888;
            --dumper-key: #d0d0d0;
            --dumper-punctuation: #888;
            --dumper-meta: #888;
            --dumper-collapsible-bg: #303030;
        ";

		$chosenTheme = self::$theme === 'dark' ? $darkTheme : $lightTheme;

		return <<<CSS
            <style>
                .php-html-dumper {
                    {$chosenTheme}
                    background-color: var(--dumper-bg);
                    color: var(--dumper-text);
                    border: 1px solid var(--dumper-border);
                    padding: 10px;
                    margin: 10px 0;
                    font-family: 'Consolas', 'Monaco', 'Menlo', monospace;
                    font-size: 13px;
                    line-height: 1.5;
                    overflow-x: auto;
                    border-radius: 5px;
                    text-align: left;
                    direction: ltr;
                }
                .php-dumper-string { color: var(--dumper-string); }
                .php-dumper-integer { color: var(--dumper-integer); }
                .php-dumper-float { color: var(--dumper-float); }
                .php-dumper-boolean { color: var(--dumper-boolean); font-weight: bold; }
                .php-dumper-null { color: var(--dumper-null); font-weight: bold; }
                .php-dumper-array-keyword, .php-dumper-object-keyword { color: var(--dumper-array-keyword); font-weight: bold; }
                .php-dumper-resource { color: var(--dumper-resource); font-style: italic; }
                .php-dumper-unknown { color: #cc0000; }
                .php-dumper-classname { color: var(--dumper-classname); font-weight: bold; }
                .php-dumper-property-web { color: var(--dumper-property-web); }
                .php-dumper-property-protected { color: var(--dumper-property-protected); }
                .php-dumper-property-private { color: var(--dumper-property-private); }
                .php-dumper-modifier { color: var(--dumper-modifier); font-style: italic; }
                .php-dumper-key { color: var(--dumper-key); }
                .php-dumper-punctuation { color: var(--dumper-punctuation); }
                .php-dumper-meta { color: var(--dumper-meta); font-size: 0.9em; }
                .php-dumper-collapsible {
                    margin-left: 20px;
                    padding-left: 10px;
                    border-left: 1px dashed var(--dumper-border);
                    background-color: var(--dumper-collapsible-bg);
                }
                .php-dumper-toggle {
                    cursor: pointer;
                    display: inline-block;
                    margin: 0 5px;
                    font-weight: bold;
                    user-select: none;
                }
                .php-dumper-toggle-checkbox {
                    display: none; /* Hide the actual checkbox */
                }
                .php-dumper-toggle-checkbox:not(:checked) + .php-dumper-punctuation + .php-dumper-collapsible {
                    display: none;
                }
                .php-dumper-toggle-checkbox:not(:checked) ~ label.php-dumper-toggle::before {
                    content: '► '; /* Right arrow when collapsed */
                }
                 .php-dumper-toggle-checkbox:checked ~ label.php-dumper-toggle::before {
                    content: '▼ '; /* Down arrow when expanded */
                }
            </style>
            CSS;
	}

	/**
	 * Set the theme for the dumper output.
	 * @param string $theme 'light' or 'dark'
	 */
	public static function setTheme(string $theme): void
	{
		if (in_array(strtolower($theme), ['light', 'dark'])) {
			self::$theme = strtolower($theme);
			self::$cssRendered = false;
		}
	}

	/**
	 * Set max depth for dumping arrays/objects.
	 */
	public static function setMaxDepth(int $depth): void
	{
		self::$maxDepth = max(0, $depth);
	}

	/**
	 * Set max string length before truncating.
	 */
	public static function setMaxStringLength(int $length): void
	{
		self::$maxStringLength = max(0, $length);
	}

	/**
	 * Set max array/object elements to display before showing ellipsis.
	 */
	public static function setMaxElements(int $count): void
	{
		self::$maxArrayElements = max(0, $count);
	}
}
