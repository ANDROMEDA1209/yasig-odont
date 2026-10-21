<?php
declare(strict_types=1);

namespace App\Validators;

/** A validation error tied to a submitted field, still compatible with domain errors. */
final class ValidationException extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
