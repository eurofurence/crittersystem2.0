<?php

namespace App\Console;

use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console output that forwards everything to a primary output (the real
 * console) while also handing each chunk to a sink closure - used to stream a
 * running migration's output into a log file the install wizard can tail.
 *
 * Decoration is disabled so both the console and the log receive plain text
 * (no ANSI escape codes leaking into the browser-rendered log).
 */
final class TeeOutput extends Output
{
    /** @var \Closure(string): void */
    private readonly \Closure $sink;

    /**
     * @param \Closure(string): void $sink
     */
    public function __construct(private readonly OutputInterface $primary, \Closure $sink)
    {
        $this->sink = $sink;
        parent::__construct($primary->getVerbosity(), false, $primary->getFormatter());
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->primary->write($message, $newline);
        ($this->sink)($message . ($newline ? \PHP_EOL : ''));
    }
}
