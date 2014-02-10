<?php
$log = SLogger::get();  // 'default' logger

// Severities adopted from RFC 5424
$log->emergency('System wide failures. Wake everyone!');
$log->alert('Primary system failure. Wake the admin!');
$log->critical('Secondary system failure. Wake the admin!');
$log->error('Non-urgent failure. Wake a developer!');
$log->warning('Failure forthcomming unless action taken. Wake the admin!');
$log->notice('Unusual event. Admins take note.');
$log->info('Normal operating event.');
$log->debug('Detailed messages for developers.');

$log->debug("Logging an array", array( 'balance'=>200 ));
// Logging null.
$log->debug("Logging null", null);


// Test exception logging. Remember it will go to 'paypal'.
function foo() {
    throw new Exception("Bad stuff");
}
function bar() {
    foo();
}
bar();
