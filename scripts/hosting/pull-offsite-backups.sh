#!/usr/bin/env bash
# Pull encrypted backups to the owner's Ubuntu device. Never delete local backups.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/local/bin:/usr/bin:/bin
root="$HOME/Water-Backups"
recovery="$HOME/.water-recovery"
identity="$HOME/.ssh/water_backup_readonly_ed25519"
key="$recovery/recovery-v1.key"
verifier="$recovery/verify-offsite-backup.py"
for dir in "$root" "$recovery"; do
  [[ -d "$dir" && ! -L "$dir" && -O "$dir" ]] || exit 1
  chmod 700 "$dir"
done
for file in "$identity" "$key" "$verifier"; do
  [[ -f "$file" && ! -L "$file" && -O "$file" ]] || exit 1
done
chmod 600 "$identity" "$key" "$verifier"
exec 9>"$recovery/pull.lock"
flock -n 9 || exit 0
work="$(mktemp -d "$root/.pull-XXXXXXXX")"
trap 'rm -rf -- "$work"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
reader() {
  ssh -nT -o BatchMode=yes -o ConnectTimeout=20 \
    -o ServerAliveInterval=20 -o ServerAliveCountMax=3 \
    -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
    -i "$identity" -p 21098 alweufti@66.29.146.205 "$1"
}
reader list > "$work/list"
test -s "$work/list"
if [[ "$(cat "$work/list")" == DISABLED ]]; then
  echo "OFFSITE_SYNC: DISABLED_BY_WEBSITE"
  exit 0
fi
new=0
existing=0
latest=""
while IFS= read -r name; do
  [[ "$name" =~ ^water-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6}$ ]] || exit 1
  archive="$name.tar.gz.gpg"
  latest="$name"
  if [[ -e "$root/$name" || -L "$root/$name" ]]; then
    [[ -d "$root/$name" && ! -L "$root/$name" && -O "$root/$name" ]]
    python3 "$verifier" "$root/$name/$archive" "$key" >/dev/null
    existing=$((existing+1))
    continue
  fi
  mkdir -m 700 "$work/$name"
  reader "checksum $name" > "$work/$name/$archive.sha256"
  reader "archive $name" > "$work/$name/$archive"
  python3 "$verifier" "$work/$name/$archive" "$key"
  mv -T -- "$work/$name" "$root/$name"
  new=$((new+1))
  printf 'LOCAL_BACKUP_VERIFIED: %s\n' "$name"
done < "$work/list"
if [[ -n "$latest" ]]; then
  archive="$latest.tar.gz.gpg"
  digest="$(sha256sum "$root/$latest/$archive" | awk '{print $1}')"
  [[ "$digest" =~ ^[a-f0-9]{64}$ ]]
  reader "ack $latest $digest"
fi
printf 'OFFSITE_SYNC: PASSED new=%s existing=%s UTC=%s\n' "$new" "$existing" "$(date -u +%FT%TZ)"
