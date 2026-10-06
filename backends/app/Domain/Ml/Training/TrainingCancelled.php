<?php

namespace App\Domain\Ml\Training;

use RuntimeException;

/** The operator asked this run to stop; raised inside the process that owns the run. */
final class TrainingCancelled extends RuntimeException {}
