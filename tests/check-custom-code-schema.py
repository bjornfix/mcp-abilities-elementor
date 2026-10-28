#!/usr/bin/env python3
"""Validate exported snippet results against registered or adapter-listed output schemas.

Requires the locally available jsonschema package. Reads JSON from stdin; no network.
Usage: php tests/run-custom-code.php --schema-cases | python3 tests/check-custom-code-schema.py
Optional adapter check: php tests/run-custom-code-mcp.php | python3 tests/check-custom-code-schema.py
"""
import copy
import json
import sys

from jsonschema import Draft4Validator


report = json.load(sys.stdin)
assert report["status"] == "PASS"
cases = report["schema_cases"]
assert {case["ability"] for case in cases} == {
    f"elementor/{name}-custom-code" for name in ("list", "get", "create", "update", "delete")
}
failures = 0
for index, case in enumerate(cases):
    validator = Draft4Validator(case["schema"])
    validator.check_schema(case["schema"])
    validator.validate(case["result"])
    assert isinstance(case["result"]["success"], bool), index
    failures += case["result"]["success"] is False
    assert "MYSQL_DETAIL_DO_NOT_EXPORT" not in json.dumps(case["result"]), index
assert failures > 0

# A negative control proves the validator rejects a wrong result type.
invalid = copy.deepcopy(cases[0]["result"])
invalid["success"] = "false"
assert not Draft4Validator(cases[0]["schema"]).is_valid(invalid)

# The review's proposed envelope would fail the real generic executor schema.
# It is a negative test value, not an implementation of the proposed change.
if "generic_execution_schema" in report:
    proposed_error = {"code": "ability_invalid_input", "message": "Invalid input", "data": None}
    assert not Draft4Validator(report["generic_execution_schema"]).is_valid(proposed_error)

print(json.dumps({"status": "PASS", "ability_assertions": report.get("assertions", report.get("base_assertions")),
                  "native_pro_fixtures": report.get("native_pro_fixtures", False),
                  "schema_cases": len(cases), "failure_cases": failures,
                  "adapter_assertions": report.get("adapter_assertions"),
                  "adapter_version": report.get("adapter_version"),
                  "negative_schema_control": "PASS"}, indent=2))
