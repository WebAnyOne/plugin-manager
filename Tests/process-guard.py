"""Exercise the independent supervisor without Composer or a database."""
from pathlib import Path
import base64
import json
import os
import shutil
import subprocess
import tempfile
import time

php = os.environ.get('PHP_BINARY', shutil.which('php'))
runner = Path(__file__).parents[1] / 'bin/run-guarded.php'
checks = 0
with tempfile.TemporaryDirectory(prefix='wao-manager-guard-') as directory:
    def cmd(script, timeout=3):
        arguments = base64.b64encode(json.dumps([php, '-r', script]).encode()).decode()
        return [php, str(runner), directory, directory, arguments, str(timeout)]

    assert subprocess.run(cmd('echo "ok";'), capture_output=True).stdout == b'ok'
    checks += 1
    assert subprocess.run(cmd('exit(23);'), capture_output=True).returncode == 23
    checks += 1
    assert subprocess.run(cmd('sleep(5);', 1), capture_output=True, timeout=5).returncode == 124
    checks += 1
    # A separate disposable parent emulates the Mautic worker; its child is the supervisor.
    arguments = cmd('usleep(1500000);echo "finished after parent death";fwrite(STDERR,"diagnostic after parent death");')
    parent = subprocess.Popen([php, '-r', '$p=proc_open(json_decode($argv[1],true),[0=>STDIN,1=>["pipe","w"],2=>["pipe","w"]],$pipes);sleep(10);', json.dumps(arguments)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    meta = Path(directory) / 'active-process.json'
    deadline = time.monotonic() + 3
    while not meta.exists() and time.monotonic() < deadline:
        time.sleep(.02)
    assert meta.exists()
    checks += 1
    parent.kill()
    parent.wait()
    assert subprocess.run(cmd('echo "must not run";'), capture_output=True).returncode == 75
    checks += 1
    deadline = time.monotonic() + 4
    while meta.exists() and time.monotonic() < deadline:
        time.sleep(.03)
    assert not meta.exists()
    checks += 1
    assert subprocess.run(cmd('echo "resumed";'), capture_output=True).stdout == b'resumed'
    checks += 1
print(json.dumps(dict(suite='process-supervisor', assertions=checks, status='passed')))
