<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/** Google Gemini generateContent transport. */
final class GoogleProvider implements AiProviderInterface
{
    public function __construct(private string $apiKey, private string $model) {}

    public function complete(array $messages, array $options = []): AiResult
    {
        $contents = [];
        foreach ($messages as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => (string) ($m['content'] ?? '')]]];
        }
        $response = Http::timeout(60)->acceptJson()->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.($options['model'] ?? $this->model).':generateContent?key='.$this->apiKey,
            ['contents' => $contents]
        );
        if ($response->failed()) {
            throw new AiTransportException('Google AI request failed: HTTP '.$response->status());
        }
        $body = $response->json() ?? [];
        $content = (string) ($body['candidates'][0]['content']['parts'][0]['text'] ?? '');
        abort_if(trim($content) === '', 422, 'Google AI returned an empty completion.');

        return new AiResult(
            $content,
            (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            (int) ($body['usageMetadata']['candidatesTokenCount'] ?? str_word_count($content)),
            (string) ($body['modelVersion'] ?? $this->model),
        );
    }
}
