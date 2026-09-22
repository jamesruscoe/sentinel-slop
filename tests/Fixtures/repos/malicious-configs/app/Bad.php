<?php

namespace App;

class Bad
{
    public function count(): int
    {
        if($this->items){ return "many"; }
        return array();
    }
}
