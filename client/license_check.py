"""
License-check client. Bake this into the product's Docker image (or run it
from the entrypoint script) regardless of what language the main app is
written in -- it only needs the fingerprint and license key at runtime.

Usage:
    python license_check.py activate <license_key> <fingerprint>
    python license_check.py validate <license_key> <fingerprint>

The fingerprint must come from the host (see Get-Fingerprint.ps1) since a
container cannot read the host's MachineGuid / BIOS UUID itself.
"""

import os
import sys
import json
import urllib.request
import urllib.error

API_BASE_URL = os.environ.get("LICENSE_API_BASE_URL", "http://localhost:8080")


def call(endpoint: str, license_key: str, fingerprint: str) -> dict:
    url = f"{API_BASE_URL}/{endpoint}"
    payload = json.dumps({"license_key": license_key, "fingerprint": fingerprint}).encode()
    req = urllib.request.Request(url, data=payload, headers={"Content-Type": "application/json"}, method="POST")

    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return {"http_status": resp.status, **json.loads(resp.read())}
    except urllib.error.HTTPError as e:
        return {"http_status": e.code, **json.loads(e.read())}


def main() -> int:
    if len(sys.argv) != 4 or sys.argv[1] not in ("activate", "validate"):
        print(__doc__)
        return 1

    _, action, license_key, fingerprint = sys.argv
    result = call(action, license_key, fingerprint)
    print(json.dumps(result, indent=2))

    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    sys.exit(main())
