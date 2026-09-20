<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat-completions transport. Also serves OpenRouter and any
 * OpenAI-compatible endpoint via base_url (provider = openrouter|custom).
 */
final class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(private string $baseUrl, private string $apiKey, private string $model) {}

    public function complete(array $messages, array $options = []): AiResult
    {
        $response = Http::withToken($this->apiKey)->timeout(60)->acceptJson()->post(
            rtrim($this->baseUrl, '/').'/chat/completions',
            ['model' => $options['model'] ?? $this->model, 'messages' => $messages, 'temperature' => $options['temperature'] ?? 0.2]
        );
        if ($response->failed()) {
            throw new AiTransportException('AI provider request failed: HTTP '.$response->status());
        }
        $body = $response->json() ?? [];
        $content = (string) ($body['choices'][0]['message']['content'] ?? '');
        abort_if(trim($content) === '', 422, 'AI provider returned an empty completion.');

        return new AiResult(
            $content,
            (int) ($body['usage']['prompt_tokens'] ?? $this->estimateTokens($messages)),
            (int) ($body['usage']['completion_tokens'] ?? str_word_count($content)),
            (string) ($body['model'] ?? $this->model),
        );
    }

    private function estimateTokens(array $messages): int
    {
        $words = 0;
        foreach ($messages as $m) {
            $words += str_word_count((string) ($m['content'] ?? ''));
        }

        return (int) ceil($words * 1.33);
    }
}
