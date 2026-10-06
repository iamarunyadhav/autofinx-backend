<?php

namespace App\Domain\Ml\Training;

use RuntimeException;

/** An attached run is not QUEUED any more (cancelled or already started elsewhere). */
final class TrainingRunNotStartable extends RuntimeException {}
