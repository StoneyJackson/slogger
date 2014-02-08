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

## Getting started

1. Clone project
1. Try example/example.php

## Documentation

See example/example.php and tests/SLoggerTest.php

## Credits

This project is inspired by Kenny Katzgrau's
[KLogger](https://github.com/katzgrau/KLogger) and many of the pull requests
made to that project.

