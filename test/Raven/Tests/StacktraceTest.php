<?php

/*
 * This file is part of Raven.
 *
 * (c) Sentry Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

function raven_test_recurse($times, $callback)
{
    $times -= 1;
    if ($times > 0) {
        return call_user_func('raven_test_recurse', $times, $callback);
    }

    return call_user_func($callback);
}

function raven_test_create_stacktrace($args=null, $times=3)
{
    return raven_test_recurse($times, 'debug_backtrace');
}

class Raven_Tests_StacktraceTest extends PHPUnit_Framework_TestCase
{
    public function testCanTraceParamContext()
    {
        $stack = raven_test_create_stacktrace(array('biz', 'baz'), 0);

        $frame = $stack[2];
        $params = Raven_Stacktrace::get_frame_context($frame);
        $this->assertEquals($params['args'], array('biz', 'baz'));
        $this->assertEquals($params['times'], 0);
    }

    public function testSimpleTrace()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
                "args"=> array(
                    "friend",
                ),
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "args"=> array(
                    "/tmp/a.php",
                ),
                "function" => "include_once",
            ),
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true);

        $frame = $frames[0];
        $this->assertEquals('b.php', $frame["module"]);
        $this->assertEquals(3, $frame["lineno"]);
        $this->assertNull($frame["function"]);
        $this->assertEquals("include_once '/tmp/a.php';", $frame["context_line"]);
        $frame = $frames[1];
        $this->assertEquals('a.php', $frame["module"]);
        $this->assertEquals(11, $frame["lineno"]);
        $this->assertEquals('include_once', $frame["function"]);
        $this->assertEquals('a_test($foo);', $frame["context_line"]);
    }

    public function testSimpleUnshiftedTrace()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
                "args"=> array(
                    "friend",
                ),
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "args"=> array(
                    "/tmp/a.php",
                ),
                "function" => "include_once",
            ),
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true, false);

        $frame = $frames[0];
        $this->assertEquals('b.php', $frame["module"]);
        $this->assertEquals(3, $frame["lineno"]);
        $this->assertNull($frame["function"]);
        $this->assertEquals('/tmp/a.php', $frame['vars']['param1']);
        $this->assertEquals("include_once '/tmp/a.php';", $frame["context_line"]);
        $frame = $frames[1];
        $this->assertEquals('a.php', $frame["module"]);
        $this->assertEquals(11, $frame["lineno"]);
        $this->assertEquals('include_once', $frame["function"]);
        $this->assertEquals('friend', $frame['vars']['param1']);
        $this->assertEquals('a_test($foo);', $frame["context_line"]);
    }

    public function testShiftedCaptureVars()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
                "args"=> array(
                    "friend",
                ),
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "args"=> array(
                    "/tmp/a.php",
                ),
                "function" => "include_once",
            ),
        );

        $vars = array(
            "foo" => "bar",
            "baz" => "zoom"
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true, true, $vars);

        $frame = $frames[0];
        $this->assertEquals('b.php', $frame["module"]);
        $this->assertEquals(3, $frame["lineno"]);
        $this->assertNull($frame["function"]);
        $this->assertEquals("include_once '/tmp/a.php';", $frame["context_line"]);
        $this->assertFalse(isset($frame['vars']));
        $frame = $frames[1];
        $this->assertEquals('a.php', $frame["module"]);
        $this->assertEquals(11, $frame["lineno"]);
        $this->assertEquals('include_once', $frame["function"]);
        $this->assertEquals('a_test($foo);', $frame["context_line"]);
        $this->assertEquals($vars, $frame['vars']);
    }

    public function testDoesNotModifyCaptureVars()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
                "args"=> array(
                    "friend",
                ),
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "args"=> array(
                    "/tmp/a.php",
                ),
                "function" => "include_once",
            ),
        );

        // PHP's errcontext as passed to the error handler contains REFERENCES to any vars that were in the global scope.
        // Modification of these would be really bad, since if control is returned (non-fatal error) we'll have altered the state of things!
        $originalFoo = "bloopblarp";
        $iAmFoo = $originalFoo;
        $vars = array(
            "foo" => &$iAmFoo
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true, true, $vars, 5);

        // Check we haven't modified our vars.
        $this->assertEquals($originalFoo, $vars["foo"]);

        $frame = $frames[1];
        // Check that we did truncate the variable in our output
        $this->assertEquals(5, strlen($frame['vars']['foo']));
    }

    public function testUnshiftedCaptureVars()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
                "args"=> array(
                    "friend",
                ),
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "args"=> array(
                    "/tmp/a.php",
                ),
                "function" => "include_once",
            ),
        );

        $vars = array(
            "foo" => "bar",
            "baz" => "zoom"
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true, false, $vars);

        $frame = $frames[0];
        $this->assertEquals('b.php', $frame["module"]);
        $this->assertEquals(3, $frame["lineno"]);
        $this->assertNull($frame["function"]);
        $this->assertEquals(array('param1' => '/tmp/a.php'), $frame['vars']);
        $this->assertEquals("include_once '/tmp/a.php';", $frame["context_line"]);
        $frame = $frames[1];
        $this->assertEquals('a.php', $frame["module"]);
        $this->assertEquals(11, $frame["lineno"]);
        $this->assertEquals('include_once', $frame["function"]);
        $this->assertEquals($vars, $frame['vars']);
        $this->assertEquals('a_test($foo);', $frame["context_line"]);
    }

    public function testDoesFixFrameInfo()
    {
        /**
         * PHP's way of storing backstacks seems bass-ackwards to me
         * 'function' is not the function you're in; it's any function being
         * called, so we have to shift 'function' down by 1. Ugh.
         */
        $stack = raven_test_create_stacktrace();

        $frames = Raven_Stacktrace::get_stack_info($stack, true);
        // just grab the last few frames
        $frames = array_slice($frames, -5);
        $frame = $frames[0];
        $this->assertEquals('StacktraceTest.php:Raven_Tests_StacktraceTest', $frame['module']);
        $this->assertEquals('testDoesFixFrameInfo', $frame['function']);
        $frame = $frames[1];
        $this->assertEquals('StacktraceTest.php', $frame['module']);
        $this->assertEquals('raven_test_create_stacktrace', $frame['function']);
        $frame = $frames[2];
        $this->assertEquals('StacktraceTest.php', $frame['module']);
        $this->assertEquals('raven_test_recurse', $frame['function']);
        $frame = $frames[3];
        $this->assertEquals('StacktraceTest.php', $frame['module']);
        $this->assertEquals('raven_test_recurse', $frame['function']);
        $frame = $frames[4];
        $this->assertEquals('StacktraceTest.php', $frame['module']);
        $this->assertEquals('raven_test_recurse', $frame['function']);
    }

    public function testInApp()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "function" => "include_once",
            ),
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true, null, null, 0, null, dirname(__FILE__));

        $this->assertEquals($frames[0]['in_app'], true);
        $this->assertEquals($frames[1]['in_app'], true);
    }

    public function testBasePath()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
            ),
            array(
                "file" => dirname(__FILE__) . "/resources/b.php",
                "line" => 3,
                "function" => "include_once",
            ),
        );

        $frames = Raven_Stacktrace::get_stack_info($stack, true, null, null, 0, array(dirname(__FILE__)));

        $this->assertEquals($frames[0]['filename'], 'resources/b.php');
        $this->assertEquals($frames[1]['filename'], 'resources/a.php');
    }

    public function testNoBasePath()
    {
        $stack = array(
            array(
                "file" => dirname(__FILE__) . "/resources/a.php",
                "line" => 11,
                "function" => "a_test",
            ),
        );

        $frames = Raven_Stacktrace::get_stack_info($stack);
        $this->assertEquals($frames[0]['filename'], dirname(__FILE__) . '/resources/a.php');
    }

    public function testWithEvaldCode()
    {
        try {
            eval("throw new Exception('foobar');");
        } catch (Exception $ex) {
            $trace = $ex->getTrace();
            $frames = Raven_Stacktrace::get_stack_info($trace);
        }
        $this->assertEquals($frames[count($frames) -1]['filename'], __FILE__);
    }
}
