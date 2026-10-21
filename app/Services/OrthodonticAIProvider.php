<?php
declare(strict_types=1);
namespace App\Services;

/** Port for a reviewed external adapter; no provider is registered in this release. */
interface OrthodonticAIProvider {
    public function name(): string;
    /** Receive only the authorized, minimized case context. Never apply clinical changes. */
    public function draft(array $context,string $task): string;
}
