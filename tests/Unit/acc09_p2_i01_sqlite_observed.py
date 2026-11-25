"""Auxiliary real SQLite NUMERIC affinity evidence; not PHP PDO acceptance."""
import json
import pathlib
import sqlite3
import subprocess


def decimal(minor):
    return ('-' if minor < 0 else '') + f'{abs(minor) // 100}.{abs(minor) % 100:02d}'


db = sqlite3.connect(':memory:')
rows = []
types = {}
for domain in (10, 12, 14):
    db.execute(f'CREATE TABLE probe (value DECIMAL({domain},2))')
    cap = 10 ** domain - 1
    vectors = [None, 0, 1, -1, 100, -100] + [sign * (cap - i) for sign in (1, -1) for i in range(251)]
    for minor in vectors:
        db.execute('INSERT INTO probe VALUES (?)', (None if minor is None else decimal(minor),))
        raw, kind = db.execute('SELECT value, typeof(value) FROM probe').fetchone()
        types.setdefault(domain, set()).add(kind)
        rows.append({'domain': domain, 'raw': raw, 'expected': minor})
        db.execute('DELETE FROM probe')
    db.execute('DROP TABLE probe')
script = pathlib.Path(__file__).with_name('acc09_p2_i01_check.php')
print('SQLite', sqlite3.sqlite_version, 'types', {k: sorted(v) for k, v in types.items()}, flush=True)
subprocess.run(['php', str(script), 'observed'], input=json.dumps(rows), text=True, check=True)
