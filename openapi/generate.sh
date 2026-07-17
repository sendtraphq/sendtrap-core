#!/usr/bin/env bash
# Regenerate the derived contract artifacts (sendtrap.json, the Postman
# collection) from sendtrap.yaml — the YAML is the single source of truth;
# never edit the generated files by hand. Requires node/npx.
set -euo pipefail
cd "$(dirname "$0")"

npx -y js-yaml sendtrap.yaml > sendtrap.json

npx -y openapi-to-postmanv2 \
  -s sendtrap.yaml \
  -o sendtrap.postman_collection.json \
  -p -O folderStrategy=Tags,requestParametersResolution=Example

npx -y @redocly/cli lint sendtrap.yaml

echo "openapi artifacts regenerated + linted"
