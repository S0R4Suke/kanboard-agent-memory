#!/usr/bin/env python3
"""Wait for the fixed, isolated 8091 test server; read-only, no business endpoint."""
from pathlib import Path
import sys
import time
sys.path.insert(0,str(Path(__file__).resolve().parent.parent/'examples'))
from client import Client
client=Client('http://127.0.0.1:8091/jsonrpc.php','admin','admin')
for attempt in range(40):
    try:
        if client.call('getVersion')=='v1.2.55':break
    except (OSError,RuntimeError):pass
    time.sleep(1)
else:raise RuntimeError('Isolated Kanboard test stack did not become ready')
