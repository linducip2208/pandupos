<?php

namespace App\Services\AI;

final class AiResult
{
    public function __construct(
        public readonly string $content,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {}

    public function totalTokens(): int
    {
        return $this->tokensIn + $this->tokensOut;
    }
}
