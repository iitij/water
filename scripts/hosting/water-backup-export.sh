#!/bin/bash
set -euo pipefail
export LC_ALL=C PATH=/usr/bin:/bin
umask 077
test "$(id -un)" = alweufti
root="$HOME/.water-backups"
ops="$HOME/.water-backup-ops"
test -d "$root" && test ! -L "$root" && test -O "$root"
cmd="${SSH_ORIGINAL_COMMAND:-}"
php=/opt/alt/php84/usr/bin/php
artisan="$HOME/repositories/water/artisan"
if "$php" "$artisan" water:backup-device allowed >/dev/null 2>&1; then
  :
else
  rc=$?
  if [[ "$rc" == 3 && "$cmd" == list ]]; then printf 'DISABLED\n'; exit 0; fi
  echo 'Backup device is disabled or policy is unavailable.' >&2
  exit 65
fi
if [[ "$cmd" == list ]]; then
  for dir in "$root"/water-*; do
    [[ -d "$dir" && ! -L "$dir" && -O "$dir" ]] || continue
    name="$(basename "$dir")"
    [[ "$name" =~ ^water-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6}$ ]] || continue
    file="$dir/$name.tar.gz.gpg"
    [[ -f "$file" && ! -L "$file" && -O "$file" && -f "$file.sha256" && ! -L "$file.sha256" && -O "$file.sha256" ]] || continue
    printf '%s\n' "$name"
  done
elif [[ "$cmd" =~ ^ack\ (water-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6})\ ([a-f0-9]{64})$ ]]; then
  name="${BASH_REMATCH[1]}"
  digest="${BASH_REMATCH[2]}"
  exec "$php" "$artisan" water:backup-device ack "$name" "$digest"
elif [[ "$cmd" == recovery-envelope ]]; then
  file="$ops/recovery-v1.msi.oaep"
  [[ -f "$file" && ! -L "$file" && -O "$file" && "$(stat -c %s "$file")" == 384 ]]
  exec cat -- "$file"
elif [[ "$cmd" =~ ^(archive|checksum)\ (water-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6})$ ]]; then
  kind="${BASH_REMATCH[1]}"
  name="${BASH_REMATCH[2]}"
  dir="$root/$name"
  file="$dir/$name.tar.gz.gpg"
  [[ -d "$dir" && ! -L "$dir" && -O "$dir" ]]
  if [[ "$kind" == checksum ]]; then file="$file.sha256"; fi
  [[ -f "$file" && ! -L "$file" && -O "$file" ]]
  exec cat -- "$file"
else
  echo 'Only encrypted backup export commands are permitted.' >&2
  exit 64
fi
