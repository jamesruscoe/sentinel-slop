<?php
file_put_contents(__DIR__ . "/CANARY_PINT", "php-cs-fixer config loaded");
return (new PhpCsFixer\Config())->setRules([]);
