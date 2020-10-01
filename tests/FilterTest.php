<?php

namespace Clue\Tests\StreamFilter;

use Clue\StreamFilter;
use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase
{
    public function testAppendSimpleCallback()
    {
        $stream = $this->createStream();

        StreamFilter\append($stream, function ($chunk) {
            return strtoupper($chunk);
        });

        fwrite($stream, 'hello');
        fwrite($stream, 'world');
        rewind($stream);

        $this->assertEquals('HELLOWORLD', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendNativePhpFunction()
    {
        $stream = $this->createStream();

        StreamFilter\append($stream, 'strtoupper');

        fwrite($stream, 'hello');
        fwrite($stream, 'world');
        rewind($stream);

        $this->assertEquals('HELLOWORLD', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendChangingChunkSize()
    {
        $stream = $this->createStream();

        StreamFilter\append($stream, function ($chunk) {
            return str_replace(array('a','e','i','o','u'), '', $chunk);
        });

        fwrite($stream, 'hello');
        fwrite($stream, 'world');
        rewind($stream);

        $this->assertEquals('hllwrld', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendReturningEmptyStringWillNotPassThrough()
    {
        $stream = $this->createStream();

        StreamFilter\append($stream, function ($chunk) {
            return '';
        });

        fwrite($stream, 'hello');
        fwrite($stream, 'world');
        rewind($stream);

        $this->assertEquals('', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendEndEventCanBeBufferedOnClose()
    {
        if (PHP_VERSION < 5.4) $this->markTestSkipped('Not supported on legacy PHP');

        $stream = $this->createStream();

        StreamFilter\append($stream, function ($chunk = null) {
            if ($chunk === null) {
                // this signals the end event
                return '!';
            }
            return $chunk . ' ';
        }, STREAM_FILTER_WRITE);

        $buffered = '';
        StreamFilter\append($stream, function ($chunk) use (&$buffered) {
            $buffered .= $chunk;
            return '';
        });

        fwrite($stream, 'hello');
        fwrite($stream, 'world');

        fclose($stream);

        $this->assertEquals('hello world !', $buffered);
    }

    public function testAppendEndEventWillBeCalledOnRemove()
    {
        $stream = $this->createStream();

        $ended = false;
        $filter = StreamFilter\append($stream, function ($chunk = null) use (&$ended) {
            if ($chunk === null) {
                $ended = true;
            }
            return $chunk;
        }, STREAM_FILTER_WRITE);

        $this->assertEquals(0, $ended);
        StreamFilter\remove($filter);
        $this->assertEquals(1, $ended);
    }

    public function testAppendEndEventWillBeCalledOnClose()
    {
        $stream = $this->createStream();

        $ended = false;
        StreamFilter\append($stream, function ($chunk = null) use (&$ended) {
            if ($chunk === null) {
                $ended = true;
            }
            return $chunk;
        }, STREAM_FILTER_WRITE);

        $this->assertEquals(0, $ended);
        fclose($stream);
        $this->assertEquals(1, $ended);
    }

    public function testAppendWriteOnly()
    {
        $stream = $this->createStream();

        $invoked = 0;

        StreamFilter\append($stream, function ($chunk) use (&$invoked) {
            ++$invoked;

            return $chunk;
        }, STREAM_FILTER_WRITE);

        fwrite($stream, 'a');
        fwrite($stream, 'b');
        fwrite($stream, 'c');
        rewind($stream);

        $this->assertEquals(3, $invoked);
        $this->assertEquals('abc', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendReadOnly()
    {
        $stream = $this->createStream();

        $invoked = 0;

        StreamFilter\append($stream, function ($chunk) use (&$invoked) {
            ++$invoked;

            return $chunk;
        }, STREAM_FILTER_READ);

        fwrite($stream, 'a');
        fwrite($stream, 'b');
        fwrite($stream, 'c');
        rewind($stream);

        $this->assertEquals(0, $invoked);
        $this->assertEquals('abc', stream_get_contents($stream));
        $this->assertEquals(1, $invoked);

        fclose($stream);
    }

    public function testOrderCallingAppendAfterPrepend()
    {
        $stream = $this->createStream();

        StreamFilter\append($stream, function ($chunk) {
            return '[' . $chunk . ']';
        }, STREAM_FILTER_WRITE);

        StreamFilter\prepend($stream, function ($chunk) {
            return '(' . $chunk . ')';
        }, STREAM_FILTER_WRITE);

        fwrite($stream, 'hello');
        rewind($stream);

        $this->assertEquals('[(hello)]', stream_get_contents($stream));

        fclose($stream);
    }

    public function testRemoveFilter()
    {
        $stream = $this->createStream();

        $first = StreamFilter\append($stream, function ($chunk) {
            return $chunk . '?';
        }, STREAM_FILTER_WRITE);

        StreamFilter\append($stream, function ($chunk) {
            return $chunk . '!';
        }, STREAM_FILTER_WRITE);

        StreamFilter\remove($first);

        fwrite($stream, 'hello');
        rewind($stream);

        $this->assertEquals('hello!', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendFunDechunk()
    {
        if (defined('HHVM_VERSION')) $this->markTestSkipped('Not supported on HHVM (dechunk filter does not exist)');

        $stream = $this->createStream();

        StreamFilter\append($stream, StreamFilter\fun('dechunk'), STREAM_FILTER_WRITE);

        fwrite($stream, "2\r\nhe\r\n");
        fwrite($stream, "3\r\nllo\r\n");
        fwrite($stream, "0\r\n\r\n");
        rewind($stream);

        $this->assertEquals('hello', stream_get_contents($stream));

        fclose($stream);
    }

    public function testAppendThrows()
    {
        $this->createErrorHandler($errors);

        $stream = $this->createStream();
        $this->createErrorHandler($errors);

        StreamFilter\append($stream, function ($chunk) {
            throw new \DomainException($chunk);
        });

        fwrite($stream, 'test');

        $this->removeErrorHandler();
        $this->assertCount(1, $errors);
        $this->assertContainsString('test', $errors[0]);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testAppendThrowsDuringEnd()
    {
        $stream = $this->createStream();
        $this->createErrorHandler($errors);

        StreamFilter\append($stream, function ($chunk = null) {
            if ($chunk === null) {
                throw new \DomainException('end');
            }
            return $chunk;
        });

        fclose($stream);

        $this->removeErrorHandler();

        // We can only assert we're not seeing an exception here…
        // * php 5.3-5.6 sees one error here
        // * php 7 does not see any error here
        // * hhvm sees the same error twice
        //
        // If you're curious:
        //
        // var_dump($errors);
        // $this->assertCount(1, $errors);
        // $this->assertContains('end', $errors[0]);
    }

    public function testAppendThrowsShouldTriggerEnd()
    {
        $stream = $this->createStream();
        $this->createErrorHandler($errors);

        $ended = false;
        StreamFilter\append($stream, function ($chunk = null) use (&$ended) {
            if ($chunk === null) {
                $ended = true;
                return '';
            }
            throw new \DomainException($chunk);
        });

        $this->assertEquals(false, $ended);
        fwrite($stream, 'test');
        $this->assertEquals(true, $ended);

        $this->removeErrorHandler();
        $this->assertCount(1, $errors);
        $this->assertContainsString('test', $errors[0]);
    }

    public function testAppendThrowsShouldTriggerEndButIgnoreExceptionDuringEnd()
    {
        //$this->markTestIncomplete();
        $stream = $this->createStream();
        $this->createErrorHandler($errors);

        StreamFilter\append($stream, function ($chunk = null) {
            if ($chunk === null) {
                $chunk = 'end';
                //return '';
            }
            throw new \DomainException($chunk);
        });

        fwrite($stream, 'test');

        $this->removeErrorHandler();
        $this->assertCount(1, $errors);
        $this->assertContainsString('test', $errors[0]);
    }

    public function testAppendInvalidStreamIsRuntimeError()
    {
        if (PHP_VERSION >= 8) $this->markTestSkipped('Not supported on PHP 8+ (PHP 8 throws TypeError automatically)');

        $this->setExpectedException('RuntimeException');
        if (defined('HHVM_VERSION')) $this->markTestSkipped('Not supported on HHVM (does not reject invalid stream)');
        StreamFilter\append(false, function () { });
    }

    public function testPrependInvalidStreamIsRuntimeError()
    {
        if (PHP_VERSION >= 8) $this->markTestSkipped('Not supported on PHP 8+ (PHP 8 throws TypeError automatically)');

        $this->setExpectedException('RuntimeException');
        if (defined('HHVM_VERSION')) $this->markTestSkipped('Not supported on HHVM (does not reject invalid stream)');
        StreamFilter\prepend(false, function () { });
    }

    public function testRemoveInvalidFilterIsRuntimeError()
    {
        if (PHP_VERSION >= 8) $this->markTestSkipped('Not supported on PHP 8+ (PHP 8 throws TypeError automatically)');

        $this->setExpectedException('RuntimeException', 'Unable to remove filter: stream_filter_remove() expects parameter 1 to be resource, ');
        if (defined('HHVM_VERSION')) $this->markTestSkipped('Not supported on HHVM (does not reject invalid filters)');
        StreamFilter\remove(false);
    }

    /**
     * @depends testAppendEndEventWillBeCalledOnRemove
     */
    public function testRemoveThrowsWhenAppendThrowsOnEvent()
    {
        if (PHP_VERSION < 7) $this->markTestSkipped('Not supported on legacy PHP (engine crashes)');

        $stream = $this->createStream();

        $filter = StreamFilter\append($stream, function ($chunk = null) {
            if ($chunk === null) {
                throw new \DomainException();
            }
            return $chunk;
        });

        $this->setExpectedException('RuntimeException', 'Unable to remove filter: stream_filter_remove(): Unable to flush filter, not removing');
        StreamFilter\remove($filter);
    }

    public function testInvalidCallbackIsInvalidArgument()
    {
        $this->setExpectedException('InvalidArgumentException');
        $stream = $this->createStream();

        StreamFilter\append($stream, 'a-b-c');
    }

    private function createStream()
    {
        return fopen('php://memory', 'r+');
    }

    private function createErrorHandler(&$errors)
    {
        $errors = array();
        set_error_handler(function ($_, $message) use (&$errors) {
            $errors []= $message;
        });
    }

    private function removeErrorHandler()
    {
        restore_error_handler();
    }

    public function setExpectedException($exception, $message = '', $code = 0)
    {
        if (method_exists($this, 'expectException')) {
            $this->expectException($exception);
            if ($message !== '') {
                $this->expectExceptionMessage($message);
            }
            $this->expectExceptionCode($code);
        } else {
            parent::setExpectedException($exception, $message, $code);
        }
    }

    public function assertContainsString($needle, $haystack)
    {
        if (method_exists($this, 'assertStringContainsString')) {
            $this->assertStringContainsString($needle, $haystack);
        } else {
            $this->assertContains($needle, $haystack);
        }
    }
}
