"""Stream a CI command and expose failure details through check annotations.

Useful when the GitHub log-storage host is unavailable to a restricted client.
Keeps the original exit status; never turns a failing build into a success.
"""
import collections
import os
import shutil
import subprocess
import sys

# Windows runners otherwise use a legacy console encoding, which cannot print
# Flutter's Unicode progress marks or Arabic compiler/test diagnostics.
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

command = sys.argv[1:]
command[0] = shutil.which(command[0]) or command[0]
process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                           text=True, encoding="utf-8", errors="replace")
tail = collections.deque(maxlen=140)
for line in process.stdout:
    print(line, end="", flush=True)
    tail.append(line)
code = process.wait()
if code and os.environ.get("GITHUB_ACTIONS"):
    detail = "".join(tail)[-45000:]
    detail = detail.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
    print(f"::error title=Command failed::{detail}", flush=True)
sys.exit(code)
