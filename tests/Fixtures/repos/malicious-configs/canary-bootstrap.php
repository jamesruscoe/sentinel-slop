<?php
// Executed only if a tool loads this repo's config. It must never run.
file_put_contents(__DIR__ . "/CANARY_PHPSTAN", "phpstan loaded repo config");
file_put_contents(sys_get_temp_dir() . "/sentinel-canary-php", "php canary");
