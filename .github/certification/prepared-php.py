#!/usr/bin/env python3
"""Freeze the PHP CLI runtime used by comparable Ubuntu load shards."""
import argparse
import hashlib
import json
import os
import pathlib
import shutil
import subprocess


def inspect_php(binary, environment=None):
    result = subprocess.run([
        str(binary), "-r",
        "echo json_encode(['version'=>PHP_VERSION,'binary'=>PHP_BINARY,"
        "'extensions'=>ini_get('extension_dir'),'ini'=>php_ini_loaded_file(),"
        "'scanned'=>php_ini_scanned_files(),'modules'=>get_loaded_extensions()], JSON_THROW_ON_ERROR);",
    ], env=environment, capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


def capture(root):
    information = inspect_php(shutil.which("php"))
    (root / "bin").mkdir(parents=True)
    (root / "original-ini").mkdir()
    shutil.copy2(information["binary"], root / "bin/php")
    shutil.copytree(information["extensions"], root / "extensions")
    original = pathlib.Path(information["ini"]).read_text() if information["ini"] else ""
    (root / "original-php.ini").write_text(original)
    scanned = information["scanned"] or ""
    for index, filename in enumerate(scanned.split(",")):
        if filename.strip():
            shutil.copyfile(filename.strip(), root / "original-ini" / f"{index:04}.ini")
    information["modules"].sort()
    information["binary_sha256"] = hashlib.sha256((root / "bin/php").read_bytes()).hexdigest()
    (root / "manifest.json").write_text(json.dumps(information, indent=2) + "\n")
    activate(root)


def runtime_environment(root):
    return dict(os.environ, PHPRC=str(root / "php.ini"), PHP_INI_SCAN_DIR=str(root / "ini"))


def activate(root):
    information = json.loads((root / "manifest.json").read_text())
    if hashlib.sha256((root / "bin/php").read_bytes()).hexdigest() != information["binary_sha256"]:
        raise ValueError("Prepared PHP executable does not match its captured identity")
    extensions = str(root / "extensions")
    original = (root / "original-php.ini").read_text().replace(information["extensions"], extensions)
    quoted = extensions.replace("\\", "\\\\").replace('"', '\\"')
    (root / "php.ini").write_text(original + f'\nextension_dir="{quoted}"\n')
    (root / "ini").mkdir(exist_ok=True)
    for source in (root / "original-ini").glob("*.ini"):
        (root / "ini" / source.name).write_text(source.read_text().replace(information["extensions"], extensions))
    actual = inspect_php(root / "bin/php", runtime_environment(root))
    if actual["version"] != information["version"] or sorted(actual["modules"]) != information["modules"]:
        raise ValueError("Prepared PHP version or loaded modules differ from dependency preparation")
    print(f"Verified prepared PHP {actual['version']} with {len(actual['modules'])} loaded modules")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=("capture", "activate"))
    parser.add_argument("root", type=pathlib.Path)
    args = parser.parse_args()
    {"capture": capture, "activate": activate}[args.operation](args.root.resolve())
