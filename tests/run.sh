#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p 'test_*.py' -v
php tests/provider_security.php
php tests/settings_status.php
php tests/runtime_service.php
node tests/provider_status.js
node tests/host_status.js
find app provider -name '*.php' ! -path 'provider/config/config.php' -print0 | xargs -0 -n 1 php -l
find app -name '*.js' -print0 | xargs -0 -n 1 node --check
python3 - <<'PY'
import ast
import subprocess
from pathlib import Path
for directory in ('runtime', 'installer', 'tests', 'service', 'provider/system', 'provider/bin'):
    for file in Path(directory).rglob('*'):
        if file.is_file() and (file.suffix == '.py' or file.read_bytes().startswith(b'#!/usr/bin/python3')):
            ast.parse(file.read_text(), filename=str(file))
        elif file.is_file() and file.read_bytes().split(b'\n', 1)[0] in (b'#!/bin/sh', b'#!/bin/bash', b'#!/usr/bin/env bash'):
            subprocess.run(['bash', '-n', str(file)], check=True)
PY
git diff --check
