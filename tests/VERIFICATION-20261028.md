# Native Custom Code candidate verification

Current verdict: **2.3.57 passes** the local registered-ability (**77**), native Pro fixture (**84**) and adapter (**125**) assertions, the licensed dev runtime suite (**51/51**), and official Plugin Check (**0 errors / 0 warnings**). Source commit: `19859163510c5b7ae321e6584a40b9d98ab41fc1`. The earlier 2.3.56 results below remain historical evidence; the final section records the correction.

Release status: **published and installed on G1 as 2.3.57**. The final section and [ROLLOUT-20261028.json](ROLLOUT-20261028.json) record signed publication, the native terminal receipt, all 16 installed member hashes and the completed MCP smoke checks. Earlier no-publication statements below describe their respective historical steps.

## Source and version coordination

The existing Elementor Custom Code abilities are corrected at their native storage Interface. No separate snippet model, plugin or transport was added.

- Source base: `b902b3ccb96d8affe4f67336e8a627f223b86565` (Elementor add-on 2.3.54).
- Implementation commit: `68b0f425735c38c24a5cf6ab1bacde8b4705a017`.
- Branch: `fix/native-custom-code-snippets-20261028`.
- Current source candidate: **2.3.57**, following the tested 2.3.56 candidate and this branch's earlier unbuilt/unpublished 2.3.55 metadata.
- The existing finding 168 proposal also proposed Elementor 2.3.55. Its files remain untouched. Its `mcp-abilities-elementor.patch` passed `git apply --check` against this branch without applying or merging it.
- Finding 297 belongs to the separate Store Locator add-on, whose existing proposal suggests 0.1.34. It does not share Elementor's version sequence.
- At the initial local inspection, private Elementor candidate identities contained versions through 2.3.54 and no 2.3.55/2.3.56 package. Later authorized candidate builds and dev installations are recorded below.

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

## Authorized dev installation and official Plugin Check

The user explicitly approved installing the existing Elementor Pro code and this MCP candidate on `https://dev.devenia.com`. This step deliberately created **no backup**. The normal combined gate helper creates a rollback archive, so the documented SSH/WP-CLI installation and official check commands were used directly under the user's narrower instruction. No shared helper or release policy was changed.

Installed and active on `/var/www/dev.devenia.com`:

- Elementor Pro **4.3.2**, using the existing unmodified plugin code from `/var/www/g1.devenia.com/wp-content/plugins/elementor-pro`. All **1,326 source files** were compared by SHA-256 with their installed dev counterparts. Production source files were read without modification; no license data or site content was copied. The temporary install ZIP SHA-256 was `084a30a91b10f9d5629a3c4dee2b91f57102c9baa8facb729fd0c4f7dedd945a`.
- MCP Abilities for Elementor **2.3.56**, private candidate SHA-256 `ff8507a3062d176b40e9335dd372ff45fa656d5e7f4fd14880e424c1701cba77`, from source commit `aad92ff993657da28ea33f511ffd48a5003f472d`. All **16 installed package members** match the candidate identity. Both plugin directories have `www-data:www-data` ownership.
- Existing runtime: PHP **8.3.6**, WordPress **7.1-RC3-63305**, Elementor **4.3.5**, Plugin Check **2.1.0**.

Official Plugin Check is now **PASSED: zero errors, zero warnings**, without exceptions. It finished at `2026-10-28T14:55:53.465208+00:00` with exit code **0**, empty stderr and this exact stdout:

```text
Success: Checks complete. No errors found.
```

The command used `--slug=mcp-abilities-elementor --mode=update --format=strict-json --warning-severity=low --include-low-severity-warnings --skip-themes`. Other active plugins were skipped for this command only, matching the shared gate's policy; none was deactivated. The shared `interpretPluginCheckResult` accepts this exact native empty-finding result as `wp-plugin-check-exit-zero-strict-json-or-native-empty-v1`, with error and warning counts both zero. Raw terminal files remain in `/tmp/devenia-plugin-check-jobs/dev/mcp-abilities-elementor/ff8507a3062d176b40e9335dd372ff45fa656d5e7f4fd14880e424c1701cba77/`. No public artifact promotion was performed.

Real WordPress ability execution passed **9/9 available checks**, using the actual framework input/output validation and permissions rather than the local doubles:

- Create and list report the native Custom Code post type as unavailable.
- Priority `11` is rejected as `ability_invalid_input` by the real WordPress schema validator.
- Anonymous list/get/create/update/delete are all rejected as `ability_invalid_permissions`.
- The snippet inventory is unchanged; **zero test objects were created**. The attempted create explicitly requested a draft and used only an inert test comment.

**Full positive native WordPress/Elementor Pro snippet testing remains blocked by the dev environment.** Pro's license API reports `license_active: false`; the Custom Code feature entitlement reports true, but there are zero existing snippets and `post_type_exists('elementor_snippet')` remains false. Pro's own `can_use_custom_code()` requires an active license with the feature, or existing published snippets. The current site therefore cannot exercise native snippet creation, update, conditions/cache relocation, rendering, ordering or deletion through the ordinary registered Interface. No license was connected or copied, no fallback snippet was seeded to activate Pro's legacy path, and no post type or third-party code was forced into service. The remaining prerequisite is a valid active license on dev, or an explicitly selected existing licensed test environment.

A pre-existing Pods early-translation notice appears during normal full WordPress bootstrap. It was already observed before this installation. It was not attributed to the snippet candidate or changed here.

The same existing internal time session was resumed with the current database time and a fresh event key. No new session or historical backfill was created. There was no production installation, public update offer, merge, adapter upgrade or change to another module. This dev result supersedes the earlier pending-installation and not-run Plugin Check statements above; full positive native testing and HTTP/TypeScript client checks remain incomplete.

## Licensed native runtime follow-up: one remaining failure

After the user activated the existing dev license, readback confirmed `license_active: true` and `post_type_exists('elementor_snippet'): true`. Installed versions remain Elementor Pro **4.3.2**, Elementor **4.3.5**, and MCP **2.3.56**. Runtime source is still candidate `ff8507a3062d176b40e9335dd372ff45fa656d5e7f4fd14880e424c1701cba77` from `aad92ff993657da28ea33f511ffd48a5003f472d`; no runtime source or installed plugin was changed during this verification follow-up.

The final native suite completed **48/49 checks**. Its terminal result is **FAIL**, solely at `native_deletion_clears_own_assignments`. Reproduction is [run-custom-code-wordpress.php](run-custom-code-wordpress.php), invoked on the exact dev root with `wp --allow-root eval-file <test-file> --use-include --user=<existing administrator ID>`. The runner is pinned to this approved target/version, uses actual WP_Ability input/output validation, real storage/permissions, native Pro Document/conditions/cache code, and the installed WordPress theme. It creates two own test pages and four inert JSON-script snippets, then removes its own objects even after a failing assertion. The collected runs, exact checks, rendering positions, object IDs and cleanup results are in [DEV-RUNTIME-20261028.json](DEV-RUNTIME-20261028.json).

Verified in the real runtime:

- Native meta-only CRUD storage, default head placement/priority, exact JSON/quotes/backslashes/Unicode, empty code without legacy-body fallback, and native Document byte-for-byte output.
- Native cache relocation and condition removal; current input/output schemas; invalid priority/location rejection; invalid conditions without partial writes.
- Real WordPress `WP_Error` handling for create and update, scoped metadata-write failure, native `unfiltered_html` sanitization, and anonymous denial of all five abilities. Failure injection is request-local and restricted to the own test objects; it does not corrupt a database or change a user/role.
- Legacy meta callers; publish/list; actual theme-rendered head ordering at priorities 1 and 9; body-start/body-end placement; exact escaped code; include/exclude page conditions; condition/location changes; and changing a published snippet back to draft.
- Default trash followed by permanent deletion removes the post and its native cache assignment.

**Confirmed remaining defect:** direct `elementor/delete-custom-code` with `force:true` returns success and deletes the post, but its native condition-cache entry remains. In the final run, direct forced deletions of own snippets **111242, 111243 and 111244** all returned `success:true`; those same three IDs remained in `elementor_pro_theme_builder_conditions`. The source path is `includes/register-pro.php:646`: the force branch calls `wp_delete_post(..., true)` directly. Pro 4.3.2 registers `Conditions_Manager::purge_post_from_cache()` on `wp_trash_post`, with no corresponding permanent-delete hook. The successful trash-then-force path is the counterexample that isolates this missing native purge from generic deletion or cache-storage failure.

The smallest owning follow-up is to invoke the existing native conditions-manager purge after a successful direct permanent deletion, preserving other assignments and avoiding purge if deletion fails. This report does not change that runtime code or deploy another version. Candidate 2.3.56 therefore has **not** passed the complete native suite.

Rendering evidence and resolved test confounders:

- Anonymous HTTP to the dev page returns **302 to the same site's wp-login.php**. The earlier followed response with HTTP 200 was the login page, so it did not establish a priority or rendering defect. No further request followed that redirect during diagnosis.
- Rendering was instead performed through the already authorized administrator WP-CLI Interface: fresh WordPress processes run `WP::main()`, `template_redirect`, `template_include` and the real page template. No browser login, auth token, user, password, role or access setting was created or changed. This verifies native theme output, not an authenticated HTTP/MCP/browser client.
- The administrator toolbar can contain a snippet title after the snippet is disabled. The final assertions locate the actual script's exact `id` attribute rather than a matching title string. Draft suppression passes with that correct signal.
- WP-CLI stdin evaluation rejected `strict_types` before the first test started; using its native `--use-include` mode resolved the test invocation.

Cleanup is verified. The final run removed all **six** own objects. Its three orphaned entries were removed through Pro's existing cache Interface, only for those own IDs. The separate final readback checked **all 24 own object IDs** created across the four runtime/diagnostic runs: **zero posts/pages/snippets remain and zero own cache references remain**. The native snippet inventory is back to its original count **0**; unrelated native assignments were preserved. Empty location keys are normalized for assignment comparison, never restored from a backup. No backup was created. License status remains active.

Earlier evidence remains scoped: local **70/75** assertions pass but did not cover real force-delete hooks; the **119** adapter assertions concern response assembly, not native cache deletion. The earlier **9/9** dev checks verified unavailable-feature and denied/error paths before licensing. Official Plugin Check remains **0 errors / 0 warnings** for the unchanged exact candidate; that result does not prove this behavioral path. Public HTTP, TypeScript SDK and browser-client checks remain unverified. The existing Pods bootstrap notice is unchanged.

No production rollout, public promotion, merge, adapter update, other-module source change or license write occurred. The same approved internal time session was resumed with a fresh key and current time; no new session, second minimum or backfill was created.

## Corrected candidate 2.3.57: final verification

The force-delete defect is fixed at the existing snippet deletion callback in `includes/register-pro.php`. After `wp_delete_post(..., true)` succeeds, the callback refreshes Pro's existing cache object, calls the native conditions manager's `purge_post_from_cache()` for that one ID, and clears native in-process location results. These seven added lines use the existing Elementor Interface; no parallel cache, hook infrastructure or upstream modification was added. Permission checks and the early failure return precede the purge. The default trash path and legacy metadata callers are unchanged.

The new local regression failed against unchanged 2.3.56 at “Direct force removes the native assignment from every location”. It now passes against 2.3.57. It also verifies that a newer unrelated assignment survives a stale in-memory cache, that another snippet/template remains assigned, and that denied or failed deletion neither removes the post nor purges its assignment. The real dev suite adds two checks with WordPress's request-local `pre_delete_post` filter: a rejected force deletion preserves the post and every native assignment.

Final source/package identity:

- Source commit: **`19859163510c5b7ae321e6584a40b9d98ab41fc1`**.
- Version / Stable tag / public README metadata: **2.3.57**; all **89** abilities remain documented.
- Private candidate SHA-256: **`b17c60a6eb7de8483d30e900ac5f0624a2b01c9721913f2d1b5e15fa4edd8d22`**.
- Candidate path: `/mnt/storagebox/internal-plugin-packages/candidates/mcp-abilities-elementor/b17c60a6eb7de8483d30e900ac5f0624a2b01c9721913f2d1b5e15fa4edd8d22/mcp-abilities-elementor.zip`.
- The package has **16** verified members, no hidden files or test/project Markdown, and passed the shared public-copy/package checks. Installed dev bytes were checked against every member digest both after installation and after runtime testing. Ownership is `www-data:www-data`.

All relevant local checks passed: **77** registered-ability assertions, **84** with unmodified native Pro fixtures, **125** adapter assertions, **55** registered output-schema cases and **50** adapter-assembled output-schema cases. Architecture, write-contract, Theme Builder conditions, revision filter (**27** assertions), restore/sibling/translated restoration, Kit selection, combined Content/Elementor guards (**8** scenarios), style-guide and boundary-coherence regressions pass. PHP syntax, whitespace, metadata, ability inventory, Markdown link targets and public-copy checks pass; the changed README badge returns HTTP 200. Checks used the existing isolated local toolchain and fixtures; no dependency or local WordPress installation was added.

The exact candidate was installed and activated on `dev.devenia.com` through the approved native SSH/WP-CLI route, without a backup. Pro remains **4.3.2**, Elementor **4.3.5**, WordPress **7.1-RC3-63305**, PHP **8.3.6**. The license remains active.

The full native suite is now **PASS: 51/51**, exit **0**. This is the same 49-check suite that previously exposed the force-delete defect, plus the two rejected-deletion preservation checks. It exercises native CRUD, placement, priority, conditions, escaping, permissions, storage/error paths and actual theme rendering in fresh WordPress processes through existing administrator WP-CLI access. The formerly failing native assignment-deletion check is true; no assertion was waived. All **six** own test objects were deleted. **Zero** own native assignments remained, and the test's extra stale-assignment cleanup was **not needed**. Readback across all **30** own object IDs from this task's dev runs found zero remaining objects or assignments; the original native snippet inventory remains **0** and unrelated assignments are preserved.

Official Plugin Check was rerun against the same installed 2.3.57 bytes with the unchanged strict policy, including low-severity warnings. It completed at **2026-10-28T15:51:31.987763+00:00**, exit **0**, empty stderr, with `Success: Checks complete. No errors found.` The shared gate interpreter confirms **0 errors / 0 warnings**, without exceptions. The raw job is `/tmp/devenia-plugin-check-jobs/dev/mcp-abilities-elementor/b17c60a6eb7de8483d30e900ac5f0624a2b01c9721913f2d1b5e15fa4edd8d22/`; the runtime job is `/tmp/elementor-native-snippet-runtime-01a182c5/fixed-2.3.57/`.

[DEV-RUNTIME-20261028.json](DEV-RUNTIME-20261028.json) preserves the 2.3.56 baseline and appends the terminal 2.3.57 result, package identity, gate output and final readback. The previous **48/49** is retained as a failed baseline, not relabeled as a pass. Current **51/51** is a new completed run on the corrected source.

No production rollout, common manifest publication, public artifact promotion, merge, other-module source change, adapter update, license write or access change was performed. Finding 168 and Store Locator finding 297 remain separate. Anonymous dev HTTP still requires login; authenticated HTTP/MCP/TypeScript SDK/browser-client checks were not run. These limits do not change the recorded native runtime and theme-rendering result. The unrelated pre-existing Pods bootstrap notice remains.

## Approved signed publication and G1 rollout

Bjørn explicitly approved publishing the tested Elementor candidate and rolling it out on G1. The existing proxy confirmed that site ID `g1` is `https://g1.devenia.com`. Before publication, the signed channel and clean canonical master were both still 2.3.54, source `b902b3ccb96d8affe4f67336e8a627f223b86565`. The Storagebox remote master matched that commit; `v2.3.57` was absent, and no other 2.3.57 package or parallel canonical source change was found. The candidate therefore retained version **2.3.57**. No Cookiebot, finding 168, finding 297 or unrelated source was integrated.

The already completed official dev job was re-read and matched the exact recorded stdout, empty stderr and exit zero. The existing `interpretPluginCheckResult`, `buildVerificationFingerprint`, `validateReleaseIdentity`, `validateReleaseQualityReceipt`, candidate authority lock and candidate terminal-state Interface recorded that real job in the standard `plugin-check.json` / `state.json` format. Its original check time remains `2026-10-28T15:51:31.987Z`; evidence recording is marked separately as an import of the completed job. No check, dev installation or dev backup was repeated to reconstruct administrative state. Receipt SHA-256: `ce6ffb8cf9b7edfdcb77b58df6ebebf8714fc0a620b0c39744f0af208958b8a5`.

`promote-plugin-candidate.mjs` then promoted only SHA-256 **`b17c60a6eb7de8483d30e900ac5f0624a2b01c9721913f2d1b5e15fa4edd8d22`** through the established signed workflow. It replicated exact source **`19859163510c5b7ae321e6584a40b9d98ab41fc1`** and tag **`v2.3.57`** to the canonical Storagebox origin. The canonical local master was advanced only by fast-forward. The manifest generated at **2026-10-28T16:46:28.723Z** has SHA-256 **`760eead050fcd52f3cf26f0e49c098315f095552a84a1b13727c0e8f51f5ea1a`**. Signature verification passed. Exactly one plugin entry changed; all **37** other entries were preserved byte-for-byte at entry level. The signed rollback entry retains 2.3.54, SHA-256 `20b2c98762346c3bad24cc15ac0fab220368fce0bd7cc735f1342e66f81a213b`. The normal manifest workflow preserved its prior signed manifest in its existing private backup location.

The shared verifier passed from immutable candidate through the signed public manifest, content-addressed artifact, stable ZIP and checksum alias. All four public checks returned HTTP **200**, and both downloaded ZIPs matched the candidate SHA-256. The signed manifest binds the package to source commit 1985916; it has no separate Git commit identity.

G1 already had the Elementor add-on enabled for automatic updates in its saved native WordPress choice. The exact signed 2.3.57 offer was confirmed through MCP. A single targeted `plugins/update` request used the existing native updater and its signed-package policy. It updated **2.3.54 → 2.3.57**, preserved activation, and produced terminal receipt **`db012c92-70e1-4c31-95de-b7dbb73ca3d5`** at **2026-10-28T16:49:40+00:00**. The immutable per-rollout option matches the receipt read model: **passed**, actual version **2.3.57**, activation preserved, and fresh site health HTTP **200**. `plugins/verify-managed-installation` independently matched **16/16** signed release member hashes and the exact source/package identity. It does not inventory extra untracked files.

The existing HTTP MCP route, using its installed SDK **1.29.0**, passed **28** checks plus **9** registered output-schema cases, including two domain failures and the wrong-result-type negative control. One owned inert snippet, **97212**, remained a draft throughout. Native create/read/update verified exact escaped code in `_elementor_code`, head placement and priority 2, then body-end placement and priority 9, condition removal and fresh readback. Priority 11 was rejected before the callback; conflicting code/meta input returned the declared failure shape. Both rejected requests preserved the complete snippet response. Direct permanent deletion succeeded, followed by `Snippet not found` and inventory readback with no owned object remaining.

Original snippets **93562, 93571 and 93680** retain their titles, draft statuses, raw post content and complete metadata. The evidence file stores hashes rather than their code. The native assignments are unchanged and contain zero own references. Pro retained one new empty `elementor_head` location key; comparison normalizes only empty locations. An initial literal cache-object comparison exposed that harmless structural difference and was corrected to compare assignments. No cache values were restored or manually purged. All saved automatic-update choices are identical before and after this task. No existing page content was written.

The full native **51/51** suite and actual theme rendering remain the licensed dev result for this exact package. G1 verification is an authenticated MCP draft smoke test; no G1 published snippet, browser rendering test, separate TypeScript SDK 1.32.2 client or rollback exercise was performed. Rollback was not needed because installation, health, integrity and smoke verification passed. Other sites continue to follow their existing saved update choices; no forced installation was requested on another site. No credentials, access policy, license, adapter or other plugin was changed.

[ROLLOUT-20261028.json](ROLLOUT-20261028.json) contains the signed identity, exact native terminal receipt, file-hash evidence, input/schema results, preserved original-content hashes and verified cleanup. The same approved internal Time session was resumed with a fresh key and current time, with no new minimum or duplicate session.
