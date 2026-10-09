#!/usr/bin/env python3
from pathlib import Path
import subprocess
import zipfile

root = Path(__file__).resolve().parents[1]
subprocess.run(["python3", "package.py"], cwd=root, check=True)
archive = root / "dist" / "sendrepute-drupal-0.1.0.zip"
with zipfile.ZipFile(archive) as package:
    names = package.namelist()
assert names
assert all(name.startswith("sendrepute/") for name in names)
assert "sendrepute/sendrepute.info.yml" in names
assert "sendrepute/src/Plugin/Mail/SendReputeMail.php" in names
assert "sendrepute/README.md" in names
assert "sendrepute/SECURITY.md" in names
assert not any("tests/" in name for name in names)
assert all("\\" not in name and not name.startswith("/") and ".." not in name.split("/") for name in names)
print("package fixtures passed")
