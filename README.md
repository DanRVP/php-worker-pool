# php-threadpool
A simplistic approach to a threadpool in PHP

## Installation 
```
composer require dan-rogers/threadpool
```

## Example use case
This implementation is intended for something like the following:
- You have many accounts to process, doing things which take a long time like multiple calls to a slow external API
- Running this in sequence would take too long so you need multiple threads
- The action you are taking is run in a command line script which take arguments

## Example implementation
Lets use our above use case. I have 200 user accounts. For each user account I need to pull and save some from an API, process it and dispatch the processed result to another service.

Code for the above process. Lets call the script `child-process.php`:
```php
<?php
// Get info by ID
$client = new HttpClient();
$info = $client->getInfo($argv[1]);

// Run some processing
$processed = $this->processInfo($info);

// Dispatch to service
$client->post($processed);
```

Invoking the above would be as simple as running `php child-process.php {account_id}`. In a main script lets use the Threadpool:
```php
<?php
use Psr\Log\NullLogger;
use Threadpool\Threadpool;

$account_ids = [1, 2, 3, 4 ... 199, 200];

$base_command = 'php child-process.php'; // The child process we want to execute.
$logger = new NullLogger(); // A PSR 3 compliant logger to be used for monitoring and logging.
$max_threads = 10; // The max number of threads the threadpool can utilise at once.
$thread_check_sleep = 3000; // When all threads are in use how long should the pool wait before checking for an available thread.
$max_event_exec_time = 60000; // The maximum amount of time the thread should live for.
$check_limit = 1000; // How many checks should the pool make for a free thread before exiting.
$output_destination = 'mylog.log'; // Where the output of the child should be directed.

$pool = new Threadpool(
  $base_command,
  $logger,
  $max_threads,
  $thread_check_sleep,
  $max_event_exec_time,
  $check_limit,
  $output_destination
);

foreach ($account_ids as $id) {
  // You can alternatively use Threadpool::setCommands() to set all at once
  $threadpool->pushCommand([$id]);
}

$threadpool->execute();
```
