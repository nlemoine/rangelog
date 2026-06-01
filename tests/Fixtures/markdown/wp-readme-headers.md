<!--
Fixture: WordPress plugin CHANGELOG.md using the readme `= Version =` header dialect.
Source: https://plugins.svn.wordpress.org/koko-analytics/tags/2.4.0/CHANGELOG.md
Captured: 2026-06-01
Tests: WP `= X =` headers inside a .md file (tagged GITHUB_FILE, so routed to MarkdownParser),
       inline-date variant, non-semver `= Initial release =` skip. CommonMark sees every
       `= X =` line as a paragraph, so the ATX/setext AST walk finds zero headings.
License: GPL-2.0+ (WordPress plugin); used as fixture only, not redistributed
-->
# Changelog

= 2.4.0 =

- tracking: hook into the visibilitychange event again to ignore prerender requests.
- data: rewrite exporter and importer to use NDJSON instead of raw SQL.
- rest: clamp date range for unauthenticated users to prevent large table scans.

= 2.3.7 - 2026-04-01 =

- tracking: include UTM parameters in pageview tracking requests so integrations can access campaign data.
- endpoint: harden pageview and event request validation by checking required parameters and accepted types.

= Initial release =

- first public version.
