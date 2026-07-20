<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support;

use Illuminate\Support\Collection;
use RalphJSmit\Filament\Explore\Data\FileData;

/**
 * Per-request collector for files created by an upload driver.
 *
 * The explore package fires no events when files are created, so drivers using
 * {@see \Mmoollllee\FilamentMediaLibraryExtensions\Drivers\Concerns\HasMediaLibraryExtensions}
 * record every created file here. Upload actions consume the collected files
 * after the upload to auto-select them. Registered as a scoped singleton.
 */
class CreatedFilesCollector
{
    /**
     * @var array<int, FileData>
     */
    protected array $files = [];

    public function record(FileData $file): void
    {
        $this->files[] = $file;
    }

    /**
     * @return Collection<int, FileData>
     */
    public function consume(): Collection
    {
        $files = new Collection($this->files);

        $this->files = [];

        return $files;
    }
}
