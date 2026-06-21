<?php

declare(strict_types=1);

namespace Strux\Component\View\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class StruxExtension extends AbstractExtension
{
    /**
     * Register global PHP helper functions as Twig functions.
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            // --- URL & Routing ---
            new TwigFunction('url', 'url'),
            new TwigFunction('asset', 'asset'),
            new TwigFunction('storage_url', 'storage_url'),
            new TwigFunction('route', 'route'),
            new TwigFunction('prefix', 'prefix'),

            // --- HTTP Context ---
            new TwigFunction('request', 'request'),

            // --- Authentication ---
            // Usage in Twig: {{ auth().user().firstname }} or {{ user().firstname }}
            new TwigFunction('auth', 'auth'),
            new TwigFunction('user', 'user'),

            // --- Session & Flash ---
            // Usage in Twig: {{ flash().show() | raw }}
            new TwigFunction('flash', 'flash'),

            // --- Formatting & Utilities ---
            new TwigFunction('time_ago', 'time_ago'),
            new TwigFunction('env', 'env'),

            // --- HTML Output Helpers ---
            // We add ['is_safe' => ['html']] so Twig doesn't escape the HTML tags (like <input>)
            // produced by these functions.
            new TwigFunction('csrf_token', 'csrf_token', ['is_safe' => ['html']]),
            new TwigFunction('method_override', 'method_override', ['is_safe' => ['html']]),

            // --- Debugging ---
            new TwigFunction('dump', 'dump'),
            new TwigFunction('dd', 'dd'),
        ];
    }
}