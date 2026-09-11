# Changelog

## 0.1.0 — Unreleased

- Transcript-first Telegram flow: Cancel / Rewrite with DeepSeek / Save; no automatic LLM calls or task creation.
- Rewriting updates the preview, preserves the original transcript and leaves drafts intact on provider failures.

- Russian/English settings, manual, bot replies and command descriptions based on the Kanboard profile language.
- Live provider model dropdown, explicit refresh and manual model ID fallback.

- Personal bot connections with administrator grants and Kanboard project/column permission checks.
- Telegram command menu, project/swimlane selection and persistent per-sender destinations.
- Destination details in previews and creation replies; changing selection updates the preview.
- Migration command for the original shared connection.

- Fix settings form reset after validation errors; retain administrator-only drafts for 30 minutes without exposing credentials in HTML.

- Telegram voice/audio/text ingestion with a private-chat allowlist.
- Two-row draft buttons, restrained emoji in Russian/English bot messages, and visible progress while DeepSeek rewrites text.
- Multilingual medium speech model by default, with a configurable 5 GB memory limit.
- Local faster-whisper service with automatic model download and persistent cache.
- Project/category/column settings, category buttons and persistent task previews.
- Optional DeepSeek and OpenRouter normalization with transcript fallback.
- Restart-safe polling and duplicate task protection.
- Russian configuration UI/manual; English and Russian installation documentation.
- Docker/OrbStack installer, integration tests and release archive tooling.

### Security and release preparation

- Bind connections/grants to users with a cascading foreign key and immutable state identity; migrate legacy settings.
- Bound Telegram delivery retries and reuse prepared transcripts on redelivery.
- Random task references, owner-scoped deduplication and project activity attribution.
- Bound audio decoding, refuse overload and keep the worker slot occupied until CPU work ends.
- Per-connection hourly limits and per-draft rewrite limits.
- Personal editable DeepSeek/OpenRouter prompt with default reset and fixed response format.
- Preserve web locale and other plugin translations; hide inaccessible profile links.
- Configurable CPU limit, reduced container capabilities and SHA-pinned GitHub actions.
