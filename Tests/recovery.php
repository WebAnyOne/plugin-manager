<?php
// Native Manager recovery with a tiny fixture project and a separate disposable MySQL schema.
require __DIR__.'/bootstrap.php';
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Environment;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Process;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Queue;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\RestoreProvider;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\State;
umask(0077);
function attachRecoveryFixture(string $scratch)
{
    global $c;
    if (!preg_match('~^/(?:private/)?tmp/wao-manager-recovery-[a-f0-9]{16}$~D',$scratch) || !is_file($scratch.'/config.json')) { throw new RuntimeException('fixture_only'); }
    $manager=$c->get('wao.manager');
    $manager->env=new Environment($scratch.'/project/docroot',['restore_provider'=>$scratch.'/provider']);
    $manager->state=new State($scratch.'/state');$manager->queue=new Queue($manager->state);return $manager;
}
if (($argv[2] ?? '')==='worker') { attachRecoveryFixture($argv[3])->recover($argv[4]);exit; }
function rejection(callable $fn,$expected): void
{
    $expected=(array)$expected;$label=implode(' or ',$expected);
    try { $fn(); } catch (Throwable $e) { check(in_array($e->getMessage(),$expected,true),'Expected rejection '.$label.'; observed '.get_class($e));return; }
    check(false,'Expected rejection '.$label);
}
function fixtureRows(PDO $pdo): array { return $pdo->query('SELECT id,value FROM fixture_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
function changedFixture(string $project,PDO $pdo): void
{
    file_put_contents($project.'/docroot/plugins/FixtureBundle/version.txt','broken');file_put_contents($project.'/vendor/added.txt','unwanted');
    $pdo->exec("UPDATE fixture_records SET value='broken'");$pdo->exec('ALTER TABLE fixture_records ADD migrated INT DEFAULT 1');
}
function waitFor(callable $condition,int $seconds=10): bool
{
    $deadline=microtime(true)+$seconds;do { clearstatcache();if ($condition()) { return true; }usleep(50000); } while (microtime(true)<$deadline);return false;
}
$scratch=realpath(sys_get_temp_dir()).'/wao-manager-recovery-'.bin2hex(random_bytes(8));$database='apimonitor_lab_pm_recovery_'.bin2hex(random_bytes(8));$created=false;$child=null;
try {
    mkdir($scratch,0700);$project=$scratch.'/project';
    mkdir($project.'/docroot/plugins/FixtureBundle',0700,true);mkdir($project.'/vendor',0700);
    file_put_contents($project.'/composer.json',json_encode(['require'=>['mautic/core-lib'=>MAUTIC_VERSION]],JSON_THROW_ON_ERROR));
    file_put_contents($project.'/composer.lock','{"packages":[]}');file_put_contents($project.'/vendor/baseline.txt','original dependency');
    file_put_contents($project.'/docroot/plugins/FixtureBundle/version.txt','1.0.0');
    $db->executeStatement('CREATE DATABASE `'.$database.'`');$created=true;
    $params=$db->getParams();$dsn='mysql:'.(!empty($params['unix_socket']) ? 'unix_socket='.$params['unix_socket'] : 'host='.($params['host'] ?? 'localhost').';port='.($params['port'] ?? 3306)).';dbname='.$database.';charset=utf8mb4';
    $config=['database'=>$database,'dsn'=>$dsn,'user'=>$params['user'],'password'=>$params['password'] ?? ''];
    file_put_contents($scratch.'/config.json',json_encode($config,JSON_THROW_ON_ERROR));chmod($scratch.'/config.json',0600);
    $pdo=new PDO($dsn,$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE fixture_records (id INT PRIMARY KEY, value VARCHAR(128) NOT NULL)');$pdo->exec("INSERT INTO fixture_records VALUES (1,'original'),(2,'second')");$baseline=fixtureRows($pdo);
    file_put_contents($scratch.'/provider',"#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/restore-provider.php').' '.escapeshellarg($scratch).' "$@"'."\n");chmod($scratch.'/provider',0700);
    $m=attachRecoveryFixture($scratch);$process=new Process();$provider=new RestoreProvider($m->env,$process,$m->state->dir());
    check($m->env->project===$project,'Fixture project detected');
    // No fabricated restore date: first prove a code+schema+data roundtrip independently.
    rejection(static function() use ($provider) { $provider->call('probe',str_repeat('0',32)); },'restore_not_verified');
    $selftest=bin2hex(random_bytes(16));$process->run([$scratch.'/provider','snapshot',$project,$selftest],$project);
    changedFixture($project,$pdo);$process->run([$scratch.'/provider','restore',$project,$selftest],$project);
    check(file_get_contents($project.'/docroot/plugins/FixtureBundle/version.txt')==='1.0.0' && !is_file($project.'/vendor/added.txt'),'Independent code roundtrip');
    check(fixtureRows($pdo)===$baseline && count($pdo->query('SHOW COLUMNS FROM fixture_records')->fetchAll())===2,'Independent schema and rows roundtrip');
    file_put_contents($scratch.'/tested-at',(string)time());check($provider->call('probe',str_repeat('0',32))['ok'],'Recent actual restore test accepted');
    file_put_contents($scratch.'/tested-at',(string)(time()-2592001));rejection(static function() use ($provider) { $provider->call('probe',str_repeat('0',32)); },'restore_not_verified');file_put_contents($scratch.'/tested-at',(string)time());
    $job=$m->queue->enqueue('fixture','1.0.1','update','fixture-recovery-0001',1,'ui');$m->queue->claim();
    $point=$provider->call('snapshot',$job['id']);mkdir($m->state->dir().'/operations/'.$job['id'],0700,true);file_put_contents($m->state->dir().'/operations/'.$job['id'].'/checkpoint.json',json_encode($point,JSON_THROW_ON_ERROR));
    changedFixture($project,$pdo);$m->queue->phase($job['id'],'native_reload',true);$m->queue->finish($job['id'],'injected_migration_failure');
    $pending=$m->queue->enqueue('other-fixture','1.0.0','install','fixture-recovery-0002',1,'api');
    check($m->state->read()['jobs'][$job['id']]['status']==='recovery_required' && $m->queue->claim()===null,'Mutation failure blocks pending job');
    file_put_contents($scratch.'/mode','restore-fail');rejection(static function() use ($m,$job) { $m->recover($job['id']); },'process_failed');
    check($m->state->read()['jobs'][$job['id']]['status']==='recovery_required' && fixtureRows($pdo)!==$baseline,'Failed restore cannot clear incident');
    file_put_contents($scratch.'/mode','verify-fail');rejection(static function() use ($m,$job) { $m->recover($job['id']); },'process_failed');
    check($m->state->read()['jobs'][$job['id']]['status']==='recovery_required','Failed post-restore verification cannot clear incident');
    file_put_contents($scratch.'/mode','wrong-checkpoint');rejection(static function() use ($m,$job) { $m->recover($job['id']); },'restore_checkpoint_mismatch');
    check($m->queue->claim()===null,'Checkpoint mismatch keeps queue blocked');unlink($scratch.'/mode');
    $backup=$scratch.'/snapshots/'.$job['id'].'/database.json';$original=file_get_contents($backup);file_put_contents($backup,'{}');
    rejection(static function() use ($m,$job) { $m->recover($job['id']); },'process_failed');check($m->queue->claim()===null,'Corrupt snapshot cannot clear incident');file_put_contents($backup,$original);
    changedFixture($project,$pdo);file_put_contents($scratch.'/mode','restore-slow');
    $child=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0','-d','sys_temp_dir=/tmp','-d','memory_limit=1G',__FILE__,$lab,'worker',$scratch,$job['id']],[0=>['file','/dev/null','r'],1=>['file',$scratch.'/worker.log','a'],2=>['file',$scratch.'/worker.log','a']],$pipes);
    check(is_resource($child) && waitFor(static function() use ($scratch) { return is_file($scratch.'/restore-started'); },30),'Restore child started under native Manager');
    $probe=fopen($m->state->dir().'/execution.lock','c');check(!flock($probe,LOCK_EX|LOCK_NB),'Restore owns exclusion lock before parent termination');fclose($probe);
    $pid=proc_get_status($child)['pid'];check(posix_kill($pid,9),'Terminate native recovery parent');
    check(waitFor(static function() use ($child) { return !proc_get_status($child)['running']; },2),'Recovery parent has stopped');
    clearstatcache();check(is_file($m->state->dir().'/active-process.json'),'Provider supervisor remains active after parent termination');
    $probe=fopen($m->state->dir().'/execution.lock','c');check(!flock($probe,LOCK_EX|LOCK_NB),'Restore owns exclusion lock after parent termination');fclose($probe);
    // Some PHP/OS combinations also inherit the composer's open lock descriptor.
    rejection(static function() use ($m,$job) { $m->recover($job['id']); },['worker_busy','child_process_still_running']);
    check($m->queue->claim()===null,'Dead parent cannot allow a concurrent mutation');
    check(waitFor(static function() use ($m) { return !is_file($m->state->dir().'/active-process.json'); }),'Supervisor finishes after parent death');
    proc_close($child);$child=null;
    check($m->state->read()['jobs'][$job['id']]['status']==='recovery_required','Interrupted recovery requires explicit verification retry');
    unlink($scratch.'/mode');$m->recover($job['id']);
    check($m->state->read()['jobs'][$job['id']]['status']==='restored','Recovery succeeds only after restore and verification');
    check(file_get_contents($project.'/docroot/plugins/FixtureBundle/version.txt')==='1.0.0' && !is_file($project.'/vendor/added.txt') && file_get_contents($project.'/vendor/baseline.txt')==='original dependency','All fixture code restored, added file removed');
    check(fixtureRows($pdo)===$baseline && count($pdo->query('SHOW COLUMNS FROM fixture_records')->fetchAll())===2,'Database schema and rows restored');
    check($m->queue->claim()['id']===$pending['id'],'Verified recovery unblocks pending queue');$m->queue->finish($pending['id'],'fixture_test_finished');
    rejection(static function() use ($m,$job) { $m->recover($job['id']); },'recovery_not_required');
    $audits=$m->state->read()['audit'];check(count(array_filter($audits,static function($a) { return $a['event']==='job_restored'; }))===1,'Exactly one verified recovery audit');
    $result=['suite'=>'native-recovery-fixture','mautic'=>MAUTIC_VERSION,'php'=>PHP_VERSION,'assertions'=>$assertions,'database'=>'isolated MySQL schema; no Mautic records changed','scope'=>'Tiny quiescent fixture code, schema and rows; native Manager recovery; parent interruption; not a full Mautic deployment or Plesk qualification','status'=>'passed'];
} finally {
    if (is_resource($child)) { proc_terminate($child,9);proc_close($child); }
    if (isset($m)) { waitFor(static function() use ($m) { return !is_file($m->state->dir().'/active-process.json'); },10); }
    if ($created) { $pdo=null;$db->executeStatement('DROP DATABASE `'.$database.'`'); }
    if (is_dir($scratch)) { $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }else { unlink($file->getPathname()); } }rmdir($scratch); }
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
