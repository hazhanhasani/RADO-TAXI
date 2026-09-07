<?php
namespace App\Domain\Dispatch;

final class DispatchPolicy
{
    public function __construct(
        public readonly array $radiusKm = [1.5, 3.0, 5.0],
        public readonly int $offerBatchSize = 4,
        public readonly int $offerTtlSeconds = 15,
    ) {}
}
