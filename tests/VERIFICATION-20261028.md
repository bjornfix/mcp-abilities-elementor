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

## Supplemental local error and output-schema checks

The upstream PR 374 review was treated as a test hypothesis, not as an installed change:
https://github.com/WordPress/mcp-adapter/pull/374#pullrequestreview-5684156725

Only test files and this verification note changed in this follow-up. Runtime snippet code remains at candidate 2.3.56. No upstream patch was copied, no adapter was upgraded, and no dev/production installation, merge or publication was performed.

- Registered-ability checks now pass **70 assertions**, including create/update `WP_Error` injection with both scalar and nested dummy database `error_data`. Failed writes preserve posts, metadata and native assignments; the result contains only `success` and `message`, without the database sentinel.
- The same checks with the existing unmodified Pro Document/Metabox/Conditions_Cache fixtures pass **75 assertions**. These remain in-memory WordPress tests.
- `php tests/run-custom-code.php --schema-cases | python3 tests/check-custom-code-schema.py` validates **52 result cases**, including **26 failures**, against the registered output schemas using the locally available JSON Schema validator. The validator has a wrong-type negative control.
- `MCP_ELEMENTOR_ADAPTER_FIXTURE=<external extracted 0.7.1 root> php tests/run-custom-code-mcp.php | python3 tests/check-custom-code-schema.py` passes **119 adapter assertions** and validates **47 assembled callback results**, including **21 domain failures**, against schemas returned by the real adapter `tools/list` handler. The converter, McpTool, ToolsHandler and MCP request/result records are unmodified upstream code. WordPress storage, permissions and WP_Ability execution remain test doubles; callback results are captured from the registered snippet tests and replayed through result assembly.
- Actual 0.7.1 permission/framework errors return `isError: true` with text and no `structuredContent`. The injected database sentinel is not exported. Snippet domain failures retain their declared `{success:false,message:...}` shape in both text and structured content; the existing adapter marks these domain results `isError:false`.
- The generic execute-ability schema really requires `success`. A negative control rejects the review's proposed `{code,message,data}` value against that schema. The proposed PR implementation is not loaded. No local snippet or current 0.7.1 error/schema defect was reproduced.
- A disposable source mutation that adds raw create `WP_Error::get_error_data()` to the snippet result fails the new regression assertion. The candidate source was not mutated. PHP syntax checks pass for both PHP test files.

The external adapter fixture is the pre-existing local `mcp-adapter-071-identity.zip`, SHA-256 `c8bc28f83d3c39beffa2add4f6becc0b1ae54097df433697d1dbe6520a80d769`. Its ToolsHandler SHA-256 is `bea3f568f01adb4ca0df5eb892c0abacfc77f6fe796fd77554064b3b9c7de697`. The separate local adapter checkout advertises 0.6.1 and was not used as a substitute for this 0.7.1 contract check. Deployed file hashes were not read in this local-only follow-up.

Execution used Bubblewrap with no external network, an empty environment, read-only source/fixture/toolchain mounts, and a 16 MiB scratch filesystem. Limits: 30 seconds wall time, 15 seconds CPU, 512 MiB address space, 32 processes, 128 open files and 1 MiB per output file. Only the needed local Python validator packages were mounted, without the host home directory or credentials. PHP 8.3 ran without its normal configuration and with the existing ctype extension. No dependencies were installed.

Limitations remain explicit: no full WordPress/database/Elementor Pro runtime, HTTP transport, TypeScript SDK 1.32.2 client run, or official candidate Plugin Check was performed. The earlier read-only dev inspection found a working WordPress/WP-CLI/Plugin Check environment but no Elementor Pro. Dev installation and the previously denied time-session resume remain pending and were not retried.
