"""Package the JalinWP admin and connection cleanup update without dependencies or disposable sites."""
from pathlib import Path
import argparse
import hashlib
import json
import zipfile

ROOT = Path(__file__).resolve().parent
PLUGIN = ROOT / "fames-mcp-gateway"
VERSION = "0.3.3"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=ROOT / "release-0.3.3")
    output = parser.parse_args().output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    bootstrap = (PLUGIN / "fames-mcp-gateway.php").read_text()
    assert "Plugin Name: JalinWP" in bootstrap
    assert f"Version: {VERSION}" in bootstrap
    assert f"define('FG_VERSION', '{VERSION}')" in bootstrap
    assert f"Stable tag: {VERSION}" in (PLUGIN / "readme.txt").read_text()
    evidence = ROOT / "test-runtime" / f"evidence-{VERSION}"
    evidence_index = json.loads((evidence / "index.json").read_text())
    assert evidence_index["release"] == VERSION
    for check in evidence_index["checks"]:
        data = (evidence / check["file"]).read_bytes()
        assert digest(data) == check["sha256"], check["file"]
        result = json.loads(data)
        assert result.get("status", "passed") == "passed", check["file"]
        summary = json.loads(result["output"]) if isinstance(result.get("output"), str) else result
        assert summary["passed"] == summary["total"] == check["total"], check["file"]
        assert not any(isinstance(case, dict) and case.get("pass") is False
                       for case in summary.get("cases", [])), check["file"]

    all_plugin = sorted(p for p in PLUGIN.rglob("*") if p.is_file() and not p.is_symlink())
    plugin_files = [p for p in all_plugin
                    if "tests" not in p.relative_to(PLUGIN).parts
                    and p.relative_to(PLUGIN).as_posix() != "bridge/test.mjs"]
    install = output / f"jalinwp-{VERSION}.zip"
    with zipfile.ZipFile(install, "w", zipfile.ZIP_DEFLATED) as archive:
        for file in plugin_files:
            archive.write(file, file.relative_to(ROOT).as_posix())

    # Original kit membership is explicit, so fixture/database/runtime debris can
    # never slip into the source release through a recursive workspace walk.
    members = json.loads((ROOT / "source-kit-members.json").read_text())
    sources = {ROOT / name for name in members}
    sources.update(all_plugin)
    sources.update(ROOT / name for name in [
        "START-HERE.md", "source-kit-members.json", "package-0.3.3.py",
        "package-0.3.2.py", "test-runtime/brand-preview-tests.php",
        "test-runtime/admin-033-tests.php", "test-runtime/oauth-cleanup-tests.php",
        "test-runtime/admin-033-dom.cjs",
    ])
    for name in ["test-runtime/evidence-0.3.2", "test-runtime/evidence-0.3.3", "test-runtime/brand-preview"]:
        sources.update(p for p in (ROOT / name).rglob("*") if p.is_file())
    sources = sorted(sources)
    for file in sources:
        assert file.is_file() and not file.is_symlink(), file
        assert file.resolve().is_relative_to(ROOT), file
        assert not any(part in {"node_modules", "fixtures", "runs"}
                       or part.startswith("oauth-http-disposable-")
                       for part in file.relative_to(ROOT).parts), file

    manifest = {
        "brand": "JalinWP", "version": VERSION,
        "install_basename": "fames-mcp-gateway/fames-mcp-gateway.php",
        "install_zip": {"name": install.name, "sha256": digest(install.read_bytes())},
        "files": [{"path": file.relative_to(ROOT).as_posix(),
                   "bytes": file.stat().st_size, "sha256": digest(file.read_bytes())}
                  for file in sources],
    }
    manifest_path = ROOT / "SOURCE-MANIFEST.json"
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n")
    source = output / f"jalinwp-{VERSION}-source.zip"
    with zipfile.ZipFile(source, "w", zipfile.ZIP_DEFLATED) as archive:
        for file in sources + [manifest_path]:
            archive.write(file, "jalinwp-source/" + file.relative_to(ROOT).as_posix())

    required = ["fames-mcp-gateway.php", "assets/brand.css", "assets/admin.css",
                "assets/oauth.css", "assets/finance.css",
                "assets/brand/jalinwp-logo-horizontal-white.png",
                "assets/brand/favicon-32.png", "includes/class-oauth.php",
                "includes/class-admin.php", "docs/UPGRADE-0.3.3.md", "docs/VALIDATION.md"]
    with zipfile.ZipFile(install) as a, zipfile.ZipFile(source) as b:
        assert a.testzip() is None and b.testzip() is None
        for name in required:
            assert "fames-mcp-gateway/" + name in a.namelist(), name
        assert len(set(a.namelist())) == len(a.namelist())
        assert len(set(b.namelist())) == len(b.namelist())
        for name in a.namelist():
            assert name.startswith("fames-mcp-gateway/"), name
            assert a.read(name) == b.read("jalinwp-source/" + name), name
        for record in manifest["files"]:
            assert digest(b.read("jalinwp-source/" + record["path"])) == record["sha256"]
        report = {"status": "passed", "install_members": len(a.namelist()),
                  "source_members": len(b.namelist()), "install": install.name,
                  "source": source.name, "sha256": manifest["install_zip"]["sha256"],
                  "checks": ["ZIP integrity", "Legacy plugin basename", "Version agreement",
                             "Required brand assets and release notes", "Install/source byte identity",
                             "Source hashes", "Current evidence hashes and assertion counts"]}
    (output / "package-verification.json").write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
