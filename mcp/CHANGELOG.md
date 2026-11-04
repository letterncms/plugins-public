# Changelog

## Unreleased

### Changed

- Needs the CMS 0.4 or later.
- The MCP server URL and the Claude Code command are part of **API & Agents** on the Account screen, together with your tokens.

## 0.2.1 (2026-11-02)

### Security

- Revoking an AI tool under **Account → API Tokens** now disconnects it for good. Before, the tool could use its refresh token to get a new access token for up to 180 days.

## 0.2.0 (2026-11-02)

### Changed

- Prompts are now the CMS's skills, so plugins that register a skill with `cms()->skills()` add a prompt too.

## 0.1.0 (2026-11-01)

### Added

- First release.
