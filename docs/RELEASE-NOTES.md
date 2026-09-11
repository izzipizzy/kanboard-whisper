# Telegram Whisper for Kanboard 0.1.0 — preview

Create Kanboard tasks from Telegram voice notes and text. Speech recognition runs locally using multilingual Whisper medium; DeepSeek or OpenRouter rewriting is optional and runs only when requested.

- Personal bot tokens, Telegram allowlists, provider keys and editable rewriting prompts.
- Kanboard project permissions, project/swimlane/category selection in Telegram.
- Original transcript preview, optional rewrite and explicit Save with destination shown.
- Russian and English interface, compact two-row buttons and progress messages.
- Cascading user-owned connection storage, bounded delivery retries, bounded audio decoding and usage limits.

Requirements: Kanboard 1.2.54+, SQLite, Docker Compose, Python 3 for the installer, and approximately 5 GB memory for the speech container. The first start downloads the model. No public webhook or paid speech API is required.

Install from the repository using `./scripts/install-local.sh YOUR_KANBOARD_CONTAINER`. The PHP ZIP is supplied for manual plugin installation; companion bot and speech services are still required. Only the full installation is supported.

This is a preview for hands-on validation, not a Kanboard directory submission. Before promotion to a stable release, complete RELEASING.md. Current local validation is ARM64; AMD64 CI is configured but has not run on GitHub yet. Live third-party provider calls are not part of automated tests. See SECURITY.md for trust boundaries and documented limitations.
