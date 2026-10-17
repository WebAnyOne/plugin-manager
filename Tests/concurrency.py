"""Multiple OS processes exercise atomic state and idempotent queue insertion."""
from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile

php = os.environ.get('PHP_BINARY', shutil.which('php'))
root = Path(__file__).parents[1]
script = '''require $argv[1].'/Service/State.php';require $argv[1].'/Service/Queue.php';
$s=new MauticPlugin\\WebAnyOnePluginManagerBundle\\Service\\State($argv[2]);
if ($argv[3]==='count') { for($i=0;$i<40;++$i){$s->change(static function(&$v){$v['counter']=($v['counter']??0)+1;});} }
else {$q=new MauticPlugin\\WebAnyOnePluginManagerBundle\\Service\\Queue($s);$j=$q->enqueue('test','1.0.0','install','concurrent-request-0001',1,'api');echo $j['id'];}
'''
with tempfile.TemporaryDirectory(prefix='wao-manager-concurrency-') as directory:
    state = Path(directory) / 'initially-missing'
    for mode in ('count', 'queue'):
        processes = [subprocess.Popen([php, '-r', script, str(root), str(state), mode], stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(6)]
        outputs = []
        for process in processes:
            output, errors = process.communicate(timeout=15)
            assert process.returncode == 0, errors.decode()
            outputs.append(output)
        data = json.loads((state / 'state.json').read_text())
        if mode == 'count':
            assert data['counter'] == 240
        else:
            assert len(set(outputs)) == 1
            assert len(data['jobs']) == 1
print('{"suite":"persistent-concurrency","processes":6,"atomic_updates":240,"idempotent_jobs":1,"status":"passed"}')
