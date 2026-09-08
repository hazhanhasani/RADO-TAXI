#!/usr/bin/env bash
set -euo pipefail

: "${ACTIONS_ID_TOKEN_REQUEST_URL:?GitHub OIDC URL missing}"
: "${ACTIONS_ID_TOKEN_REQUEST_TOKEN:?GitHub OIDC token missing}"
: "${GITHUB_ENV:?GITHUB_ENV missing}"

AUDIENCE="${RADO_OIDC_AUDIENCE:-rado-taxi.sbs}"
ENDPOINT="${RADO_CI_CREDENTIALS_URL:-https://rado-taxi.sbs/api/ci-credentials/}"
TMP_JSON="$(mktemp)"
trap 'rm -f "$TMP_JSON"' EXIT

OIDC_JSON="$(curl --fail --silent --show-error \
  -H "Authorization: bearer ${ACTIONS_ID_TOKEN_REQUEST_TOKEN}" \
  "${ACTIONS_ID_TOKEN_REQUEST_URL}&audience=${AUDIENCE}")"

OIDC_TOKEN="$(OIDC_JSON="$OIDC_JSON" python3 - <<'PY'
import json, os
v=json.loads(os.environ['OIDC_JSON']).get('value','')
if not v: raise SystemExit('GitHub OIDC token missing')
print(v)
PY
)"

curl --fail --silent --show-error \
  -H "Authorization: Bearer ${OIDC_TOKEN}" \
  "$ENDPOINT" > "$TMP_JSON"
chmod 600 "$TMP_JSON"

python3 - "$TMP_JSON" "$GITHUB_ENV" <<'PY'
import json, sys
path, env_path = sys.argv[1], sys.argv[2]
with open(path, encoding='utf-8') as f:
    data=json.load(f)
if not data.get('ok'):
    raise SystemExit(data.get('error','RADO credential broker rejected the request'))
mapping={
    'KEYSTORE_B64':'keystore_base64',
    'STORE_PASSWORD':'store_password',
    'KEY_ALIAS':'key_alias',
    'KEY_PASSWORD':'key_password',
}
with open(env_path,'a',encoding='utf-8') as out:
    for env_name, key in mapping.items():
        value=str(data.get(key,'')).strip()
        if not value:
            raise SystemExit(f'missing credential: {key}')
        print(f'::add-mask::{value}')
        out.write(f'{env_name}<<RADO_EOF\n{value}\nRADO_EOF\n')
PY
