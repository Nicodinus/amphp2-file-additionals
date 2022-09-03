<?php

namespace Amp\File\Additionals\Test;

use Amp\File;
use Amp\PHPUnit\AsyncTestCase;
use function Amp\Iterator\toArray;

class MoveToAnotherFilesystemTest extends AsyncTestCase
{
    /**
     * @return string
     */
    protected function getTempPath(): string
    {
        return \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amphp2-file-additionals';
    }

    /**
     * @return File\Filesystem
     */
    protected function getFilesystem(): File\Filesystem
    {
        return File\filesystem();
    }

    /**
     * @return \string[][]
     */
    protected function getTestData(): array
    {
        return [
            ['type' => 'directory', 'path' => '/dir1'],
            ['type' => 'directory', 'path' => '/dir1/dir1'],
            ['type' => 'file', 'path' => '/dir1/dir1/file1', 'content' => '/dir1/dir1/file1 content'],
            ['type' => 'file', 'path' => '/dir1/dir1/file2', 'content' => '/dir1/dir1/file2 content'],
            ['type' => 'symlink', 'path' => '/dir1/dir1/file3', 'target' => '/dir1/dir1/file1'],
            ['type' => 'symlink', 'path' => '/dir1/dir1/file4', 'target' => '/dir1/dir1/file2'],
            ['type' => 'symlink', 'path' => '/dir1/dir1/dir1', 'target' => '/'],

            ['type' => 'directory', 'path' => '/dir1/dir2'],
            ['type' => 'file', 'path' => '/dir1/dir2/file1', 'content' => '/dir1/dir2/file1 content'],
            ['type' => 'symlink', 'path' => '/dir1/dir2/file2', 'target' => '/dir1/dir1/file1'],

            ['type' => 'file', 'path' => '/dir1/file1', 'content' => '/dir1/file1 content'],
            ['type' => 'symlink', 'path' => '/dir1/file2', 'target' => '/dir1/dir2/file2'],
        ];
    }

    /**
     * @return \Generator
     */
    protected function createTestData(): \Generator
    {
        if (false === yield $this->getFilesystem()->isDirectory($this->getTempPath())) {
            yield $this->getFilesystem()->createDirectoryRecursively($this->getTempPath());
        }

        foreach ($this->getTestData() as $testDataItem) {
            switch ($testDataItem['type']) {
                case 'directory':
                    if (true === yield $this->getFilesystem()->exists($this->getTempPath() . $testDataItem['path'])) {
                        break;
                    }
                    yield $this->getFilesystem()->createDirectoryRecursively($this->getTempPath() . $testDataItem['path']);
                    break;
                case 'file':
                    if (true === yield $this->getFilesystem()->exists($this->getTempPath() . $testDataItem['path'])) {
                        break;
                    }
                    yield $this->getFilesystem()->write($this->getTempPath() . $testDataItem['path'], $testDataItem['content']);
                    break;
                case 'symlink':
                    if (true === yield $this->getFilesystem()->exists($this->getTempPath() . $testDataItem['path'])) {
                        break;
                    }
                    yield $this->getFilesystem()->createSymlink($this->getTempPath() . $testDataItem['target'], $this->getTempPath() . $testDataItem['path']);
                    break;
                default:
                    throw new \Error("Invalid testData item!");
            }
        }
    }

    /**
     * @return \Generator
     */
    protected function removeTestData(): \Generator
    {
        if (true === yield $this->getFilesystem()->isDirectory($this->getTempPath())) {
            yield File\Additionals\recursiveDeleteDirectory($this->getFilesystem(), $this->getTempPath());
        }
    }

    /**
     * @return \Generator
     */
    protected function setUpAsync(): \Generator
    {
        return $this->createTestData();
    }

    /**
     * @return \Generator
     */
    protected function tearDownAsync(): \Generator
    {
        return $this->removeTestData();
    }

    /**
     * @return \Generator
     */
    public function testMoveToSameLocalFilesystem(): \Generator
    {
        $targetPath = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'testMoveToAnotherFilesystemResult';

        try {
            yield File\Additionals\moveToAnotherFilesystem($this->getTempPath(), $this->getFilesystem(), $targetPath, $this->getFilesystem());

            $iterator = File\Additionals\recursiveDirectoryListing($this->getFilesystem(), $this->getTempPath());
            $result = yield toArray($iterator);

            $this->assertSameSize($this->getTestData(), $result);
        } finally {
            yield File\Additionals\recursiveDeleteDirectory($this->getFilesystem(), $targetPath);
        }
    }

    /**
     * @return \Generator
     */
    public function testMoveToAnotherFilesystem(): \Generator
    {
        $this->markTestSkipped("Not implemented yet!");
    }
}
