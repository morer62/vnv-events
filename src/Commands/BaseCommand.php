<?php

namespace App\Commands;

use App\Services\ConfigService;
use Exception;

abstract class BaseCommand
{

    abstract public function getName(): string;

    abstract public function handle(array $args);

    /**
     * Parse command line arguments into an associative array.
     *
     * The following rules are applied:
     *  - Non-option arguments are skipped
     *  - Arguments with a value are split by '='
     *  - Arguments without a value are set to true
     *
     * @return array The parsed arguments
     */
    private function parseArguments(): array
    {
        global $argv;
        
        $arguments = [];
        
        // Skip the script name (first argument)
        $args = array_slice($argv, 1);
        
        foreach ($args as $arg) {
            // Skip non-option arguments (like command name)
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            
            // Remove the -- prefix
            $arg = substr($arg, 2);
            
            // Split by = to get key and value
            if (str_contains($arg, '=')) {
                list($key, $value) = explode('=', $arg, 2);
                $arguments[trim($key)] = trim($value);
            } else {
                // Handle flags without values (set to true)
                $arguments[trim($arg)] = true;
            }
        }
        
        return $arguments;
    }

    /**
     * Validate the parsed arguments.
     *
     * @param array $args The parsed arguments
     * @throws Exception If the arguments are invalid
     */
    protected function validateArgs(array $args): void
    {

    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        ConfigService::init();
        $args = $this->parseArguments();
        $this->validateArgs($args);
        $this->handle($args);
    }
}