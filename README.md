# Kanboard Whisper

Turn Telegram voice notes into Kanboard tasks while on the go. Speech recognition runs locally with **faster-whisper**. Optionally use **DeepSeek**, directly or through **OpenRouter**, to turn a transcript into a concise title and description.

**Status: 0.1.0, initial implementation.** Tested with Kanboard 1.2.54 and SQLite on Docker/OrbStack (ARM64). Core voice-to-task flow has been tried with a live Telegram bot. Automated tests cover personal accounts, access revocation, project/lane selection and simulated LLM APIs; provider keys still need a live trial before a public release.

[Инструкция на русском](docs/README.ru.md) · [Release checklist](docs/RELEASING.md) · [MIT license](LICENSE)

## Features

- Voice notes, audio messages and plain text in private Telegram chats.
- Automatically downloads the local Whisper model on service startup. No speech API key.
- Project, category (task type) and column selected in Kanboard settings.
- `/projects`, `/swimlanes` and `/type` show inline choices; selection persists per Telegram sender.
- Each Kanboard user has a separate bot, Telegram allowlist, API key and defaults. Administrators grant plugin access.
- Original transcript first, with **Cancel / Rewrite with DeepSeek / Save** buttons. Every task requires explicit Save.
- DeepSeek/OpenRouter rewriting runs only when requested for that draft; provider failures leave the draft unchanged.
- Original transcript retained in normalized task descriptions.
- Explicit Telegram user ID allowlist, personal settings, administrator-only access grants and CSRF protection.
- Persistent polling cursor, a single-worker lock and deterministic task references for duplicate prevention.
- Setup instructions embedded in **My profile → Telegram Whisper** (Russian and English; follows the Kanboard profile language).

## Requirements

- Kanboard **1.2.54+**, PHP CLI with cURL and mbstring (included in the official Docker image).
- Docker Engine + Compose v2, Python 3 on the installation host.
- Persistent Kanboard `data` and `plugins` mounts.
- The included installer targets **SQLite**. For other database engines, supply the exact same database configuration to the bot worker yourself; this is not yet tested.
- Internet for Telegram, the first model download and optional normalization. Start with roughly 5 GB available container memory and disk space for dependencies/model. Runtime performance depends on CPU and audio length.

## Install into an existing Docker / OrbStack Kanboard

Download/clone this repository and run from its root:

```sh
./scripts/install-local.sh YOUR_KANBOARD_CONTAINER
```

The script installs `Whisper/` into Kanboard's persistent plugins mount, records the exact running Kanboard image and Docker network in ignored `.env.local`, builds the speech container and starts a separate PHP bot worker. The worker shares Kanboard's data/plugins mounts. Existing Kanboard tasks/settings are not modified by the installer. No Docker socket is mounted into either service.

The **first startup downloads the `medium` multilingual model**. Model files are retained in the `whisper_models` volume. The transcription port is not published to the host. The PHP plugin itself does not execute package managers from web requests: installing the companion service is a required deployment step, including when installing the PHP ZIP from the plugin directory.

1. As an administrator, open **Settings → Telegram Whisper**, expand **Administrator: user access**, and grant access to the desired accounts. Administrators have access automatically. Each permitted user then opens **My profile → Telegram Whisper**.
2. Create a Telegram bot with **@BotFather → `/newbot`** and paste its token.
3. Enter the numeric Telegram IDs allowed to use it. An empty list allows nobody. You can find your ID using `@userinfobot`.
4. Choose the default project, category and column. Categories are managed in project settings. Only projects in which the connection owner can create tasks are offered. Project/group roles and custom column creation restrictions are respected. Telegram sender IDs allowed by that owner can create tasks as that Kanboard account. Use a separate bot for each account; duplicate bot IDs are rejected.
5. Enable the bot, save and send `/start` in its private chat.
6. Send a voice message or text. Review the original transcript, optionally press **Rewrite with DeepSeek**, then press **Save**.
7. Optionally select DeepSeek or OpenRouter, enter that provider's key. Save to load the model dropdown; use **Save and refresh models** to fetch an updated list directly from the provider. OpenRouter shows DeepSeek models. A custom ID can also be entered. Defaults: `deepseek-chat` / `deepseek/deepseek-chat`.

Set Kanboard's **Application URL** to get task links in Telegram. A local-only URL works only while the phone can reach your local network/VPN. Receiving voice notes still works while away: the worker connects outbound to Telegram.

No public webhook URL is required. If the bot already has a webhook, remove it through the previous integration before starting polling. Run only one consumer for a bot token.

## Languages and model selection

The plugin UI, embedded manual, bot replies, buttons and command menu support **Russian and English**. They use the connection owner's Kanboard profile language (or the application's default); unsupported UI languages fall back to English. Speech recognition language remains independent: Russian, English or automatic detection. Dictated text is not translated by changing the interface language.

After saving a provider and key, choose a model from the dropdown and save again. **Save and refresh models** bypasses the one-hour session cache and fetches the provider's current list. DeepSeek uses its authenticated `/models` endpoint; OpenRouter uses its public catalog filtered to `deepseek/` IDs. A failed fetch keeps your saved model available and offers manual ID entry. Model availability does not guarantee credits or access with a particular API key.

## Commands and behavior

| Command | Behavior |
| --- | --- |
| `/start`, `/help` | Instructions |
| `/projects` | Choose an accessible project |
| `/swimlanes` | Choose an active swimlane in that project |
| `/status` | Destination project, swimlane, column, category and normalization mode |
| `/type` | Choose category in the configured project |
| `/cancel` | Discard current preview |

Each account has its own default project; each Telegram sender can override it and choose a swimlane. Choices survive restarts and draft expiry. DeepSeek never runs automatically on receipt. Rewriting produces a new preview; repeated rewrites use the original transcript. The previous automatic-save option is no longer used. One draft per Telegram user; a new message replaces the previous draft. Drafts expire after 24 hours. Audio limit: **10 minutes / 20 MB**; text limit: 16,000 characters. Long previews are truncated, while the task retains the full description. Tasks record the owning Kanboard account as creator, without an assignee; Telegram sender ID is recorded in the description. Existing Kanboard task-created automatic actions run normally.

Choosing a project/lane/category updates the existing draft and issues new confirmation buttons; old buttons become invalid. Project changes reset lane and category to appropriate defaults. Preview and success messages include the destination, with the final message reflecting moves by automatic actions. Permissions are rechecked on confirmation. Rewriting errors leave the current draft intact; speech failures ask the user to resend. Connections are polled in turn; input is processed sequentially, so a long recording delays other users. This deployment is intended for a small team, not high-volume parallel transcription. Failed operations are acknowledged after notifying the user; there is no automatic transcription retry queue. Delivery failures retry at most three times with backoff; permanent Telegram 400/401/403/404 refusals are acknowledged immediately. Prepared transcripts are reused on delivery retry. Telegram retains updates for a limited period, so this is not an offline mailbox.

## Upgrade from the initial single-bot version

After installing updated files, stop the bot worker and explicitly migrate the old connection to the appropriate administrator account (replace `1` with its user ID):

```sh
docker compose --env-file .env.local stop bot
docker exec YOUR_KANBOARD_CONTAINER php /var/www/app/plugins/Whisper/bin/migrate-legacy.php 1
docker compose --env-file .env.local start bot
```

This preserves the token, AI key, defaults and polling state in that account, and clears legacy credentials. It refuses to overwrite an existing personal connection. Fresh installations need no migration.

## Operations

```sh
docker compose --env-file .env.local ps
docker compose --env-file .env.local logs --tail=50
docker compose --env-file .env.local down
# Update after pulling changes; preserves a local plugin backup:
./scripts/update-local.sh YOUR_KANBOARD_CONTAINER
```

To change the speech model, add `WHISPER_MODEL=small` or `WHISPER_MODEL=large-v3` to `.env.local`, then run `docker compose --env-file .env.local up -d`. Smaller models use fewer resources; larger ones take more time/memory. The memory limit defaults to 5 GB; adjust `WHISPER_MEMORY_LIMIT` for larger models. `WHISPER_THREADS` defaults to 4. No GPU support is configured.

If Kanboard is upgraded/recreated, update `KANBOARD_IMAGE` in `.env.local` to the new container's exact image ID and recreate the bot service. Keep the worker and web application on the same version and identical database configuration. Config files stored under `/var/www/app/data` are shared; container environment variables and a config file outside shared mounts are **not** automatically inherited.

For a non-Docker Kanboard deployment, copy `Whisper/` to `plugins/Whisper`, run the speech service privately and supervise:

```sh
WHISPER_URL=http://127.0.0.1:8000 php /path/to/kanboard/plugins/Whisper/bin/worker.php
```

Run it with the application user's permissions and shared writable `DATA_DIR`. Docker is the tested installation path. See `whisper-service/Dockerfile` for the speech runtime dependencies.

## Data and security

Telegram carries audio/messages to its own servers. Whisper runs locally and audio temp files are deleted after transcription. Transcript text is sent to the configured provider only when the sender presses **Rewrite with DeepSeek**. Each account’s keys are stored under its own namespace in the Kanboard settings database and never populated into returned HTML; protect database backups. Leave a password field blank to retain the saved value, or use its explicit delete checkbox. If validation fails, the form and credentials remain in the user’s server-side session for 30 minutes so the error can be corrected without retyping keys.

Worker drafts, choices and cursor are separated by Kanboard account and bot ID under `data/whisper/`, with private state file permissions. No raw tokens, provider responses or dictation text are logged by the worker. The speech service is unauthenticated and must stay on a trusted private Docker network. Do not publish port 8000. Uninstall: stop companion services and remove `plugins/Whisper`; remove `data/whisper` and `whisper_*` settings separately if you also want to erase stored drafts/credentials. Back up first.

## Develop / test / package

```sh
./scripts/test.sh                         # disposable Kanboard DB; no external messages
python3 scripts/package.py              # dist/Whisper-0.1.0.zip
# Speech tests (after building the image):
docker compose --env-file .env.local run --rm --no-deps \
  -v "$PWD/tests:/tests:ro" whisper python /tests/test_speech.py
```

See [RELEASING.md](docs/RELEASING.md) for GitHub releases and a Kanboard directory submission. No repository URL is assumed until the public repository is chosen.

## References

[Kanboard plugin registration](https://docs.kanboard.org/v1/plugins/registration/) · [Kanboard automatic actions](https://docs.kanboard.org/v1/plugins/automatic_actions/) · [Telegram Bot API](https://core.telegram.org/bots/api) · [faster-whisper](https://github.com/SYSTRAN/faster-whisper) · [DeepSeek](https://api-docs.deepseek.com/api/create-chat-completion/) · [OpenRouter](https://openrouter.ai/docs/api/reference/overview)

## Personal rewriting prompt

In your profile, edit **DeepSeek / OpenRouter prompt** (up to 8,000 characters). It is saved only for your account and used for either provider. **Save with default prompt** restores the built-in instructions. The bot adds the JSON output contract (`title` and `description`); the transcript is a separate user message. Nothing is sent to a provider until you press Rewrite.

## Security and supported deployment

Version 0.1.0 supports **SQLite and Docker Compose**. Connections and grants use a plugin table with a cascading user foreign key. Upgrading from the development build migrates existing personal settings once; orphan settings are deleted. The local installer makes a consistent SQLite backup in `.backups/` before replacing an existing plugin. Backups contain secrets: keep them private. Stop the worker before restoring a database backup, restore matching code, then restart it.

Deleting a user removes their connection and grant. The worker removes orphaned state files on a subsequent cycle. Revoking access disables the connection; granting it again does not enable the bot. Administrators and anyone who can read the database can read API keys. See [SECURITY.md](SECURITY.md) for the trust model and remaining limitations.

The shared worker is intended for a small trusted team: one long recording still delays other bot connections. Limits per connection are 12 audio attempts and 30 rewrites per hour, and 5 rewrites per draft (including failed requests). These are resource safeguards, not hard tenant isolation. The speech service bounds decoded audio to ten minutes and refuses concurrent jobs with HTTP 503. Retry later if it is busy. The CPU limit defaults to two cores (`WHISPER_CPU_LIMIT`), while local administrators may choose more.

After updating PHP plugin files manually, restart the bot container. A directory ZIP alone cannot run the bot; companion services are mandatory. The proposed directory entry disables one-click remote installation for this reason.
