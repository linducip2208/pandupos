# AI Assistant Module (Future Addon 13)

Provider-agnostic completions with encrypted keys, token budgets, usage
metering and deterministic anomaly detection, gated by the `ai` module flag
(non-core).

## Concepts

- **Providers** (`ai_provider_configs`): openai / anthropic / google /
  openrouter / custom, model, encrypted API key, optional monthly token cap.
  One active config per provider per tenant (first active wins).
- **Transports** (`AiProviderInterface`): `OpenAiCompatibleProvider` (also
  serves OpenRouter and any OpenAI-compatible base URL), `AnthropicProvider`
  (Messages API with system handling), `GoogleProvider` (generateContent),
  `FakeAiProvider` (deterministic, for tests/demos/CI). Failures map to
  `AiTransportException`; empty completions are refused (422).
- **Metering** (`ai_usages`): tokens in/out per feature; the monthly budget
  is enforced *before* the provider call once exhausted (a single call's
  cost is unknowable upfront, so the guard is on cumulative use).
- **Anomaly detection**: deterministic scan over real data — low-stock
  (on-hand vs reorder level) and dead-stock (no sales in 30 days, scoped
  through invoices since lines carry no tenant column). No LLM needed.

## Surfaces

- UI: `/ai` (provider form, playground, budget, anomalies, usage) —
  `module:ai` plus `ai.view` / `ai.manage`.
- API: `/api/v1/ai/{ask,usage,anomalies}` (sanctum + tenant scope + same
  policies).

## Intentional boundaries (v1)

- No streaming: completions are request/response.
- No fine-tuning/embeddings endpoints: chat completions only.
- No per-feature model routing: the tenant's active config serves all
  features.
