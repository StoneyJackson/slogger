<?php

require '../src/SLogger.php';

// Set the default timezone
date_default_timezone_set('UTC');

// Create one or more loggers
SLogger::add(array(

    // Logger returned by SLogger::get() by default
    'default' => array(

        // Log to same directory as this file
        dirname(__FILE__)
    ),

    // Configure any public attribute of SLogger.
    'paypal'  => array(

        // Log to paypal subdirectory
        dirname(__FILE__).DIRECTORY_SEPARATOR.'/paypal',
        
        // Configure any public SLogger attribute
        
        // Log error events or worse
        'severityThreshold'      => 'error',

        // If a critical or worse event is logged, all events are logged
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

)); // SLogger::add()


// Get default logger
$defaultLogger = SLogger::get();

// Severities adopted from RFC 5424
$defaultLogger->emergency('System wide failures. Wake everyone!');
$defaultLogger->alert('Primary system failure. Wake the admin!');
$defaultLogger->critical('Secondary system failure. Wake the admin!');
$defaultLogger->error('Non-urgent failure. Wake a developer!');
$defaultLogger->warning('Failure forthcomming unless action taken. Wake the admin!');
$defaultLogger->notice('Unusual event. Admins take note.');
$defaultLogger->info('Normal operating event.');
$defaultLogger->debug('Detailed messages for developers.');


// Some data to log
$balance = 200;

// Using a non-default logger and logging data too.
$pLog = SLogger::get('paypal');
$pLog->info("Not normally logged");
$pLog->debug("Not normally logged", array( 'balance'=>$balance ));
$pLog->critical("Critical or worse will cause this logger to log everything");
