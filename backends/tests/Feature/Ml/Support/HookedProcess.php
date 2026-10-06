<?php

namespace Tests\Feature\Ml\Support;

use Closure;
use Symfony\Component\Process\Process;

/** A real child process that runs a callback on the Nth poll (a stand-in for "the operator clicked Stop now"). */
final class HookedProcess extends Process
{
    public int $polls = 0;

    public ?Closure $onPoll = null;

    public int $firePoll = 3;

    public function isRunning(): bool
    {
        if (++$this->polls === $this->firePoll && $this->onPoll) {
            ($this->onPoll)();
        }

        return parent::isRunning();
    }
}
