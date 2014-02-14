# SLogger

A PHP Logger

## Features

* [Smart logging](http://blog.dynom.nl/archives/Logging-best-practices_20120304_63.html)
* Support for microseconds (via [udate](http://php.net/manual/en/datetime.format.php))
* [Auto log rotation based on file size](https://github.com/katzgrau/KLogger/pull/14)
* Auto deletion of old log files
* [CSV log format](http://en.wikipedia.org/wiki/Comma-separated_values)
* [Context and location information](https://github.com/katzgrau/KLogger/pull/6)
* Locking
* Fail-fast via exceptions

## Quick start

    require 'SLogger.php';
    SLogger::add(array(
        'default' => array( 'path/to/log/directory' ),
    ));
    SLoggerErrorHandler::install(); // log all errors and uncaught exceptions; supress display of errors to browser.
    SLogger::get()->debug("This will be logged only if a more sever event is logged.");
    SLogger::get()->error("This will trigger smart logging: all events in this session will be logged.");
    throw new Exception("This is logged if not caught and if SLoggerErrorHandler::install() was called.");

## Documentation

See example/example.php, SLogger.php, and tests/SLoggerTest.php

## Credits

The giants on which I stand ...

* Kenny Katzgrau's [KLogger](https://github.com/katzgrau/KLogger)
* Contributions made to KLogger via GitHub pull requests (also licensed under MIT)
* Code taken from answers on StackOverflow and documentation comments on php.net (both licensed under CC 3.0 with attribution; attributions given in code).
