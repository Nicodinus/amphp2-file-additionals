<?php

namespace Amp\File\Additionals\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\File;
use Amp\Success;

class CheckIsEqualFilesystemsTest extends AsyncTestCase
{
    /**
     * @return File\Filesystem
     */
    protected function getFilesystem(): File\Filesystem
    {
        return File\filesystem();
    }

    /**
     * @return \Generator
     */
    public function testCheckIsEqualFilesystems(): \Generator
    {
        $this->assertTrue(yield File\Additionals\checkIsEqualFilesystems($this->getFilesystem(), $this->getFilesystem()));

        $nullDriver = $this->createMock(File\Driver::class);
        $nullDriver->method('getStatus')->willReturn(new Success(null));
        $nullFilesystem = new File\Filesystem($nullDriver);

        $this->assertFalse(yield File\Additionals\checkIsEqualFilesystems($this->getFilesystem(), $nullFilesystem));
    }
}