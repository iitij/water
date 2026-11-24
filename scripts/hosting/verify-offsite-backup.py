#!/usr/bin/env python3
"""Verify an off-host Water backup without extracting plaintext files."""
import argparse
import hashlib
import os
from pathlib import Path
import re
import stat
import subprocess
import tarfile
import tempfile

PAYLOAD = {"database.sql", "source.tar", "application.env", "uploads.tar", "public-web.tar", "manifest.txt"}
MAX_METADATA = 65536


def require(condition, message):
    if not condition:
        raise ValueError(message)


def private_file(path):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode), "Expected a regular file, not a symlink")
    require(info.st_uid == os.getuid(), "File is not owned by the current user")
    require(not info.st_mode & 0o077, "Set file permissions to 600 before verification")


def sha256(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def verify(archive, key):
    private_file(archive)
    private_file(key)
    require(archive.resolve() != key.resolve(), "Archive and key must be separate files")
    require(key.stat().st_size <= 128, "Unexpected key format")
    require(re.fullmatch(rb"[0-9a-f]{64}\n?", key.read_bytes()), "Unexpected key format")
    require(re.fullmatch(r"water-\d{8}T\d{6}Z-[a-f0-9]{6}\.tar\.gz\.gpg", archive.name), "Unexpected archive filename")
    sidecar = archive.with_name(archive.name + ".sha256")
    private_file(sidecar)
    require(sidecar.stat().st_size <= MAX_METADATA, "Invalid outer checksum file")
    outer = re.fullmatch(r"([0-9a-f]{64})  " + re.escape(archive.name) + r"\n?", sidecar.read_text())
    require(outer and outer[1] == sha256(archive), "Outer SHA-256 verification failed")
    digests = {}
    metadata = {}
    seen = set()
    with tempfile.TemporaryDirectory(prefix="water-gpg-") as gnupg:
        with subprocess.Popen([
            "gpg", "--homedir", gnupg, "--batch", "--no-tty", "--pinentry-mode", "loopback",
            "--passphrase-file", str(key.resolve()), "--decrypt", str(archive.resolve())
        ], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL) as process:
            try:
                with tarfile.open(fileobj=process.stdout, mode="r|gz") as stream:
                    for member in stream:
                        name = member.name.removeprefix("./")
                        if member.isdir() and name in {"", "."}:
                            continue
                        require(member.isfile() and name in PAYLOAD | {"SHA256SUMS"}, "Unexpected archive entry")
                        require(name not in seen, "Duplicate archive entry")
                        seen.add(name)
                        require(member.size > 0, "Empty archive entry")
                        with stream.extractfile(member) as content:
                            if name in {"manifest.txt", "SHA256SUMS"}:
                                require(member.size <= MAX_METADATA, "Oversized metadata")
                                data = content.read()
                                metadata[name] = data.decode("utf-8")
                                digests[name] = hashlib.sha256(data).hexdigest()
                            else:
                                digests[name] = hashlib.file_digest(content, "sha256").hexdigest()
                # Drain trailing tar padding; GPG must authenticate the full stream.
                while process.stdout.read(65536):
                    pass
                require(process.wait() == 0, "GPG authentication or decryption failed")
            except BaseException:
                if process.poll() is None:
                    process.kill()
                raise
    require(seen == PAYLOAD | {"SHA256SUMS"}, "Missing archive entries")
    expected = {}
    for line in metadata["SHA256SUMS"].splitlines():
        match = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        require(match and match[2] in PAYLOAD and match[2] not in expected, "Invalid inner checksum list")
        expected[match[2]] = match[1]
    require(set(expected) == PAYLOAD, "Incomplete inner checksum list")
    require(all(digests[name] == value for name, value in expected.items()), "Inner SHA-256 verification failed")
    manifest = dict(line.split("=", 1) for line in metadata["manifest.txt"].splitlines())
    require(manifest.get("format") == "water-backup-v1", "Unknown backup format")
    require(manifest.get("database") == "alweufti_water", "Unexpected database")
    require(re.fullmatch(r"[a-f0-9]{40}", manifest.get("source_commit", "")), "Invalid commit")
    require(re.fullmatch(r"[1-9]\d*", manifest.get("tables", "")), "Invalid table count")
    require(re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", manifest.get("created_utc", "")), "Invalid timestamp")
    print("SOURCE_COMMIT:", manifest["source_commit"])
    print("CREATED_UTC:", manifest["created_utc"])
    print("TABLES_IN_MANIFEST:", manifest["tables"])
    print("OUTER_AND_INNER_SHA256: PASSED")
    print("GPG_AUTHENTICATION: PASSED")
    print("OFFSITE_BACKUP_VERIFICATION: PASSED")
    print("No plaintext files extracted. Database import is a separate restore test.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=Path)
    parser.add_argument("key_file", type=Path)
    args = parser.parse_args()
    try:
        verify(args.archive, args.key_file)
    except (OSError, ValueError, tarfile.TarError, EOFError, subprocess.SubprocessError) as error:
        # Never print decrypted content or key material in diagnostic output.
        print("OFFSITE_BACKUP_VERIFICATION: FAILED")
        print("Reason:", type(error).__name__)
        raise SystemExit(1) from None
