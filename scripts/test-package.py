#!/usr/bin/env python3
"""Exercise release inclusion, exclusions, reproducibility, and failure guards.

Runs entirely in a temporary copied repository. Does not mutate this checkout.
Use alongside check-repository.py; these checks do not execute WordPress tests.
"""

import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile


SCRIPT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("jalinwp_package", SCRIPT / "package.py")
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class PackageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="jalinwp-package-tests-")
        cls.root = Path(cls.temp.name) / "jalinwp"
        cls.original = package.source_files(package.ROOT)
        for file in cls.original:
            target = cls.root / file.relative_to(package.ROOT)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(file, target)
        cls.release = package.version(cls.root)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def test_01_archive_members_and_exclusions(self):
        artifacts = [
            ".git/config", ".pi/session.json", ".env", ".env.production", "credentials/private.key",
            "scripts/test-runtime/node_modules/dependency/index.js", "scripts/test-runtime/fixtures/site/wp-config.php", "scripts/test-runtime/brand-preview/generated.html",
            "scripts/test-runtime/runs/a/db.sqlite", "scripts/test-runtime/oauth-http-disposable-a/secret.json",
            "scripts/test-runtime/new-tests-output.json", "scripts/test-runtime/debug.log", "SOURCE-MANIFEST.json",
            "scripts/test-runtime/connection-trace-render.html", "scripts/test-runtime/finance-admin-hpos-render.html",
            "scripts/test-runtime/brand-verification.json", "scripts/test-runtime/wp-tools.json",
            "dist/previous.zip", "custom-output/unexpected.json",
            f"{package.PLUGIN_NAME}/.env", f"{package.PLUGIN_NAME}/wp-config.php",
            f"{package.PLUGIN_NAME}/private.pem", f"{package.PLUGIN_NAME}/backup.sql",
            f"{package.PLUGIN_NAME}/debug.log",
            f"{package.PLUGIN_NAME}/node_modules/dependency/index.js",
        ]
        for name in artifacts:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("synthetic private or generated test marker", encoding="utf-8")
        report = package.build(self.root)
        with zipfile.ZipFile(self.root / "dist" / report["source"]) as source, zipfile.ZipFile(self.root / "dist" / report["install"]) as install:
            self.assertIsNone(source.testzip())
            self.assertIsNone(install.testzip())
            for name in artifacts:
                if name != "SOURCE-MANIFEST.json":
                    self.assertNotIn("jalinwp/" + name, source.namelist())
                    self.assertNotIn(name, install.namelist())
            for name in package.REQUIRED_ROOT:
                self.assertIn("jalinwp/" + name, source.namelist())
            historical_log = "docs/evidence/evidence-0.3.2/bridge-brand-run.log"
            self.assertEqual((self.root / historical_log).read_bytes(), source.read("jalinwp/" + historical_log))
            for name in package.REQUIRED_PLUGIN:
                self.assertIn(package.PLUGIN_NAME + "/" + name, install.namelist())
            expected = {file.relative_to(package.ROOT).as_posix(): file.read_bytes()
                        for file in package.install_files(package.ROOT)}
            self.assertEqual(set(expected), set(install.namelist()))
            for name, original_bytes in expected.items():
                self.assertEqual(original_bytes, install.read(name), name)
                self.assertEqual(original_bytes, source.read("jalinwp/" + name), name)
            self.assertNotIn("jalinwp/scripts/bridge/test.mjs", install.namelist())
            self.assertFalse(any("/tests/" in name for name in install.namelist()))
            manifest = json.loads(source.read("jalinwp/SOURCE-MANIFEST.json"))
            self.assertEqual(self.release, manifest["version"])
            self.assertEqual(report["install_sha256"], manifest["install_zip"]["sha256"])

    def test_02_reproducible_custom_output(self):
        first = package.build(self.root, self.root / "build-a")
        second = package.build(self.root, self.root / "build-b")
        self.assertEqual(first["install_sha256"], second["install_sha256"])
        self.assertEqual(first["source_sha256"], second["source_sha256"])
        with zipfile.ZipFile(self.root / "build-b" / second["source"]) as source:
            self.assertFalse(any("/build-a/" in name or "/build-b/" in name for name in source.namelist()))

    def test_03_version_mismatch_fails(self):
        file = self.root / package.PLUGIN_NAME / "readme.txt"
        original = file.read_text(encoding="utf-8")
        try:
            file.write_text(original.replace("Stable tag: " + self.release, "Stable tag: 0.0.0"), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "version fields disagree"):
                package.build(self.root)
        finally:
            file.write_text(original, encoding="utf-8")

    def test_04_unsafe_manifest_path_fails(self):
        file = self.root / "scripts" / "source-kit-members.json"
        original = file.read_text(encoding="utf-8")
        try:
            members = json.loads(original)
            members.append("../outside-secret.txt")
            file.write_text(json.dumps(members), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "Unsafe or generated source member"):
                package.source_files(self.root)
        finally:
            file.write_text(original, encoding="utf-8")

    def test_05_required_source_member_omission_fails(self):
        required = json.loads((self.root / "scripts/source-kit-members.json").read_text(encoding="utf-8"))[0]
        (self.root / required).unlink()
        with self.assertRaisesRegex(ValueError, "Missing source member"):
            package.source_files(self.root)
        shutil.copyfile(package.ROOT / required, self.root / required)

    def test_06_symlink_source_fails(self):
        link = self.root / package.PLUGIN_NAME / "assets" / "unexpected.js"
        try:
            link.symlink_to(self.root / "README.md")
        except (OSError, NotImplementedError):
            self.skipTest("Platform cannot create test symlinks")
        try:
            with self.assertRaisesRegex(ValueError, "symlink"):
                package.source_files(self.root)
        finally:
            link.unlink()

    def test_07_source_output_directory_fails(self):
        for directory in [self.root, self.root.parent, self.root / package.PLUGIN_NAME / "build", self.root / "scripts" / "test-runtime" / "build"]:
            with self.assertRaises(ValueError):
                package.build(self.root, directory)

    def test_08_source_archive_rebuilds_the_install_archive(self):
        report = package.build(self.root, self.root / "source-build")
        extract = self.root / "extracted"
        with zipfile.ZipFile(self.root / "source-build" / report["source"]) as archive:
            archive.extractall(extract)
        rebuilt = package.build(extract / "jalinwp", extract / "dist")
        self.assertEqual(report["install_sha256"], rebuilt["install_sha256"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
