#!/usr/bin/env python3
"""Verify that load shards use a captured runtime, rather than a moving installer."""
import importlib.util
import json
import os
import pathlib
import shutil
import tempfile
import unittest
from unittest import mock

DIRECTORY = pathlib.Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("prepared_php", DIRECTORY / "prepared-php.py")
RUNTIME = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNTIME)


class PreparedPhpTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.capture_directory = tempfile.TemporaryDirectory(prefix="webrick-php-capture-")
        cls.snapshot = pathlib.Path(cls.capture_directory.name) / "runtime"
        RUNTIME.capture(cls.snapshot)

    @classmethod
    def tearDownClass(cls):
        cls.capture_directory.cleanup()

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="webrick-php-restore-")
        self.addCleanup(self.directory.cleanup)
        self.root = pathlib.Path(self.directory.name) / "relocated runtime"
        shutil.copytree(self.snapshot, self.root)
        self.manifest = json.loads((self.root / "manifest.json").read_text())

    def test_relocation_preserves_version_modules_and_direct_child_binary(self):
        RUNTIME.activate(self.root)
        environment = RUNTIME.runtime_environment(self.root)
        actual = RUNTIME.inspect_php(self.root / "bin/php", environment)
        self.assertEqual(self.manifest["version"], actual["version"])
        self.assertEqual(self.manifest["modules"], sorted(actual["modules"]))
        self.assertEqual(str(self.root / "extensions"), actual["extensions"])
        self.assertEqual(str(self.root / "php.ini"), actual["ini"])
        child = RUNTIME.inspect_php(actual["binary"], environment)
        self.assertEqual(actual["version"], child["version"])
        self.assertEqual(actual["modules"], child["modules"])

    def test_host_php_selection_cannot_change_prepared_runtime(self):
        host = pathlib.Path(self.directory.name) / "host-bin"
        host.mkdir()
        (host / "php").write_text("#!/bin/sh\necho incompatible-host-PHP\n")
        (host / "php").chmod(0o755)
        with mock.patch.dict(os.environ, {"PATH": str(host) + os.pathsep + os.environ["PATH"]}):
            self.assertEqual(str(host / "php"), shutil.which("php"))
            RUNTIME.activate(self.root)

    def test_changed_executable_is_rejected_before_execution(self):
        (self.root / "bin/php").write_bytes(b"not the captured PHP executable")
        with self.assertRaisesRegex(ValueError, "captured identity"):
            RUNTIME.activate(self.root)

    def test_wrong_patch_identity_is_rejected(self):
        self.manifest["version"] = "8.5.999"
        (self.root / "manifest.json").write_text(json.dumps(self.manifest))
        with self.assertRaisesRegex(ValueError, "version or loaded modules differ"):
            RUNTIME.activate(self.root)

    def test_missing_extension_modules_are_rejected(self):
        shutil.rmtree(self.root / "extensions")
        (self.root / "extensions").mkdir()
        with self.assertRaises(ValueError):
            RUNTIME.activate(self.root)


if __name__ == "__main__":
    unittest.main()
