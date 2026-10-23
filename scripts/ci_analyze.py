"""Run `flutter analyze` and expose each analyzer error as a check annotation.

GitHub truncates a single annotation to roughly 4 KB, so publishing the whole
log tail hides the actual errors when a project has many legacy lint notes.
Each `error •` line is emitted as its own annotation instead, which keeps the
failing diagnostics readable from the Checks API.
"""
import os
import shutil
import subprocess
import sys

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

command = sys.argv[1:] or ["flutter", "analyze", "--no-fatal-infos", "--no-fatal-warnings"]
command[0] = shutil.which(command[0]) or command[0]

process = subprocess.Popen(
    command,
    stdout=subprocess.PIPE,
    stderr=subprocess.STDOUT,
    text=True,
    encoding="utf-8",
    errors="replace",
)

errors = []
tail = []
for line in process.stdout:
    print(line, end="", flush=True)
    if " error • " in line:
        errors.append(line.rstrip())
    tail.append(line)
    if len(tail) > 60:
        tail.pop(0)

code = process.wait()

if code and os.environ.get("GITHUB_ACTIONS"):
    if errors:
        for line in errors[:40]:
            message = line.replace("%", "%25").replace("\r", "").strip()
            print(f"::error title=Flutter analyze::{message}", flush=True)
    else:
        detail = "".join(tail)[-3800:]
        detail = detail.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
        print(f"::error title=Flutter analyze failed::{detail}", flush=True)

sys.exit(code)
