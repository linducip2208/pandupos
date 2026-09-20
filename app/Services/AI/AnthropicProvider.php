<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/** Anthropic Messages API transport. */
final class AnthropicProvider implements AiProviderInterface
{
    public function __construct(private string $apiKey, private string $model, private string $version = '2023-06-01') {}

    public function complete(array $messages, array $options = []): AiResult
    {
        $system = null;
        $turns = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system' && $system === null) {
                $system = (string) $m['content'];
            } else {
                $turns[] = ['role' => ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user', 'content' => (string) ($m['content'] ?? '')];
            }
        }
        $response = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version])
            ->timeout(60)->acceptJson()->post('https://api.anthropic.com/v1/messages', array_filter([
                'model' => $options['model'] ?? $this->model, 'max_tokens' => $options['max_tokens'] ?? 1024,
                'system' => $system, 'messages' => $turns,
            ]));
        if ($response->failed()) {
            throw new AiTransportException('Anthropic request failed: HTTP '.$response->status());
        }
        $body = $response->json() ?? [];
        $content = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= (string) ($block['text'] ?? '');
            }
        }
        abort_if(trim($content) === '', 422, 'Anthropic returned an empty completion.');

        return new AiResult(
            $content,
            (int) ($body['usage']['input_tokens'] ?? 0),
            (int) ($body['usage']['output_tokens'] ?? str_word_count($content)),
            (string) ($body['model'] ?? $this->model),
        );
    }
}
