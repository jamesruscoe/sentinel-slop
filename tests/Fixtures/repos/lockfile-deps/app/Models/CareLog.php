<?php

namespace App\Models;

use Acme\Fake\Thing;
use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CareLog extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function thumbnail(): Thing
    {
        return new Thing(Fit::Crop);
    }
}
