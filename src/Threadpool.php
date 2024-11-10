<?php

namespace Threadpool;

use Psr\Log\LogLevel;

final class Threadpool
{
    /**
     * PSR3 Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * The maximum number of simultaenous threads to run
     *
     * @var int
     */
    private $max_threads;

    /**
     * Time to wait between checks for an available event thread, in milliseconds
     *
     * @var int
     */
    private $thread_check_sleep;

    /**
     * How long (roughly) a process is allowed to live for in milliseconds
     *
     * @var int
     */
    private $max_event_exec_time;

    /**
     * An array of commands to run
     *
     * @var array
     */
    private $commands = [];

    /**
     * Where the output of the command should be piped to
     *
     * @var string
     */
    private $output_destination;

    /**
     * A base command which the threadpool will run with args derived from the commands array
     *
     * @var string
     */
    private $base_command;

    /**
     * How many times the thradpool
     *
     * @var int
     */
    private $check_limit;

    /**
     * Constructor
     *
     * @param string $base_command
     * @param \Psr\Log\LoggerInterface $logger
     * @param int $max_threads
     * @param int $thread_check_sleep
     * @param int $max_event_exec_time
     * @param int $check_limit
     * @param string $output_destination
     */
    public function __construct(
        $base_command,
        $logger,
        $max_threads = 5,
        $thread_check_sleep = 1000,
        $max_event_exec_time = 3000,
        $check_limit = 1000,
        $output_destination = '/dev/null'
    ) {
        $this->base_command = $base_command;
        $this->logger = $logger;
        $this->max_threads = $max_threads;
        $this->thread_check_sleep = $thread_check_sleep;
        $this->max_event_exec_time = $max_event_exec_time;
        $this->check_limit = $check_limit;
        $this->output_destination = $output_destination;

        $this->logger->log(LogLevel::INFO, '(PID ' . getmypid() . ") $base_command pool instantiated");
    }

    /**
     * Sets a full array of commands in the commands queue
     *
     * @param string[] $args
     * @return void
     */
    public function setCommands(array $args)
    {
        $this->commands[] = $args;
    }

    /**
     * Pushes a command request into the commands queue
     *
     * @param array $args
     * @return void
     */
    public function pushCommand(array $args)
    {
        $this->commands[] = $args;
    }

    /**
     * Execute a batch of commands in a threaded manner via exec
     *
     * @param array[] $commands An array of commands to be run by exec.
     */
    public function execute()
    {
        foreach ($this->commands as $key => $args) {
            $i_thread = 0;
            $available_thread = false;
            $full_command = $this->base_command . ' ' . implode(' ', $args);

            do {
                $this->logger->log(LogLevel::INFO, "Checking for thread for command $full_command");
                $available_thread = $this->isThreadAvailable();
                if (!$available_thread) {
                    $this->logger->log(LogLevel::INFO, "No available threads. Pausing before checking again.");
                    usleep($this->thread_check_sleep * 1000);

                    if ($i_thread++ > $this->check_limit) {
                        $this->logger->log(LogLevel::INFO, 'Hit thread check count limit: stopping process.');
                        return;
                    }
                }
            } while (!$available_thread);

            $this->logger->log(LogLevel::INFO, "Found thread for command $full_command");
            exec("nohup $full_command >> $this->output_destination 2>&1 &");
            unset($this->commands[$key]);
        }
    }

    /**
     * Check for an available process thread for the event. Will check and prune any dead threads if needed.
     *
     * @return bool
     */
    private function isThreadAvailable()
    {
        exec("ps -eo pid,lstart,command | grep -v grep | grep -w '$this->base_command'", $processes);
        foreach ($processes as $process) {
            preg_match('/^(\d+)\s+(\w+\s+\w+\s+\d+\s+\d+:\d+:\d+\s+\d+)\s+(.*)$/', trim($process), $matches);
            if (empty($matches) || count($matches) !== 4) {
                $this->logger->log(
                    LogLevel::INFO,
                    "A unmatchable process was found by ps."
                    . " Investigate immediately. The command found was: $process and the search"
                    . " term was $this->base_command"
                );

                continue;
            }

            $pid = $matches[1];
            $start_time = $matches[2];
            if ((time() - strtotime($start_time)) * 1000 > $this->max_event_exec_time) {
                if ($this->killProcess($pid)) {
                    $this->logger->log(LogLevel::INFO, "$pid over time and was killed");
                } else {
                    $this->logger->log(LogLevel::INFO, "Unable to kill PID: $pid");
                }
            }
        }

        return count($processes) < $this->max_threads;
    }

    /**
     * Kill a process
     *
     * @param int $pid
     * @return bool
     */
    private function killProcess($pid)
    {
        if (function_exists("posix_kill")) {
            return posix_kill($pid, 0);
        }

        exec("/usr/bin/kill -s 0 $pid 2>&1", $junk, $return_code);
        return !$return_code;
    }
}
