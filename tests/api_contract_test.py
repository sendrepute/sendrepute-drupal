#!/usr/bin/env python3
"""Validate fixtures against the exact /v1/classify OpenAPI operation."""

from pathlib import Path
import json
import re

root = Path(__file__).resolve().parents[3]
openapi_path = root / "artifacts/api-server/src/customer-api-openapi.json"
client_path = root / "integrations/drupal/sendrepute/src/Service/SendReputeClient.php"
route_path = root / "artifacts/api-server/src/routes/customer-api.ts"
document = json.loads(openapi_path.read_text(encoding="utf-8"))
client = client_path.read_text(encoding="utf-8")
route = route_path.read_text(encoding="utf-8")

operation = document["paths"]["/v1/classify"]["post"]
request_schema = operation["requestBody"]["content"]["application/json"]["schema"]
response_schema = operation["responses"]["200"]["content"]["application/json"]["schema"]
assert request_schema == {"$ref": "#/components/schemas/CustomerClassificationInput"}
assert response_schema == {"$ref": "#/components/schemas/CustomerClassificationResponse"}


def resolve(schema):
    if "$ref" not in schema:
        return schema
    node = document
    for part in schema["$ref"].removeprefix("#/").split("/"):
        node = node[part]
    return node


def validate(value, schema, path="$"):
    schema = resolve(schema)
    kind = schema.get("type")
    if kind == "object":
        assert isinstance(value, dict), f"{path}: expected object"
        properties = schema.get("properties", {})
        for key in schema.get("required", []):
            assert key in value, f"{path}: missing {key}"
        if schema.get("additionalProperties") is False:
            assert not set(value) - set(properties), f"{path}: additional properties"
        for key, item in value.items():
            if key in properties:
                validate(item, properties[key], f"{path}.{key}")
    elif kind == "array":
        assert isinstance(value, list), f"{path}: expected array"
        assert len(value) <= schema.get("maxItems", len(value))
        for index, item in enumerate(value):
            validate(item, schema["items"], f"{path}[{index}]")
    elif kind == "string":
        assert isinstance(value, str), f"{path}: expected string"
        assert len(value) >= schema.get("minLength", 0)
        assert len(value) <= schema.get("maxLength", len(value))
        if "enum" in schema:
            assert value in schema["enum"]
    elif kind == "number":
        assert isinstance(value, (int, float)) and not isinstance(value, bool)
        assert value >= schema.get("minimum", value)
        assert value <= schema.get("maximum", value)
        if schema.get("multipleOf") == 1:
            assert int(value) == value
    elif kind == "boolean":
        assert isinstance(value, bool)
    if "enum" in schema:
        assert value in schema["enum"]
    if "const" in schema:
        assert value == schema["const"]


request_fixture = {
    "sender": "Example Store",
    "subject": "Contract fixture",
    "body": "SENDREPUTE-FINAL-BODY/1\nbytes=4\n\nBody",
}
response_fixture = {
    "requestId": "req_contract_fixture",
    "model": "thor",
    "result": {
        "label": "spam",
        "spamProbability": 0.95,
        "confidence": "high",
        "reasons": [{"signal": "fixture", "detail": "offline", "weight": 1}],
        "flaggedTerms": [],
        "analyzedFields": ["sender", "subject", "body"],
        "modelVersion": "fixture-1",
        "analyzedAt": "2026-01-01T00:00:00Z",
    },
    "billing": {"chargedMillicents": 1000, "replayed": False},
}
validate(request_fixture, request_schema)
validate(response_fixture, response_schema)

payload = re.search(r"\$payload = json_encode\(\[(?P<body>.*?)\],", client, re.S)
assert payload, "production request payload was not found"
members = re.findall(r"'([^']+)'\s*=>", payload.group("body"))
assert members == ["sender", "subject", "body"], members
assert "$decoded['result']['spamProbability']" in client
assert "$value['billing']" in client

# Trace both actual runtimes: their success envelopes must match the path schema.
assert 'router.post("/v1/classify"' in route
assert "res.json({ requestId: claim.requestId, model: parsed.model, result: settled.result, billing:" in route
worker = (root / "artifacts/api-server/src/worker.ts").read_text(encoding="utf-8")
assert "return json({requestId:claim.requestId,model:parsed.model,result:settled.result,billing:" in worker
print("exact /v1/classify OpenAPI and route contract fixture passed")