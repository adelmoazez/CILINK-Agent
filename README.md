# CILINK Agent

**CILINK Agent** is an independent downstream distribution of the open-source **Novamira Free** WordPress plugin. It keeps the upstream MCP and OAuth implementation and applies CILINK-specific identity, endpoint naming, and packaging.

- Direct MCP access to the WordPress installation; no CILINK-hosted proxy is part of the request path.
- OAuth 2.0 authorization, token issuance, refresh and revocation are inherited from Novamira Free.
- WordPress abilities, PHP execution, WP-CLI and filesystem tools are provided by the upstream project.
- Intended for development and staging environments. Keep verified backups and review every action proposed by an AI client.

## Requirements

- WordPress 6.9 or later
- PHP 8.0 or later
- HTTPS for remote OAuth/MCP connections
- A WordPress administrator account and an MCP-compatible AI client

## Build and install

Do not install GitHub's automatically generated source archive: it does not contain the Composer dependencies required at runtime.

To build the installable ZIP locally, install PHP/Composer and Bun, then run:

```bash
./build/build 1.12.16.1
```

The generated package is `/tmp/cilink-agent-1.12.16.1.zip`.

GitHub Actions can also build the ZIP: open **Actions → Build CILINK Agent** and run the workflow, or push a tag matching `cilink-v*`. The workflow uploads the ZIP as an artifact and attaches it to tagged GitHub releases.

## Connect

1. Install and activate the CILINK Agent ZIP in WordPress.
2. Open **CILINK Agent → Configuration** and enable AI Abilities.
3. Select your AI client and follow its OAuth connection instructions.
4. OAuth MCP resource endpoint: `/wp-json/mcp/cilink-agent-oauth`.
5. Application Password MCP endpoint: `/wp-json/mcp/cilink-agent`.

The OAuth authorization/token implementation is upstream code; this fork does not replace it with a custom token system. Its internal OAuth REST namespace remains `/wp-json/novamira/v1/oauth/` for now.

## Upstream and license

This is a modified version of Novamira Free, originally authored by Ovation S.r.l. and distributed under **GNU AGPL-3.0-or-later**. Original copyright and SPDX notices are retained in source files. Changes made in this fork are also covered by the AGPL; corresponding source is published in this repository.

- Upstream source: https://github.com/use-novamira/novamira
- Upstream project: https://novamira.ai
- License: [AGPL-3.0-or-later](LICENSE)
- CILINK: https://cilink.ir
