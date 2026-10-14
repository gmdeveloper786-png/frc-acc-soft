<?php

namespace App\Services\Frc\FinancialSync;

use RuntimeException;

/** A source record that failed validation. The message is safe to store and show. */
class InvalidFrcRecord extends RuntimeException {}
