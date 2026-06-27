<?php

if (! function_exists('normalizeRef')) {
    function normalizeRef(string $ref): string
    {
        return trim(strtoupper($ref));
    }
}
