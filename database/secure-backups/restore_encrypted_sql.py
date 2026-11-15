#!/usr/bin/env python3
"""Decrypt a private Water SQL backup without sending the recovery key to Git."""
import argparse
import base64
import gzip
import hashlib
import json
from pathlib import Path
from cryptography.hazmat.primitives.ciphers.aead import AESGCM


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('encrypted_backup', type=Path)
    parser.add_argument('recovery_key', type=Path, help='Base64 key file kept OUTSIDE Git')
    parser.add_argument('output_sql', type=Path, help='New local output file')
    args = parser.parse_args()
    if args.output_sql.exists():
        parser.error('Output already exists. Choose a new path; existing databases/files are never overwritten.')
    archive = json.loads(args.encrypted_backup.read_text(encoding='utf-8'))
    if archive.get('format') != 'water-encrypted-sql-backup-v1' or archive.get('cipher') != 'AES-256-GCM':
        parser.error('Unsupported backup format')
    key = base64.b64decode(args.recovery_key.read_text(encoding='ascii').strip(), validate=True)
    if len(key) != 32:
        parser.error('The recovery key must be exactly 256 bits')
    plaintext = gzip.decompress(AESGCM(key).decrypt(
        base64.b64decode(archive['nonce_b64']),
        base64.b64decode(archive['ciphertext_b64']),
        base64.b64decode(archive['aad_b64']),
    ))
    if len(plaintext) != archive['sql_size_bytes'] or hashlib.sha256(plaintext).hexdigest() != archive['sql_sha256']:
        parser.error('Backup integrity check failed; no file created')
    args.output_sql.write_bytes(plaintext)
    print('Verified SQL backup restored to:', args.output_sql)
    print('NOTE: Do NOT import into a running production DB. Verify application migrations first.')


if __name__ == '__main__':
    main()
