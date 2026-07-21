<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support\Assets;

use Filament\Support\Assets\Css;

/**
 * Css asset whose cache-busting version is the file's content hash — the
 * default (composer package version, `dev-main` for path repositories) never
 * changes between releases, so browsers would keep stale copies forever.
 */
class ContentVersionedCss extends Css
{
    public function getVersion(): string
    {
        return is_file($this->getPath())
            ? (string) md5_file($this->getPath())
            : parent::getVersion();
    }
}
