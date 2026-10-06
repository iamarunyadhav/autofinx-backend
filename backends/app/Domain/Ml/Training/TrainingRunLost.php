<?php

namespace App\Domain\Ml\Training;

use RuntimeException;

/** The run row is no longer RUNNING (the reaper ended it): the owner must stop at once. */
final class TrainingRunLost extends RuntimeException {}
