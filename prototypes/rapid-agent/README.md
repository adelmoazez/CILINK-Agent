# CILINK Agent — Rapid Prototype

This directory contains the single-file prototype supplied for review. It is kept isolated from the main Novamira-derived CILINK Agent implementation.

## Status

- Imported as a prototype; not merged into the main plugin.
- PHP syntax was checked with `php -l`.
- Not tested inside a live WordPress installation.
- Not validated for ChatGPT MCP compatibility or OAuth discovery.
- Not production-ready.

## Included capabilities

- JSON-RPC-style HTTP endpoint at `/wp-json/cilink-agent/v1/mcp`
- Site information
- PHP execution
- File listing, reading and writing
- SQL queries (writes are disabled by default)
- WP-CLI execution
- Basic activity log

## Critical security warning

This prototype deliberately exposes highly privileged operations. A connected administrator can execute arbitrary PHP, modify WordPress files, run SQL, and invoke WP-CLI. Application Password authentication is not OAuth, and the implementation is not a complete, verified MCP transport. Do not install or enable it on a production site or expose it to an untrusted client.

Before any deployment, it requires a security redesign, robust authorization and capability boundaries, strict command handling, path/symlink hardening, audit-log protections, and real MCP/OAuth integration tests.

## License

The source header declares GPL-2.0-or-later. Preserve that notice if redistributing this prototype.
