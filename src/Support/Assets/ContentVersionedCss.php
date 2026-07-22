<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support\Assets;

use Filament\Support\Assets\Css;

class ContentVersionedCss extends Css
{
    use HasContentHashVersion;
}
