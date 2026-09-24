"""Test-only process bridge: all mutable paths must belong to the private fixture."""
import json
import os
import pwd
import sys
from pathlib import Path

ROOT = Path(os.environ['BM_JOURNEY_ROOT']).resolve()
if ROOT.parent != Path('/tmp') or not ROOT.name.startswith('bm-integration-'):
    raise SystemExit('Unsafe fixture root')
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'runtime'))
from backupmanager import client_helpers, config, provider_helpers
args = [v for v in sys.argv[1:] if v != '-n']
action = Path(args[0]).name
account = pwd.getpwuid(os.getuid())
provider_helpers.pwd.getpwnam = lambda name: account
client_helpers.CONFIG = str(ROOT / 'runtime.json')
client_helpers.load = lambda: config.load(ROOT / 'runtime.json')
provider_helpers.ROOT = ROOT / 'storage'
provider_helpers.ACCOUNT = ROOT / 'account'
with (ROOT / 'helper-calls').open('a') as out:
    out.write(action + '\n')
sys.argv = [action] + args[1:]
if action in ('backupmanager-recovery-request-info', 'backupmanager-request-info', 'backupmanager-apply-provider-config'):
    cfg = client_helpers.load()
    for field in ('runtime', 'nc_path', 'data_path', 'ssh_key', 'ssh_read_key', 'known_hosts'):
        if not Path(cfg[field]).resolve().is_relative_to(ROOT):
            raise SystemExit('Live fixture path refused: ' + field)
    raise SystemExit(client_helpers.main())
if action == 'backupmanager-install-authorized-keys':
    raise SystemExit(provider_helpers.main())
raise SystemExit('Unexpected fixture helper: ' + action)
