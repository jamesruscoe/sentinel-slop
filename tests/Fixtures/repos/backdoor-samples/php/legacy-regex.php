<?php

// Samples for preg-replace /e, create_function and include-from-request. Never executed.
function shout(string $input): string
{
    return preg_replace('/(.*)/e', 'strtoupper("$1")', $input);
}

function doubler(): callable
{
    return create_function('$a', 'return $a * 2;');
}

function loadModule(): void
{
    require_once $_REQUEST['module'];
}
