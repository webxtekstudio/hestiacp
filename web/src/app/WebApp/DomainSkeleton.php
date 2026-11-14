<?php

declare(strict_types=1);

namespace Hestia\WebApp;

use Hestia\System\HestiaApp;
use Hestia\System\Util;

use function array_filter;
use function count;
use function defined;
use function dirname;
use function in_array;
use function is_array;
use function is_dir;
use function is_link;
use function is_readable;
use function rtrim;
use function scandir;

final class DomainSkeleton
{
    private const DEFAULT_FILES = ['index.html', 'robots.txt'];

    public static function defaultSkeletonDocRoot(): string
    {
        $hestiaDir = defined('HESTIA_DIR_BIN')
            ? dirname(rtrim((string) HESTIA_DIR_BIN, '/\\'))
            : '/usr/local/hestia';

        return Util::joinPaths($hestiaDir, 'data/templates/web/skel/public_html');
    }

    public static function isClean(
        HestiaApp $appcontext,
        string $docRoot,
        ?string $skeletonDocRoot = null,
    ): bool {
        $skelRoot = $skeletonDocRoot ?? self::defaultSkeletonDocRoot();
        if (!is_dir($skelRoot) || !is_readable($skelRoot)) {
            $files = $appcontext->listFiles($docRoot);
            $unexpected = array_filter(
                $files,
                fn(string $file) => !in_array($file, self::DEFAULT_FILES, true),
            );

            return count($unexpected) === 0;
        }

        return self::isDirectoryClean($appcontext, $docRoot, $skelRoot);
    }

    public static function cleanup(
        HestiaApp $appcontext,
        string $docRoot,
        ?string $skeletonDocRoot = null,
    ): void {
        $skelRoot = $skeletonDocRoot ?? self::defaultSkeletonDocRoot();
        if (!is_dir($skelRoot) || !is_readable($skelRoot)) {
            foreach (self::DEFAULT_FILES as $defaultFile) {
                $appcontext->deleteFile(Util::joinPaths($docRoot, $defaultFile));
            }

            return;
        }

        self::cleanupDirectory($appcontext, $docRoot, $skelRoot);
    }

    private static function isDirectoryClean(
        HestiaApp $appcontext,
        string $currentDocDir,
        string $currentSkelDir,
    ): bool {
        $docEntries = $appcontext->listFiles($currentDocDir);
        $skelEntries = self::readSkeletonEntries($currentSkelDir);
        if ($skelEntries === null) {
            return count($docEntries) === 0;
        }

        foreach ($docEntries as $entry) {
            if (!in_array($entry, $skelEntries, true)) {
                return false;
            }

            $skelEntryPath = rtrim($currentSkelDir, '/\\') . '/' . $entry;
            if (is_dir($skelEntryPath) && !is_link($skelEntryPath)) {
                $nestedDocDir = Util::joinPaths($currentDocDir, $entry);
                if (!self::isDirectoryClean($appcontext, $nestedDocDir, $skelEntryPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function cleanupDirectory(
        HestiaApp $appcontext,
        string $currentDocDir,
        string $currentSkelDir,
    ): void {
        $skelEntries = self::readSkeletonEntries($currentSkelDir);
        if ($skelEntries === null) {
            return;
        }

        $docEntries = $appcontext->listFiles($currentDocDir);
        foreach ($skelEntries as $entry) {
            if (!in_array($entry, $docEntries, true)) {
                continue;
            }

            $skelEntryPath = rtrim($currentSkelDir, '/\\') . '/' . $entry;
            $docEntryPath = Util::joinPaths($currentDocDir, $entry);
            if (is_dir($skelEntryPath) && !is_link($skelEntryPath)) {
                if (self::isDirectoryClean($appcontext, $docEntryPath, $skelEntryPath)) {
                    $appcontext->deleteDirectory($docEntryPath);
                } else {
                    self::cleanupDirectory($appcontext, $docEntryPath, $skelEntryPath);
                }
            } else {
                $appcontext->deleteFile($docEntryPath);
            }
        }
    }

    /**
     * @return string[]|null
     */
    private static function readSkeletonEntries(string $skelDir): ?array
    {
        if (!is_dir($skelDir) || !is_readable($skelDir)) {
            return null;
        }

        $entries = scandir($skelDir);
        if (!is_array($entries)) {
            return null;
        }

        $filtered = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $filtered[] = $entry;
        }

        return $filtered;
    }
}
