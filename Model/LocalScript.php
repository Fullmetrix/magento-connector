<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

class LocalScript
{
    public const DIRECTORY = 'kvo';
    public const LEGACY_DIRECTORY = 'fullmetrix';

    private const SCRIPT_PATTERN = '/^[0-9a-f]{12}\.js$/';
    private const TEMPORARY_PATTERN = '/^\.[0-9a-f]{16}\.tmp$/';
    private const LEGACY_SCRIPT_PATTERN = '/^fm-[0-9a-f]{12}\.js$/';
    private const LEGACY_TEMPORARY_PATTERN = '/^\.fm-[0-9a-f]{16}\.tmp$/';
    private const OWNED = [
        self::DIRECTORY => [self::SCRIPT_PATTERN, self::TEMPORARY_PATTERN],
        self::LEGACY_DIRECTORY => [self::LEGACY_SCRIPT_PATTERN, self::LEGACY_TEMPORARY_PATTERN],
    ];
    private const INDEX = 'index.html';
    private const TEMPORARY_LIFETIME_SECONDS = 3600;
    private const MIN_AGE_SECONDS = 604800;
    private const PAGE_CACHE_MARGIN_SECONDS = 86400;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Tells whether a path names a tracker file this module writes.
     *
     * @param string $path
     * @return bool
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function isScriptPath(string $path): bool
    {
        $prefix = self::DIRECTORY . '/';

        return str_starts_with($path, $prefix)
            && 1 === preg_match(self::SCRIPT_PATTERN, substr($path, \strlen($prefix)));
    }

    /**
     * Returns the media path of the tracker file of a version.
     *
     * @param string $version
     * @return string
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function pathFor(string $version): string
    {
        return self::DIRECTORY . '/' . $version . '.js';
    }

    /**
     * Makes the tracker file of a version hold exactly the given content, written atomically.
     *
     * @param string $version
     * @param string $content
     * @param string $sha256
     * @return string The media path of the file.
     */
    public function install(string $version, string $content, string $sha256): string
    {
        $media = $this->media();
        if (!$media->isDirectory(self::DIRECTORY)) {
            $media->create(self::DIRECTORY);
        }
        $index = self::DIRECTORY . '/' . self::INDEX;
        if (!$media->isExist($index)) {
            $media->writeFile($index, '');
        }

        $path = self::pathFor($version);
        if ($media->isFile($path) && hash_equals($sha256, hash('sha256', (string) $media->readFile($path)))) {
            return $path;
        }

        $temporary = self::DIRECTORY . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $media->writeFile($temporary, $content);
            if (!hash_equals($sha256, hash('sha256', (string) $media->readFile($temporary)))) {
                throw new \RuntimeException('Le fichier écrit ne correspond pas au script reçu.');
            }
            $media->renameFile($temporary, $path);
        } catch (\Throwable $e) {
            $this->deleteQuietly($media, $temporary);
            throw $e;
        }

        return $path;
    }

    /**
     * Marks a file as the one being served from now on, so its replacement date is known later.
     *
     * @param string $path
     * @return void
     */
    public function markCurrent(string $path): void
    {
        $this->media()->touch($path);
    }

    /**
     * Deletes abandoned temporary files and the versions older than a week that no cached page still references.
     *
     * @param string $current
     * @param string $previous
     * @param int $pageCacheTtl
     * @return void
     */
    public function prune(string $current, string $previous, int $pageCacheTtl): void
    {
        $media = $this->media();
        $now = time();
        $scripts = [];
        foreach ($this->names($media, self::DIRECTORY) as $name) {
            $path = self::DIRECTORY . '/' . $name;
            if (1 === preg_match(self::TEMPORARY_PATTERN, $name)) {
                if ($this->modifiedAt($media, $path) < $now - self::TEMPORARY_LIFETIME_SECONDS) {
                    $this->deleteQuietly($media, $path);
                }
                continue;
            }
            if (1 === preg_match(self::SCRIPT_PATTERN, $name)) {
                $scripts[] = ['path' => $path, 'mtime' => $this->modifiedAt($media, $path)];
            }
        }

        usort($scripts, static fn (array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);
        $protected = [$current, $previous];
        $writtenBefore = $now - self::MIN_AGE_SECONDS;
        $replacedBefore = $now - $pageCacheTtl - self::PAGE_CACHE_MARGIN_SECONDS;
        foreach ($scripts as $index => $script) {
            $replacedAt = $scripts[$index + 1]['mtime'] ?? $now;
            if (!\in_array($script['path'], $protected, true)
                && $script['mtime'] < $writtenBefore
                && $replacedAt < $replacedBefore
            ) {
                $this->deleteQuietly($media, $script['path']);
            }
        }
    }

    /**
     * Deletes the files this module wrote, here and in the directory of the versions before 1.8.0.
     *
     * A directory goes only once nothing else is left in it, an empty index page included.
     *
     * @return void
     */
    public function removeAll(): void
    {
        $media = $this->media();
        foreach (self::OWNED as $directory => [$scriptPattern, $temporaryPattern]) {
            if (!$media->isDirectory($directory)) {
                continue;
            }
            foreach ($this->names($media, $directory) as $name) {
                if (1 === preg_match($scriptPattern, $name) || 1 === preg_match($temporaryPattern, $name)) {
                    $this->deleteQuietly($media, $directory . '/' . $name);
                }
            }
            $index = $directory . '/' . self::INDEX;
            if ([self::INDEX] === $this->names($media, $directory) && $media->isFile($index)
                && '' === (string) $media->readFile($index)
            ) {
                $this->deleteQuietly($media, $index);
            }
            if ([] === $this->names($media, $directory)) {
                $this->deleteQuietly($media, $directory);
            }
        }
    }

    /**
     * Returns the names of the entries of a media directory.
     *
     * @param WriteInterface $media
     * @param string $directory
     * @return string[]
     */
    private function names(WriteInterface $media, string $directory): array
    {
        $names = [];
        foreach ($media->read($directory) as $entry) {
            $parts = explode('/', (string) $entry);
            $names[] = (string) end($parts);
        }

        return $names;
    }

    /**
     * Returns the writable media directory.
     *
     * @return WriteInterface
     */
    private function media(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Returns the modification time of a file, 0 when unknown.
     *
     * @param WriteInterface $media
     * @param string $path
     * @return int
     */
    private function modifiedAt(WriteInterface $media, string $path): int
    {
        try {
            $stat = $media->stat($path);
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($stat['mtime'] ?? 0);
    }

    /**
     * Deletes a file or an empty directory, a failure only leaves it for the next run.
     *
     * @param WriteInterface $media
     * @param string $path
     * @return void
     */
    private function deleteQuietly(WriteInterface $media, string $path): void
    {
        try {
            if ($media->isExist($path)) {
                $media->delete($path);
            }
        // The file stays until the next run, nothing else depends on it.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Throwable) {
        }
    }
}
