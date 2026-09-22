<?php

// Sample for sentinel.malware.php.eval-of-decoded-payload. Never executed.
function applySeed(array $config): void
{
    $blob = base64_decode($config['seed']);
    eval($blob);
}

function applyRotated(string $text): void
{
    eval(str_rot13($text));
}
