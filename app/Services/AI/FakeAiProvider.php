<?php

namespace App\Services\AI;

/** Deterministic stand-in for tests, demos and CI (no network, no key). */
final class FakeAiProvider implements AiProviderInterface
{
    /** @var array<int, array{messages:array,options:array}> */
    public array $calls = [];

    public function __construct(private string $reply = 'OK') {}

    public function complete(array $messages, array $options = []): AiResult
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];
        $last = end($messages);

        return new AiResult($this->reply.': '.substr((string) ($last['content'] ?? ''), 0, 120), 10, 5, 'fake');
    }
}
