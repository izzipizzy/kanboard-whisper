# Publishing on GitHub and in the Kanboard directory

Nothing is published by the local installer or packaging script.

1. Repository: `izzipizzy/kanboard-whisper`, author `izzipizzy`. Enable private vulnerability reporting and review the MIT attribution before a public release. The initial v0.1.0 release is a prerelease for hands-on validation.
2. Run PHP integration and speech tests, then a real voice → Telegram → Whisper → Kanboard trial, including each optional provider you intend to advertise. LLM API tests are simulated; the core Telegram voice flow has also been tried live. Test on AMD64 before advertising that platform; local validation was ARM64.
3. Ensure `git status` contains no secrets, `.env.local`, local backups or database files. Keep the companion-service requirement clear in the repository description and screenshots.
4. Update version in `Plugin.php`, record the release date in `CHANGELOG.md`, run `python3 scripts/package.py`. The archive must have exactly the top-level `Whisper/` folder, including `Whisper/Plugin.php`.
5. Push the reviewed repository. Create a GitHub release tagged `v0.1.0` and upload `dist/Whisper-0.1.0.zip` and its SHA-256 file. The workflow here builds artifacts for review; it does not publish releases automatically. Do **not** use GitHub's automatic source ZIP as the plugin download.
6. Generate a catalog entry using the actual public repository and author:

   ```sh
   python3 scripts/catalog-entry.py izzipizzy/kanboard-whisper --author 'izzipizzy' > dist/catalog-entry.json
   ```

7. Follow the [official directory contribution instructions](https://github.com/kanboard/website#how-to-add-a-new-plugin-to-the-list). Add the generated `Whisper` object to [`kanboard/website/plugins.json`](https://github.com/kanboard/website/blob/main/plugins.json), maintaining alphabetical order by plugin name, and open a pull request. Verify the release asset URL downloads successfully before submitting.
8. Test installation from the exact release ZIP in a clean Kanboard instance, then deploy the companion services from the repository. `remote_install: false` directs users to the complete Docker installation; a PHP ZIP alone cannot run the integration.

The plugin integrates through the settings sidebar and standard task-creation model. It does not patch core files or override templates. The SQLite migration creates `whisper_connections` with an `ON DELETE CASCADE` foreign key to users; `has_schema` must be true.
