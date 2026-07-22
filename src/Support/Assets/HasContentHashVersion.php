<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support\Assets;

/**
 * Cache-busting version = content hash of the asset. The default version
 * (composer package version, `dev-main` for path repositories) never changes
 * between releases, so browsers would keep stale copies forever.
 *
 * The PUBLISHED copy is hashed when it exists — it is what the browser is
 * actually served; hashing the source would rotate the URL before
 * `filament:assets` publishes the new content, poisoning long-lived caches
 * with old bytes under the new version string.
 */
trait HasContentHashVersion
{
    protected ?string $contentHashVersion = null;

    public function getVersion(): string
    {
        return $this->contentHashVersion ??= $this->resolveContentHashVersion();
    }

    protected function resolveContentHashVersion(): string
    {
        foreach ([$this->getPublicPath(), $this->getPath()] as $path) {
            if (is_file($path)) {
                return (string) md5_file($path);
            }
        }

        return parent::getVersion();
    }
}
