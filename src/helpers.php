<?php

namespace Amp\File\Additionals;

use Amp\Emitter;
use Amp\File\File;
use Amp\File\Filesystem;
use Amp\File\FilesystemException;
use Amp\Iterator;
use Amp\Promise;
use Throwable;
use function Amp\asyncCall;
use function Amp\call;

/**
 * @param Filesystem $filesystem
 * @param string $path
 *
 * @return Iterator<array>
 */
function recursiveDirectoryListing(Filesystem $filesystem, string $path): Iterator
{
    $emitter = new Emitter();

    asyncCall(static function (Filesystem $filesystem, string $sourcePath) use (&$emitter) {
        $sourcePath = \str_replace('//', '/', $sourcePath . DIRECTORY_SEPARATOR);

        try {
            if (false === (yield ($filesystem->isDirectory($sourcePath)))) {
                throw new FilesystemException("Path {$sourcePath} is not valid directory");
            }

            $queue = yield $filesystem->listFiles($sourcePath);

            while (\sizeof($queue) > 0) {
                $relativePath = \array_shift($queue);
                $fullPath = \str_replace('//', '/', $sourcePath . $relativePath);

                /** @var bool $isDirectory */
                $isDirectory = yield $filesystem->isDirectory($fullPath);
                /** @var string|null $followSymlink */
                $followSymlink = null;

                if (true === yield $filesystem->isSymlink($fullPath)) {
                    $followSymlink = yield $filesystem->resolveSymlink($fullPath);
                }

                $resultItem = [
                    'relative_path' => $relativePath,
                    'full_path' => $fullPath,
                ];

                if ($isDirectory) {
                    $resultItem['is_directory'] = $isDirectory;
                }

                if ($followSymlink) {
                    $resultItem['follow_symlink'] = $followSymlink;
                }

                yield $emitter->emit($resultItem);

                if (!$followSymlink && $isDirectory) {
                    foreach (\array_reverse(yield $filesystem->listFiles($fullPath)) as $subPath) {
                        \array_unshift($queue, $relativePath . DIRECTORY_SEPARATOR . $subPath);
                    }
                }
            }
        } catch (Throwable $exception) {
            $emitter->fail($exception);
        } finally {
            $emitter->complete();
        }
    }, $filesystem, $path);

    return $emitter->iterate();
}

/**
 * @param Filesystem $filesystem
 * @param string $path
 *
 * @return Promise<void>
 */
function recursiveDeleteDirectory(Filesystem $filesystem, string $path): Promise
{
    return call(static function (Filesystem $filesystem, string $sourcePath) {
        $sourcePath = \str_replace('//', '/', $sourcePath . DIRECTORY_SEPARATOR);

        $directoryEntryLevels = [];

        $iterator = recursiveDirectoryListing($filesystem, $sourcePath);
        while (true === yield $iterator->advance()) {
            /** @var array $item */
            $item = $iterator->getCurrent();

            if (!isset($item['is_directory']) || isset($item['follow_symlink'])) {
                yield $filesystem->deleteFile($item['full_path']);
            } else {
                $directoryEntryLevel = \count_chars($item['relative_path'], 1);
                $directoryEntryLevel = $directoryEntryLevel[\ord(DIRECTORY_SEPARATOR)] ?? 0;

                if (!isset($directoryEntryLevels[$directoryEntryLevel])) {
                    $directoryEntryLevels[$directoryEntryLevel] = [];
                }

                $directoryEntryLevels[$directoryEntryLevel][] = $item['relative_path'];
            }
        }

        foreach (\array_reverse($directoryEntryLevels, true) as $arr) {
            foreach ($arr as $v) {
                if (false === yield $filesystem->isDirectory($sourcePath . $v)) {
                    continue;
                }
                yield $filesystem->deleteDirectory($sourcePath . $v);
            }
        }

        if (true === yield $filesystem->isDirectory($sourcePath)) {
            yield $filesystem->deleteDirectory($sourcePath);
        }
    }, $filesystem, $path);
}

/**
 * @param Filesystem $source
 * @param Filesystem $target
 *
 * @return Promise<bool>
 */
function checkIsEqualFilesystems(Filesystem $source, Filesystem $target): Promise
{
    return call(static function () use ($source, $target) {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'tmp-');
        $tmpContent = \uniqid();

        yield $source->write($tmpFile, $tmpContent);

        try {
            return true === (yield $target->isFile($tmpFile)) && $tmpContent === (yield $target->read($tmpFile));
        } finally {
            yield $source->deleteFile($tmpFile);
        }
    });
}

/**
 * @param string $sourcePath
 * @param Filesystem $sourceFilesystem
 * @param string $targetPath
 * @param Filesystem $targetFilesystem
 *
 * @return Promise<void>
 */
function moveToAnotherFilesystem(string $sourcePath, Filesystem $sourceFilesystem, string $targetPath, Filesystem $targetFilesystem): Promise
{
    return call(static function () use ($sourcePath, $sourceFilesystem, $targetPath, $targetFilesystem) {
        if (false === yield $sourceFilesystem->exists($sourcePath)) {
            throw new FilesystemException("Can't find source path {$sourcePath}");
        }

        /*
        if (true === yield checkIsEqualFilesystems($sourceFilesystem, $targetFilesystem)) {
            return $sourceFilesystem->move($sourcePath, $targetPath);
        }
        */

        if (false === yield $targetFilesystem->isDirectory($targetPath)) {
            yield $targetFilesystem->createDirectoryRecursively($targetPath, 0750);
        }

        $copyFile = static function (string $sourcePath, Filesystem $sourceFilesystem, string $targetPath, Filesystem $targetFilesystem): \Generator {
            /** @var File $fhSourceReader */
            $fhSourceReader = yield $sourceFilesystem->openFile($sourcePath, 'rb');
            /** @var File $fhTargetWriter */
            $fhTargetWriter = yield $targetFilesystem->openFile($targetPath, 'wb');

            try {
                while (!$fhSourceReader->eof()) {
                    /** @var string|null $data */
                    $data = yield $fhSourceReader->read();

                    if ($data === null) {
                        break;
                    }

                    yield $fhTargetWriter->write($data);
                }

                $fhSourceReader->close();
                $fhTargetWriter->close();
            } catch (Throwable $exception) {
                $fhSourceReader->close();
                $fhTargetWriter->close();

                if (true === yield $targetFilesystem->isFile($targetPath)) {
                    yield $targetFilesystem->deleteFile($targetPath);
                }

                throw $exception;
            }
        };

        if (true === yield $sourceFilesystem->isFile($sourcePath)) {
            yield call($copyFile, $sourcePath, $sourceFilesystem, $targetPath, $targetFilesystem);
            return;
        }

        $symlinks = [];

        $iterator = recursiveDirectoryListing($sourceFilesystem, $sourcePath);
        while (true === yield $iterator->advance()) {
            /** @var array $item */
            $item = $iterator->getCurrent();

            if (isset($item['follow_symlink'])) {
                $symlinks[] = $item;
                continue;
            }

            if (isset($item['is_directory'])) {
                if (false === yield $targetFilesystem->isDirectory($targetPath . DIRECTORY_SEPARATOR . $item['relative_path'])) {
                    yield $targetFilesystem->createDirectoryRecursively($targetPath . DIRECTORY_SEPARATOR . $item['relative_path'], 0750);
                }
                continue;
            }

            yield call($copyFile, $sourcePath . DIRECTORY_SEPARATOR . $item['relative_path'], $sourceFilesystem, $targetPath . DIRECTORY_SEPARATOR . $item['relative_path'], $targetFilesystem);
        }

        foreach ($symlinks as $item) {
            $original = \str_replace($sourcePath, $targetPath, $item['follow_symlink']);
            yield $targetFilesystem->createSymlink($original, $targetPath . DIRECTORY_SEPARATOR .  $item['relative_path']);
        }
    });
}
