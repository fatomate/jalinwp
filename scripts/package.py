#!/usr/bin/env python3
"""Build deterministic JalinWP install/source ZIPs using Python's standard library.

Run from any directory: python3 /path/to/jalinwp/scripts/package.py
Source membership is deliberately curated: local WordPress sites, dependencies,
test run outputs, credentials, and build output are not release source files.
"""

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
import zipfile


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_NAME = "fames-mcp-gateway"
REQUIRED_ROOT = (
    "README.md", "LICENSE", "AGENTS.md", "CONTRIBUTING.md", "SECURITY.md",
    ".gitignore", ".gitattributes", ".editorconfig",
)
REQUIRED_PLUGIN = (
    "fames-mcp-gateway.php", "LICENSE", "readme.txt", "assets/brand.css",
    "assets/admin.css", "assets/admin.js", "assets/oauth.css", "assets/finance.css",
    "assets/brand/jalinwp-logo-horizontal-white.png", "assets/brand/favicon-32.png",
    "includes/class-oauth.php", "includes/class-admin.php", "docs/VALIDATION.md",
)
EXTRA_RUNTIME = (
    "test-runtime/brand-preview-tests.php", "test-runtime/admin-033-tests.php",
    "test-runtime/oauth-cleanup-tests.php", "test-runtime/admin-033-dom.cjs",
)
SOURCE_TREES = (PLUGIN_NAME, "docs", "scripts", ".github")
EVIDENCE_TREES = (
    "test-runtime/evidence", "test-runtime/evidence-0.3.2",
    "test-runtime/evidence-0.3.3", "test-runtime/brand-preview",
)
ROOT_FILES = (
    "source-kit-members.json", "build-validation-031.py",
    "package-0.3.1.py", "package-0.3.2.py", "package-0.3.3.py",
    *REQUIRED_ROOT,
)
BLOCKED_PARTS = {
    ".git", "node_modules", "__pycache__", ".pytest_cache", ".venv", "venv",
    "fixtures", "runs", "dist", "ui-preview", "admin-033-fixtures",
}
BLOCKED_SUFFIXES = {".pyc", ".zip", ".log", ".sql", ".sqlite", ".sqlite3", ".db", ".pem", ".key"}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def excluded(relative):
    """Defense in depth for each curated source candidate, including dotfiles."""
    parts = relative.parts
    # Versioned evidence may include deliberately retained test transcripts.
    # Ordinary runtime/plugin logs remain generated output and are excluded.
    evidence_log = relative.suffix.lower() == ".log" and any(
        relative.is_relative_to(Path(tree))
        for tree in EVIDENCE_TREES if Path(tree).name.startswith("evidence")
    )
    return (
        any(part in BLOCKED_PARTS or part.startswith("oauth-http-disposable-") for part in parts)
        or any(part.startswith(".env") and part != ".env.example" for part in parts)
        or relative.name in {".DS_Store", "wp-config.php", "SOURCE-MANIFEST.json"}
        or (relative.suffix.lower() in BLOCKED_SUFFIXES and not evidence_log)
    )


def checked_file(root, relative):
    relative = Path(relative)
    if relative.is_absolute() or ".." in relative.parts or excluded(relative):
        raise ValueError(f"Unsafe or generated source member: {relative}")
    path = root / relative
    if any((root / Path(*relative.parts[:i])).is_symlink() for i in range(1, len(relative.parts) + 1)):
        raise ValueError(f"Source member must not be a symlink: {relative}")
    if not path.is_file() or not path.resolve().is_relative_to(root.resolve()):
        raise ValueError(f"Missing source member: {relative}")
    return path


def version(root=ROOT):
    bootstrap = (root / PLUGIN_NAME / "fames-mcp-gateway.php").read_text(encoding="utf-8")
    readme = (root / PLUGIN_NAME / "readme.txt").read_text(encoding="utf-8")
    patterns = (
        (bootstrap, r"^\s*\*?\s*Version:\s*([^\s]+)\s*$"),
        (bootstrap, r"define\(\s*['\"]FG_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)"),
        (readme, r"^Stable tag:\s*([^\s]+)\s*$"),
    )
    versions = []
    for content, pattern in patterns:
        match = re.search(pattern, content, re.MULTILINE)
        if not match:
            raise ValueError("Missing plugin Version, FG_VERSION, or Stable tag")
        versions.append(match.group(1))
    if len(set(versions)) != 1 or not re.fullmatch(r"\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?", versions[0]):
        raise ValueError(f"Release version fields disagree or are invalid: {versions}")
    return versions[0]


def source_files(root=ROOT):
    root = root.resolve()
    members = set(ROOT_FILES) | set(EXTRA_RUNTIME)
    legacy = json.loads((root / "source-kit-members.json").read_text(encoding="utf-8"))
    if not isinstance(legacy, list) or not all(isinstance(name, str) for name in legacy):
        raise ValueError("source-kit-members.json must be a list of relative file names")
    members.update(legacy)
    members.update(path.name for path in root.glob("*.md"))
    for tree in SOURCE_TREES + EVIDENCE_TREES:
        directory = root / tree
        if directory.is_symlink():
            raise ValueError(f"Source directory must not be a symlink: {tree}")
        if not directory.exists():
            continue
        for path in directory.rglob("*"):
            relative = path.relative_to(root)
            if path.is_file() and not excluded(relative):
                members.add(relative.as_posix())
    return [checked_file(root, member) for member in sorted(members)]


def install_files(root=ROOT):
    prefix = PLUGIN_NAME + "/"
    return [path for path in source_files(root)
            if path.relative_to(root).as_posix().startswith(prefix)
            and "tests" not in path.relative_to(root / PLUGIN_NAME).parts
            and path.relative_to(root / PLUGIN_NAME).as_posix() != "bridge/test.mjs"]


def write_member(archive, name, data):
    # Stable timestamp and permissions produce identical archives from identical bytes.
    info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
    info.compress_type = zipfile.ZIP_DEFLATED
    info.create_system = 3
    info.external_attr = 0o100644 << 16
    archive.writestr(info, data)


def build(root=ROOT, output=None):
    root = root.resolve()
    output = (output or root / "dist").resolve()
    if output == root or root.is_relative_to(output):
        raise ValueError("Output must be a separate directory, not the repository root or its parent")
    if output.is_relative_to(root) and output.relative_to(root).parts[0] in {*SOURCE_TREES, "test-runtime"}:
        raise ValueError("Output must be outside maintained source directories; use dist/")
    release = version(root)
    sources = source_files(root)
    installer_files = install_files(root)
    for name in REQUIRED_PLUGIN:
        checked_file(root, f"{PLUGIN_NAME}/{name}")
    output.mkdir(parents=True, exist_ok=True)
    install = output / f"jalinwp-{release}.zip"
    source = output / f"jalinwp-{release}-source.zip"
    with zipfile.ZipFile(install, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for path in installer_files:
            write_member(archive, path.relative_to(root).as_posix(), path.read_bytes())
    manifest = {
        "brand": "JalinWP", "version": release, "source_root": "jalinwp/",
        "install_basename": f"{PLUGIN_NAME}/fames-mcp-gateway.php",
        "install_zip": {"name": install.name, "sha256": digest(install.read_bytes())},
        "files": [{"path": path.relative_to(root).as_posix(), "bytes": path.stat().st_size,
                   "sha256": digest(path.read_bytes())} for path in sources],
    }
    with zipfile.ZipFile(source, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for path in sources:
            write_member(archive, "jalinwp/" + path.relative_to(root).as_posix(), path.read_bytes())
        write_member(archive, "jalinwp/SOURCE-MANIFEST.json", (json.dumps(manifest, indent=2) + "\n").encode())
    with zipfile.ZipFile(install) as a, zipfile.ZipFile(source) as b:
        if a.testzip() is not None or b.testzip() is not None:
            raise ValueError("Archive integrity check failed")
        if len(set(a.namelist())) != len(a.namelist()) or len(set(b.namelist())) != len(b.namelist()):
            raise ValueError("Duplicate archive member")
        for name in a.namelist():
            if not name.startswith(PLUGIN_NAME + "/") or a.read(name) != b.read("jalinwp/" + name):
                raise ValueError(f"Install/source mismatch: {name}")
        for record in manifest["files"]:
            data = b.read("jalinwp/" + record["path"])
            if len(data) != record["bytes"] or digest(data) != record["sha256"]:
                raise ValueError(f"Manifest mismatch: {record['path']}")
    report = {
        "status": "passed", "version": release, "install": install.name, "source": source.name,
        "install_members": len(installer_files), "source_members": len(sources) + 1,
        "install_sha256": digest(install.read_bytes()), "source_sha256": digest(source.read_bytes()),
        "checks": ["Version agreement", "Curated source membership", "Required assets and metadata",
                   "ZIP integrity", "Legacy plugin basename", "Install/source byte identity", "Source manifest"],
    }
    (output / "package-verification.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, help="Output directory (default: repository dist/)")
    args = parser.parse_args()
    try:
        print(json.dumps(build(output=args.output), indent=2))
    except (OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
        print(f"Packaging failed: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
