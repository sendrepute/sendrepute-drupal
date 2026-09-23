#!/usr/bin/env python3
"""Build the installable SendRepute Drupal archive from an allowlist."""

from pathlib import Path
import stat
import zipfile

ROOT = Path(__file__).resolve().parent
MODULE = ROOT / "sendrepute"
OUTPUT = ROOT / "dist" / "sendrepute-drupal-0.1.0.zip"
ALLOWED_SUFFIXES = {".php", ".yml"}
ALLOWED_NAMES = {"LICENSE"}
PACKAGE_DOCUMENTS = (ROOT / "README.md", ROOT / "SECURITY.md")


def files():
    for path in sorted(MODULE.rglob("*")):
        if path.is_symlink():
            raise SystemExit(f"Refusing symlink: {path.relative_to(ROOT)}")
        if not path.is_file():
            continue
        if path.name not in ALLOWED_NAMES and path.suffix not in ALLOWED_SUFFIXES:
            raise SystemExit(f"Unexpected module file: {path.relative_to(ROOT)}")
        yield path


def main():
    selected = list(files())
    required = {
        "sendrepute/sendrepute.info.yml",
        "sendrepute/src/Plugin/Mail/SendReputeMail.php",
        "sendrepute/LICENSE",
    }
    names = {path.relative_to(ROOT).as_posix() for path in selected}
    if not required.issubset(names):
        raise SystemExit("Required package files are missing.")
    OUTPUT.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as archive:
        for path in selected:
            name = path.relative_to(ROOT).as_posix()
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info, path.read_bytes())
        for path in PACKAGE_DOCUMENTS:
            if path.is_symlink() or not path.is_file():
                raise SystemExit(f"Required package document is invalid: {path.name}")
            info = zipfile.ZipInfo(f"sendrepute/{path.name}", date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info, path.read_bytes())
    print(f"Wrote {OUTPUT.relative_to(ROOT)} with {len(selected) + len(PACKAGE_DOCUMENTS)} files.")


if __name__ == "__main__":
    main()
