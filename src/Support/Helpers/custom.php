<?php

if (!function_exists('prefix')) {
    /**
     * Global function to return a path based on a user role
     */
    function prefix(): string
    {
        return auth()->user()->isAdmin()
            ? 'admin'
            : (auth()->user()->isAgent()
                ? 'agent'
                : 'customer');
    }
}


