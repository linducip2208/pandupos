<?php

namespace App\Services\AI;

/** Provider-agnostic chat completion contract. */
interface AiProviderInterface
{
    /** @param array<int, array{role:string,content:string}> $messages */
    public function complete(array $messages, array $options = []): AiResult;
}
