<?php

/*
 * Demonstrates a more realistic, yet simple, use of SLogger.
 */

// You'll probably need to set the default timezone.
// You can do that here, or in php.ini (see date.timezone property).
date_default_timezone_set('UTC');


// If you plan to log errors and exceptions as well as standard
// messages, you'll want to load and configure SLogger early.
require '../src/SLogger.php';

// Call add() to create loggers.
SLogger::add(array(

    // Create at least a default logger.
    'default' => array(

        // Log to ./log
        dirname(__FILE__).DIRECTORY_SEPARATOR.'log',

    ),

    // A separate logger for a paypal subsystem.
    // You may also configure public attributes of SLogger here.
    'paypal'  => array(

        // Log to ./log/paypal
        implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__),'log','paypal')),
        
        // Log error or worse events
        'severityThreshold'      => 'error',

        // Log ALL events if a critical or worse event is logged
        'smartSeverityThreshold' => 'critical',

        // Format of timestamp; u is microseconds
        'dateFormat'             => 'G-i-s.u',

        // Permission used to create files
        'defaultPermission'      => 0777,

        // Rotate if file exceeds 100MB
        'maxFileSize'            => 100000000,

        // Delete logs over 7 days old
        'maxDays'                => 7,
    ),
));

// Install SLogger to log errors and uncaught exceptions.
SLoggerErrorHandler::install(
    'paypal',         // logger to use
    E_ALL | E_NOTICE, // report all errors
    0                 // 1 displays errors to browser, 0 does not.
);

// Get to work.
require 'use.php';
