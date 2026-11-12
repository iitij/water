#!/usr/bin/env bash
# Water: encrypted pre-single-meter archive of tracked code + live database + local uploads.
# Nothing in this tool commits an unencrypted SQL dump or a secret.
set -Eeuo pipefail
umask 077

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "Missing command: $1"; }
for c in git php zip unzip gpg sha256sum mktemp tar cmp; do need "$c"; done
ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || fail "Run from a local Git checkout"
cd "$ROOT"
[[ "$(git branch --show-current)" == "main" ]] || fail "Switch to main before making this backup"
[[ -f "artisan" && -f "vendor/autoload.php" && -f "bootstrap/app.php" ]] ||
  fail "Run from the Laravel checkout with Composer dependencies installed"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] ||
  fail "Commit or discard tracked changes before creating a snapshot"
SHA="$(git rev-parse HEAD)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="water-v2026.11.12-pre-single-meter-${SHA:0:12}-${STAMP}"
TARGET="$ROOT/backups/releases"
mkdir -p "$TARGET"
OUT="$TARGET/$NAME.zip.gpg"
[[ ! -e "$OUT" ]] || fail "Archive already exists"
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
mkdir -p "$TMP/stage/backup"
git archive --format=tar "$SHA" | tar -xf - -C "$TMP/stage"
printf '%s\n' "$SHA" > "$TMP/stage/backup/source-commit.txt"

# Laravel resolves DB_URL/.env/config on the actual application machine.
# The temporary connection option file (mode 600) is never part of the ZIP.
php -r '
require $argv[1]."/vendor/autoload.php";
$app = require $argv[1]."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = Illuminate\Support\Facades\DB::connection()->getConfig();
$d = (string)($c["driver"] ?? "");
if (!in_array($d, ["mysql", "mariadb", "sqlite"], true)) {
  fwrite(STDERR, "Unsupported DB driver: ".$d."\n"); exit(2);
}
file_put_contents($argv[2]."/driver", $d);
if ($d === "sqlite") {
  $db = (string)($c["database"] ?? "");
  if ($db === "" || $db === ":memory:" || !is_file($db)) {
    fwrite(STDERR, "SQLite file not found\n"); exit(2);
  }
  file_put_contents($argv[2]."/sqlite-path", $db);
} else {
  foreach (["username","password","database","host","unix_socket"] as $k) {
    if (strpbrk((string)($c[$k] ?? ""), "\r\n") !== false) {
      fwrite(STDERR, "Unexpected newline in DB configuration\n"); exit(2);
    }
  }
  $quote = fn($value) => "\"".addcslashes((string)$value, "\\\"")."\"";
  $conf = "[client]\nuser=".$quote($c["username"] ?? "")."\npassword=".$quote($c["password"] ?? "")."\n";
  if (!empty($c["unix_socket"])) {
    $conf .= "socket=".$quote($c["unix_socket"])."\n";
  } else {
    $conf .= "host=".$quote($c["host"] ?? "127.0.0.1")."\nport=".(int)($c["port"] ?? 3306)."\n";
  }
  file_put_contents($argv[2]."/mysql-client.cnf", $conf);
  file_put_contents($argv[2]."/database-name", (string)($c["database"] ?? ""));
}
' "$ROOT" "$TMP"
DRIVER="$(cat "$TMP/driver")"
if [[ "$DRIVER" == "sqlite" ]]; then
  need sqlite3
  sqlite3 "$(cat "$TMP/sqlite-path")" ".backup '$TMP/stage/backup/database.sqlite'"
  [[ -s "$TMP/stage/backup/database.sqlite" ]] || fail "SQLite backup is empty"
  [[ "$(sqlite3 "$TMP/stage/backup/database.sqlite" 'PRAGMA integrity_check;')" == "ok" ]] ||
    fail "SQLite integrity check failed"
else
  if command -v mysqldump >/dev/null 2>&1; then
    DUMP="$(command -v mysqldump)"
  elif command -v mariadb-dump >/dev/null 2>&1; then
    DUMP="$(command -v mariadb-dump)"
  else
    fail "Install mysqldump or mariadb-dump on the application host"
  fi
  DB_NAME="$(cat "$TMP/database-name")"
  [[ -n "$DB_NAME" ]] || fail "Database name is empty"
  "$DUMP" --defaults-extra-file="$TMP/mysql-client.cnf" \
    --single-transaction --quick --routines --triggers --events \
    --hex-blob --no-tablespaces "$DB_NAME" > "$TMP/stage/backup/database.sql"
  [[ -s "$TMP/stage/backup/database.sql" ]] || fail "SQL dump is empty"
fi

# Preserve user uploads on this host; files on other hosts need separate capture.
if [[ -d "$ROOT/storage/app" ]]; then
  mkdir -p "$TMP/stage/storage"
  cp -a "$ROOT/storage/app" "$TMP/stage/storage/"
fi

cat > "$TMP/stage/backup/RESTORE_NOTES.txt" <<EOF
Water pre-single-meter backup
Source Git commit: $SHA
UTC creation time: $STAMP
Database driver: $DRIVER
Includes tracked project files at the source commit, live DB snapshot from the
machine that ran this tool, and locally available storage/app uploads.
Does NOT include .env, Git history, untracked secrets, or files on other hosts.
Restore into an isolated test environment before relying on this backup.
EOF

( cd "$TMP/stage" && zip -qr "$TMP/$NAME.zip" . )
unzip -tq "$TMP/$NAME.zip" >/dev/null || fail "ZIP validation failed"
# GPG AES256 prompts for a passphrase; store it independently of GitHub.
gpg --symmetric --cipher-algo AES256 --output "$OUT" "$TMP/$NAME.zip" ||
  fail "Encryption failed; run in an interactive terminal with GPG configured"
[[ -s "$OUT" ]] || fail "Encrypted archive missing"
gpg --decrypt --output "$TMP/verified.zip" "$OUT" ||
  fail "Decryption verification failed"
cmp -s "$TMP/$NAME.zip" "$TMP/verified.zip" || fail "Archive decrypt comparison failed"
unzip -tq "$TMP/verified.zip" >/dev/null || fail "Decrypted ZIP validation failed"
( cd "$TARGET" && sha256sum "$NAME.zip.gpg" > "$NAME.zip.gpg.sha256" )
printf 'Encrypted backup verified: %s\nSource commit: %s\n' "$OUT" "$SHA"

# GitHub rejects individual files >100 MiB. Prefer external encrypted storage if larger.
SIZE="$(wc -c < "$OUT")"
if (( SIZE >= 95 * 1024 * 1024 )); then
  printf 'Archive too large for GitHub (%s bytes). It remains on disk only.\n' "$SIZE" >&2
  exit 3
fi
if [[ "${PUBLISH_TO_GITHUB:-0}" == "1" ]]; then
  git add -- "$OUT" "$OUT.sha256"
  git commit -m "backup: encrypted pre-single-meter ${SHA:0:12}"
  git push origin main
  printf 'Encrypted archive and checksum uploaded to GitHub main.\n'
else
  printf 'Verified locally only. To generate-and-publish, run with PUBLISH_TO_GITHUB=1.\n'
fi
