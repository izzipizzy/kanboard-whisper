# Security policy

Version 0.1.0 is intended for a small trusted team using Kanboard 1.2.54+ with SQLite and Docker Compose. Report a vulnerability through [GitHub private vulnerability reporting](https://github.com/izzipizzy/kanboard-whisper/security/advisories/new). Do not post credentials, real transcripts or exploit details in public issues. The author's public channel is https://t.me/izzypizzy_seo (not a private disclosure inbox).

## Trust boundaries

- Administrators control plugin grants. An allowed Telegram sender acts with the connection owner's Kanboard permissions. An administrator's allowlist can therefore create tasks in all projects the administrator can access. Enter IDs carefully; automatic proof-of-ownership pairing is not implemented.
- Bot tokens and provider keys are stored in the database, protected by its filesystem permissions. They are not encrypted at rest. Kanboard administrators, other installed PHP plugins and database backup holders are trusted. Never publish the database, `.env.local`, `.backups/` or worker state. Validation drafts also live in the server-side session; expiry is enforced on use, physical removal depends on session cleanup.
- User deletion cascades connection data and grants. Orphan worker files are removed on a subsequent worker cycle. Disabled connections keep settings and drafts for resuming. Telegram sender IDs and source transcripts are part of created task descriptions and visible to project members.
- LLM instructions are user-editable and transcripts can contain malicious instructions. The model has no tools or credentials in its prompt. Output is validated, previewed, and requires explicit Save; review facts before saving.
- Speech has no public host port. Keep its Docker network private. It still needs internet on first start to download a model. Audio uploads are capped at 20 MB and decoded to at most ten minutes. The service rejects concurrent transcription work.
- One PHP worker handles connections sequentially; this is not strong multi-tenant resource isolation. Twelve audio attempts and thirty rewrites per hour per connection, and five rewrites per draft, limit abuse. A long request can still delay all connections. Provider spending controls remain the responsibility of each key owner.
- The worker needs write access to Kanboard data. Current compose uses the exact Kanboard image and inherited volumes, runs as root with capabilities dropped and no-new-privileges. Other volumes inherited from that container may remain visible. Do not co-locate untrusted services on its networks.
- Base images, model revisions and transitive Python packages are not fully locked yet. No claim of a CVE-free dependency set is made. Before a broad deployment, audit dependencies and pin a tested image/model snapshot.

## Updates

Use the installer to back up the SQLite database and plugin before updating. Stop workers before restoring a backup. An old pre-fix backup may contain orphaned credentials: do not restore it into a running newer installation without review. Restart the worker after PHP changes; installing only the ZIP does not start companion services.
