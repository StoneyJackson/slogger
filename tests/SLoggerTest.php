<?php

// Report all errors.
error_reporting(-1);
ini_set('display_errors', 1);
ini_set('display_startup_errors' , 1);

// Import the class we're testing.
require '../src/SLogger.php';

/**
 * Test SLogger.php
 */
class SLoggerTest extends PHPUnit_Framework_TestCase
{

    public function test_functional() {
        date_default_timezone_set('UTC');

        $args1 = array('a' => array('b' => 'c'), 'd');
        $args2 = NULL;

        SLogger::add(array(
            'default' => array(
                dirname(__FILE__),
                'dateFormat' => 'Y-m-d H:i:s.u',
                'severityThreshold' => SLogger::DEBUG,
            ),
            'logTest' => array('../logTest'),
        ));

        $log = SLogger::get();

        $log->info('Info Test');
        $log->notice('Notice Test');
        $log->warning('Warn Test');
        $log->error('Error Test');
        $log->critical('Crit test');
        $log->alert('Alert test');
        $log->emergency('Emerg Test');
        $log->info('Testing passing an array or object', $args1);
        $log->warning('Testing passing a NULL value', $args2);

        # Log from a function
        foo();

        # Log from methods
        A::baz();
        $a = new A();
        $a->bar();

        # Log from another file
        require 'SLoggerTestHelper.php';

        echo "\n\n***** Check log_*.csv and ../logTest/log_*.csv to confirm results. *****\n\n";
    }
}

# Log from function
function foo() {
    SLogger::get('logTest')->debug('Log from function.');
}

# Log from methods
class A {
    function bar() {
        SLogger::get('logTest')->debug('Log from instance method.');
    }
    static function baz() {
        SLogger::get('logTest')->debug('Log from static method.');
    }
}

class TestException extends Exception {}
