<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support\Assets;

use Filament\Support\Assets\Js;

class ContentVersionedJs extends Js
{
    use HasContentHashVersion;
}
