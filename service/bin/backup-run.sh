#!/usr/bin/python3 -I
import sys
sys.path.insert(0, '/usr/local/lib/backupmanager')
from backupmanager.config import load
from backupmanager.engine import Engine
Engine(load()).backup()
