#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export PATH=/usr/local/bin:/usr/bin:/bin
export LC_ALL=C
ops="$HOME/.water-backup-ops"
root="$HOME/.water-backups"
[[ "$(id -un)" == alweufti && -d "$ops" && ! -L "$ops" && -O "$ops" ]] || exit 1
exec 8>"$ops/daily.lock"
flock -n 8 || exit 0
log="$(mktemp "$ops/.daily-log-XXXXXXXX")"
if timeout --kill-after=20s 1200s bash "$ops/water-backup.sh" >"$log" 2>&1; then
  printf 'DAILY_BACKUP_STATUS: SUCCESS %s\n' "$(date -u +%FT%TZ)" >>"$log"
else
  printf 'DAILY_BACKUP_STATUS: FAILED %s\n' "$(date -u +%FT%TZ)" >>"$log"
  mv -f "$log" "$ops/latest-daily.log"
  echo 'Water backup failed. Review the private latest-daily.log.' >&2
  exit 1
fi
# Pruning is opt-in only after the owner has verified an independent off-host copy.
if [[ -f "$ops/enable-retention-after-offsite-verification" && ! -L "$ops/enable-retention-after-offsite-verification" && -O "$ops/enable-retention-after-offsite-verification" ]]; then
  verified=()
  while IFS= read -r -d '' dir; do
    name="$(basename "$dir")"
    [[ "$name" =~ ^water-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6}$ ]] || continue
    [[ ! -L "$dir" && -O "$dir" && -f "$dir/$name.tar.gz.gpg" && -f "$dir/$name.tar.gz.gpg.sha256" ]] || continue
    [[ ! -L "$dir/$name.tar.gz.gpg" && ! -L "$dir/$name.tar.gz.gpg.sha256" ]] || continue
    (cd "$dir"; sha256sum -c --quiet "$name.tar.gz.gpg.sha256") || continue
    verified+=("$dir")
  done < <(find "$root" -mindepth 1 -maxdepth 1 -type d -name 'water-*' -print0 | sort -z)
  remaining="${#verified[@]}"
  for dir in "${verified[@]}"; do
    (( remaining > 7 )) || break
    [[ ! -e "$dir/.keep" ]] || continue
    [[ -n "$(find "$dir" -maxdepth 0 -mtime +29 -print)" ]] || continue
    name="$(basename "$dir")"
    rm -- "$dir/$name.tar.gz.gpg" "$dir/$name.tar.gz.gpg.sha256"
    rmdir -- "$dir"
    remaining=$((remaining-1))
    echo "RETENTION_REMOVED: $name" >>"$log"
  done
else
  echo 'RETENTION: disabled until independent recovery copy is verified' >>"$log"
fi
mv -f "$log" "$ops/latest-daily.log"
