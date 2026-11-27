"""Build the installable add-on ZIP and changelog notes from this checkout."""

import argparse
import hashlib
from pathlib import Path
import re
import zipfile


SLUG = "admin-suite-unified-shop-extras-for-mainwp"
ROOT = Path(__file__).resolve().parents[1]


def build(version: str, output: Path, changelog_file: Path) -> None:
    if not re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", version):
        raise SystemExit("Expected a stable version without the v prefix.")
    entry = ROOT / f"{SLUG}.php"
    header = entry.read_text(encoding="utf-8")
    header_version = re.search(r"(?m)^\s*\*\s*Version:\s*(\S+)\s*$", header)
    if header_version is None or header_version.group(1) != version:
        raise SystemExit("The requested version differs from the plugin header.")

    changelog = changelog_file.read_text(encoding="utf-8")
    section = re.search(rf"(?ms)^## {re.escape(version)} - \d{{4}}-\d{{2}}-\d{{2}}\n.*?(?=^## |\Z)", changelog)
    if section is None:
        raise SystemExit("The requested version is missing from CHANGELOG.md.")
    notes = section.group().rstrip() + "\n"
    bullets = [line for line in notes.splitlines()[1:] if line.strip()]
    categories = r"NEW|IMPROVE|FIX|SECURITY|COMPAT|DEPRECATE|REMOVE|DEV|TRANSLATIONS"
    if not bullets or any(not re.fullmatch(rf"- \[(?:{categories})\] .+", line) for line in bullets):
        raise SystemExit("Release notes require categorized changelog bullets.")

    files = [entry, ROOT / "LICENSE", ROOT / "README.md", ROOT / "CHANGELOG.md", ROOT / "assets/products-icon.png"]
    files.extend(sorted((ROOT / "src").rglob("*.php")))
    output.mkdir(parents=True, exist_ok=True)
    artifact = output / f"{SLUG}-{version}.zip"
    with zipfile.ZipFile(artifact, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for file in files:
            if file.is_symlink() or not file.is_file():
                raise SystemExit(f"Unexpected build input: {file.relative_to(ROOT)}")
            relative = file.relative_to(ROOT).as_posix()
            item = zipfile.ZipInfo(f"{SLUG}/{relative}", (2026, 1, 1, 0, 0, 0))
            item.create_system = 3
            item.external_attr = 0o100644 << 16
            archive.writestr(item, file.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with zipfile.ZipFile(artifact) as archive:
        if archive.testzip() is not None or len(archive.namelist()) != len(files):
            raise SystemExit("ZIP verification failed.")
        for file in files:
            if archive.read(f"{SLUG}/{file.relative_to(ROOT).as_posix()}") != file.read_bytes():
                raise SystemExit("ZIP bytes differ from the checkout.")

    digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
    (output / "SHA256SUMS").write_text(f"{digest}  {artifact.name}\n", encoding="utf-8", newline="\n")
    (output / "release-notes.md").write_text(notes, encoding="utf-8", newline="\n")
    print(f"Built and verified {artifact.name} ({len(files)} files), SHA-256 {digest}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--changelog", type=Path, default=ROOT / "CHANGELOG.md")
    args = parser.parse_args()
    build(args.version, args.output.resolve(), args.changelog)
