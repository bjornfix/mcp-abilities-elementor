# Native Custom Code candidate verification

## Source and version coordination

The existing Elementor Custom Code abilities are corrected at their native storage Interface. No separate snippet model, plugin or transport was added.

- Source base: `b902b3ccb96d8affe4f67336e8a627f223b86565` (Elementor add-on 2.3.54).
- Implementation commit: `68b0f425735c38c24a5cf6ab1bacde8b4705a017`.
- Branch: `fix/native-custom-code-snippets-20261028`.
- Current source candidate: **2.3.56**, superseding this branch's unbuilt and unpublished 2.3.55 metadata.
- The existing finding 168 proposal also proposed Elementor 2.3.55. Its files remain untouched. Its `mcp-abilities-elementor.patch` passed `git apply --check` against this branch without applying or merging it.
- Finding 297 belongs to the separate Store Locator add-on, whose existing proposal suggests 0.1.34. It does not share Elementor's version sequence.
- The inspected private Elementor candidate identities contain versions through 2.3.54 and no 2.3.55/2.3.56 package. No package, tag, promotion, update offer or installation was created here.

This branch does not include finding 168's frontend change. Before any eventual rollout, reconcile the approved source changes and actual current version, then validate the exact combined candidate through the normal release process. Publishing this older-base branch after a different source candidate without that reconciliation could discard that candidate's changes. The version reservation alone is not an integration or release approval.

## Executed checks

- Original source fails the new native-code storage assertion: create writes `post_content`, while the native renderer consumes `_elementor_code`.
- Corrected registered-ability tests: **58 assertions pass**.
- Optional native Pro source integration: **63 assertions pass** with unmodified local Pro `Document`, `Custom_Code_Metabox` and `Conditions_Cache` fixtures. These verify exact rendered code and native cache relocation/removal while preserving unrelated assignments.
- Architecture, document-write and Theme Builder condition regressions pass.
- Native revision-filter contract: 27 assertions pass.
- PHP lint, Git whitespace checks, README/readme version alignment, inventory consistency for all 89 abilities and the public-copy check pass.
- JSON, JavaScript escaping, blank code, native meta-only existing snippets, create/update/status/location/priority/condition flows, legacy meta callers, denied permissions and metadata-write failure handling are covered by the registered-ability checks.

The 58/63 checks use in-memory WordPress storage and permission functions. They are **not a full WordPress runtime test**, HTTP/MCP transport test, official Plugin Check or live-site browser check. Native Pro fixture files remain outside the repository and installable package.

## Full runtime and Plugin Check limitations

An existing bootable local WordPress/Elementor Pro test root was not resolved from the checked local paths. A standalone `~/Documents/wp-config.php` has no neighboring `wp-load.php`, `wp-includes`, `wp-admin` or `wp-content`; it was not loaded or printed.

The available local CLI is PHP 8.3.6. `mysqli`, `pdo_mysql`, `sqlite3` and `pdo_sqlite` are all absent. No `wp` executable is available. Local PHPCS has MySource, PEAR, PSR1, PSR2, PSR12, Squiz and Zend; the WordPress standard is not installed.

Consequently:

- Full WordPress + Elementor Pro runtime: **not run; test environment unresolved/unavailable**.
- Official Plugin Check: **not run; no resolved local WordPress runtime/WP-CLI/Plugin Check**.
- PHPCS WordPress standard: **not run; standard missing**.

Continuing these checks requires the exact existing permitted local test root and its capable PHP/WordPress/Pro/Plugin Check runtime. No new environment, database driver, access path or third-party plugin installation was created to bypass this limit. Production, remote dev and other candidates were not mutated in this follow-up.

## G1 separation

Snippet ordering remains native ordering within the same insertion location. This change does not alter WordPress hook priorities or move code before Cookiebot. G1 tracking snippets and production content were not edited. The Cookiebot initialization-order problem requires its separately owned solution.
