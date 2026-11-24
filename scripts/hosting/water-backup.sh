#!/usr/bin/env bash
# Private, encrypted backup of the deployed Water application. Never writes to Git or public_html.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/local/bin:/usr/bin:/bin
unset MYSQL_PWD
php=/opt/alt/php84/usr/bin/php
project="$HOME/repositories/water"
root="$HOME/.water-backups"
keys="$HOME/.water-backup-keys"
ops="$HOME/.water-backup-ops"
die(){ echo "BACKUP_FAILED: $1" >&2; exit 1; }
[[ "$(id -un)" == alweufti ]] || die account
for tool in mariadb-dump mariadb gpg openssl tar gzip sha256sum flock mktemp git cmp timeout; do command -v "$tool" >/dev/null || die "missing $tool"; done
for dir in "$root" "$keys" "$ops"; do
  [[ ! -L "$dir" ]] || die symlink
  mkdir -m 700 -p "$dir"
  [[ -d "$dir" && -O "$dir" ]] || die ownership
  chmod 700 "$dir"
done
exec 9>"$ops/backup.lock"
flock -n 9 || die 'another backup is in progress'
cd "$project"
test -z "$(git status --porcelain)" || die dirty-source
commit="$(git rev-parse HEAD)"
test -f .env && test ! -L .env && test -r .env || die configuration
test -d storage/app && test ! -L storage/app || die storage
test -d "$HOME/public_html" && test ! -L "$HOME/public_html" || die document-root
tmp="$(mktemp -d "$root/.work-XXXXXXXX")"
outdir=''
cleanup(){
  rc=$?
  rm -rf -- "$tmp"
  if [[ "$rc" != 0 && -n "$outdir" ]]; then rm -rf -- "$outdir"; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -m 700 "$tmp/payload" "$tmp/gnupg"
export GNUPGHOME="$tmp/gnupg"
key="$keys/recovery-v1.key"
if [[ ! -e "$key" ]]; then
  openssl rand -hex 32 > "$tmp/new-key"
  chmod 600 "$tmp/new-key"
  mv -n "$tmp/new-key" "$key"
fi
[[ -f "$key" && ! -L "$key" && -O "$key" ]] || die key
chmod 600 "$key"
grep -Eq '^[a-f0-9]{64}$' "$key" || die key-format
# Use resolved Laravel connection settings; never echo credentials.
"$php" -d display_errors=0 -r '
try {
  require "vendor/autoload.php";
  $app=require "bootstrap/app.php";
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $c=\Illuminate\Support\Facades\DB::connection();
  $config=$c->getConfig();
  if (!in_array($config["driver"],["mysql","mariadb"],true) || $c->getDatabaseName()!=="alweufti_water") throw new RuntimeException("unexpected database");
  if (!in_array($config["host"],["localhost","127.0.0.1"],true)) throw new RuntimeException("nonlocal DB");
  $pdo=$c->getPdo();
  $all=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn();
  $inno=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\"BASE TABLE\" AND ENGINE=\"InnoDB\"")->fetchColumn();
  if ($all<1 || $all!==$inno) throw new RuntimeException("unsupported non-InnoDB objects");
  foreach (["TRIGGERS"=>"TRIGGER_SCHEMA","ROUTINES"=>"ROUTINE_SCHEMA","EVENTS"=>"EVENT_SCHEMA"] as $t=>$column) {
    if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.".$t." WHERE ".$column."=DATABASE()")->fetchColumn()!==0) throw new RuntimeException("new schema objects require backup review");
  }
  $quote=function($v){$v=(string)$v;if(strpbrk($v,"\r\n\0")!==false)throw new RuntimeException("invalid option");return "\"".addcslashes($v,"\\\"")."\"";};
  $text="[client]\nuser=".$quote($config["username"])."\npassword=".$quote($config["password"])."\n";
  if (filled($config["unix_socket"]??null)) $text.="socket=".$quote($config["unix_socket"])."\n";
  else $text.="host=".$quote($config["host"])."\nport=".(int)$config["port"]."\n";
  file_put_contents($argv[1]."/client.cnf",$text,LOCK_EX);
  file_put_contents($argv[1]."/table-count",(string)$all);
  echo "BACKUP_SCHEMA: ".$all." InnoDB tables".PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,"Database backup preflight failed\n");exit(1);}
' "$tmp"
chmod 600 "$tmp/client.cnf"
dump_opts=(--single-transaction --quick --skip-lock-tables --hex-blob --no-tablespaces --skip-comments --skip-dump-date --order-by-primary --default-character-set=utf8mb4)
timeout --kill-after=10s 600s mariadb-dump --defaults-file="$tmp/client.cnf" "${dump_opts[@]}" alweufti_water > "$tmp/payload/database.sql" 2>"$tmp/dump.err" || die database-dump
test -s "$tmp/payload/database.sql" || die empty-dump
# Preserve exactly the deployed source, not historical SQL/archives or development diagnostics.
git archive --format=tar "$commit" app artisan bootstrap config database/migrations database/factories database/seeders lang public resources routes composer.json composer.lock package.json package-lock.json vite.config.js .env.example > "$tmp/payload/source.tar"
cp -p .env "$tmp/payload/application.env"
tar -cf "$tmp/payload/uploads.tar" -C "$project/storage" app
tar -cf "$tmp/payload/public-web.tar" -C "$HOME" public_html
printf 'format=water-backup-v1\nsource_commit=%s\ncreated_utc=%s\ndatabase=alweufti_water\ntables=%s\n' "$commit" "$(date -u +%FT%TZ)" "$(cat "$tmp/table-count")" > "$tmp/payload/manifest.txt"
for t in source uploads public-web; do tar -tf "$tmp/payload/$t.tar" >/dev/null; done
(cd "$tmp/payload"; sha256sum database.sql source.tar application.env uploads.tar public-web.tar manifest.txt > SHA256SUMS)
tar -czf "$tmp/archive.tar.gz" -C "$tmp/payload" .
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
name="water-$stamp-$(openssl rand -hex 3)"
gpg --batch --no-tty --pinentry-mode loopback --passphrase-file "$key" --cipher-algo AES256 --compress-algo none --symmetric --output "$tmp/$name.tar.gz.gpg" "$tmp/archive.tar.gz" 2>"$tmp/gpg.err" || die encryption
gpg --batch --no-tty --pinentry-mode loopback --passphrase-file "$key" --decrypt "$tmp/$name.tar.gz.gpg" 2>"$tmp/decrypt.err" | cmp -s "$tmp/archive.tar.gz" - || die decrypt-compare
# Optional first-run restore drill: unique database and user; production database untouched.
if [[ "${1:-}" == --restore-drill ]]; then
  mkdir -m 700 "$tmp/restore"
  gpg --batch --no-tty --pinentry-mode loopback --passphrase-file "$key" --decrypt "$tmp/$name.tar.gz.gpg" 2>"$tmp/decrypt.err" | tar -xzf - -C "$tmp/restore"
  (cd "$tmp/restore"; sha256sum -c --quiet SHA256SUMS)
  for t in source uploads public-web; do tar -tf "$tmp/restore/$t.tar" >/dev/null; done
  [[ "$(cat "$tmp/restore/manifest.txt" | grep '^database=')" == database=alweufti_water ]] || die manifest
  db="alweufti_wbr$(openssl rand -hex 4)"
  user="alweufti_wbu$(openssl rand -hex 4)"
  pass="Aa9!$(openssl rand -hex 24)"
  created_db=0; created_user=0
  api(){
    uapi --output=json Mysql "$@" 2>"$tmp/uapi.err" | "$php" -r '
      $j=json_decode(stream_get_contents(STDIN),true);
      exit((int)($j["result"]["status"]??0)===1?0:1);
    '
  }
  drill_cleanup(){
    rc=$?
    if [[ "$created_db" == 1 ]]; then
      api delete_database "name=$db" || { echo "RESTORE_DRILL_CLEANUP_FAILED: database $db"; rc=1; }
    fi
    if [[ "$created_user" == 1 ]]; then
      api delete_user "name=$user" || { echo "RESTORE_DRILL_CLEANUP_FAILED: user $user"; rc=1; }
    fi
    if [[ "$rc" != 0 ]]; then
      rm -rf -- "$tmp"
      exit "$rc"
    fi
  }
  # EXIT cleanup is active even if import or exact comparison fails.
  trap 'drill_cleanup; cleanup' EXIT
  api create_database "name=$db" || die restore-database-create
  created_db=1
  api create_user "name=$user" "password=$pass" || die restore-user-create
  created_user=1
  api set_privileges_on_database "user=$user" "database=$db" 'privileges=ALL PRIVILEGES' || die restore-grant
  printf '[client]\nuser="%s"\npassword="%s"\nhost=localhost\n' "$user" "$pass" > "$tmp/restore.cnf"
  unset pass
  [[ "$db" =~ ^alweufti_wbr[0-9a-f]{8}$ && "$db" != alweufti_water ]] || die restore-target
  [[ "$(mariadb --defaults-file="$tmp/restore.cnf" --batch --skip-column-names "$db" -e 'SELECT DATABASE()')" == "$db" ]] || die restore-target
  [[ "$(mariadb --defaults-file="$tmp/restore.cnf" --batch --skip-column-names "$db" -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')" == 0 ]] || die restore-not-empty
  timeout --kill-after=10s 600s mariadb --defaults-file="$tmp/restore.cnf" "$db" < "$tmp/restore/database.sql" >"$tmp/restore.out" 2>"$tmp/restore.err" || die restore-import
  timeout --kill-after=10s 600s mariadb-dump --defaults-file="$tmp/restore.cnf" "${dump_opts[@]}" "$db" > "$tmp/restored.sql" 2>"$tmp/restore-dump.err" || die restore-export
  cmp -s "$tmp/restore/database.sql" "$tmp/restored.sql" || die restore-content-mismatch
  tables="$(mariadb --defaults-file="$tmp/restore.cnf" --batch --skip-column-names "$db" -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')"
  [[ "$tables" == "$(cat "$tmp/table-count")" ]] || die restore-table-count
  api delete_database "name=$db" || die restore-cleanup-database
  created_db=0
  api delete_user "name=$user" || die restore-cleanup-user
  created_user=0
  trap cleanup EXIT
  echo "RESTORE_DRILL_TABLES: $tables"
  echo 'RESTORE_SQL_EXACT_COMPARISON: PASSED'
  echo 'RESTORE_DRILL_CLEANUP: PASSED'
fi
outdir="$root/$name"
mkdir -m 700 "$outdir"
mv "$tmp/$name.tar.gz.gpg" "$outdir/"
(cd "$outdir"; sha256sum "$name.tar.gz.gpg" > "$name.tar.gz.gpg.sha256"; sha256sum -c --quiet "$name.tar.gz.gpg.sha256")
chmod 600 "$outdir/"*
printf 'BACKUP_SOURCE: %s\nBACKUP_DIRECTORY: %s\nBACKUP_ENCRYPTED_BYTES: %s\n' "$commit" "$outdir" "$(stat -c %s "$outdir/$name.tar.gz.gpg")"
echo 'BACKUP_DECRYPTION_AND_CHECKSUM: PASSED'
echo 'BACKUP_COMPLETE: PASSED'
