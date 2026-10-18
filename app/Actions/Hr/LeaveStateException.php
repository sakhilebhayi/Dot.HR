<?php

namespace App\Actions\Hr;

use RuntimeException;

/** A leave request could not change state (already decided, balance gone). The message is safe to show. */
class LeaveStateException extends RuntimeException {}
