<?php
// TEST ONLY: tiny, quiescent fixture project and one disposable MySQL table.
// This is deliberately NOT a backup provider for a Mautic installation.
if (PHP_SAPI!=='cli' || count($argv)!==5) { exit(64); }
umask(0077);
try {
    $scratch=realpath($argv[1]);$action=$argv[2];$project=realpath($argv[3]);$job=$argv[4];
    if (!$scratch || !preg_match('~^/(?:private/)?tmp/wao-manager-recovery-[a-f0-9]{16}$~D',$scratch) || $project!==$scratch.'/project' || !in_array($action,['probe','snapshot','verify','restore'],true) || !preg_match('/^[a-f0-9]{32}$/D',$job)) { throw new RuntimeException('fixture_only'); }
    $config=json_decode(file_get_contents($scratch.'/config.json'),true,16,JSON_THROW_ON_ERROR);
    if (!preg_match('/^apimonitor_lab_pm_recovery_[a-f0-9]{16}$/D',$config['database'])) { throw new RuntimeException('fixture_database_only'); }
    $pdo=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$config['database'] || $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)!==['fixture_records']) { throw new RuntimeException('fixture_schema_only'); }
    $mode=is_file($scratch.'/mode') ? trim(file_get_contents($scratch.'/mode')) : '';
    if ($mode===$action.'-fail') { throw new RuntimeException('injected_failure'); }
    $point=$scratch.'/snapshots/'.$job;
    if ($action==='snapshot') {
        if (!mkdir($point.'/code',0700,true)) { throw new RuntimeException('snapshot_exists'); }
        fixtureCopy($project,$point.'/code');
        $database=['schema'=>$pdo->query('SHOW CREATE TABLE fixture_records')->fetch(PDO::FETCH_NUM)[1],'rows'=>$pdo->query('SELECT id,value FROM fixture_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
        file_put_contents($point.'/database.json',json_encode($database,JSON_THROW_ON_ERROR));
        $manifest=['code'=>fixtureFiles($point.'/code'),'database'=>hash_file('sha256',$point.'/database.json')];
        file_put_contents($point.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
    }
    if ($action!=='probe') {
        $manifest=json_decode(file_get_contents($point.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
        if ($manifest['code']!==fixtureFiles($point.'/code') || !hash_equals($manifest['database'],hash_file('sha256',$point.'/database.json'))) { throw new RuntimeException('snapshot_corrupt'); }
        if ($action==='restore') {
            if ($mode==='restore-slow') { file_put_contents($scratch.'/restore-started','1');sleep(6); }
            $database=json_decode(file_get_contents($point.'/database.json'),true,32,JSON_THROW_ON_ERROR);
            fixtureDelete($project);mkdir($project,0700);fixtureCopy($point.'/code',$project);
            $pdo->exec('DROP TABLE fixture_records');$pdo->exec($database['schema']);
            $insert=$pdo->prepare('INSERT INTO fixture_records (id,value) VALUES (?,?)');
            foreach ($database['rows'] as $row) { $insert->execute([$row['id'],$row['value']]); }
        }
    }
    $tested=is_file($scratch.'/tested-at') ? (int)file_get_contents($scratch.'/tested-at') : 0;
    $checkpoint=$action==='probe' ? 'fixture-probe' : hash_file('sha256',$point.'/manifest.json');
    if ($mode==='wrong-checkpoint') { $checkpoint='wrong'; }
    echo json_encode(['protocol'=>1,'ok'=>true,'project'=>$project,'code_and_database'=>true,'restore_tested_at'=>$tested,'checkpoint'=>$checkpoint],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,"fixture_provider_failed\n");exit(1); }

function fixtureFiles(string $path): array
{
    $files=[];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) { throw new RuntimeException('fixture_symlink'); }
        if ($file->isFile()) { $files[substr($file->getPathname(),strlen($path)+1)]=hash_file('sha256',$file->getPathname()); }
    }
    ksort($files);return $files;
}
function fixtureCopy(string $from,string $to): void
{
    foreach (new FilesystemIterator($from,FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isLink()) { throw new RuntimeException('fixture_symlink'); }
        $target=$to.'/'.$file->getFilename();
        if ($file->isDir()) { mkdir($target,0700);fixtureCopy($file->getPathname(),$target); }
        else { if (!copy($file->getPathname(),$target)) { throw new RuntimeException('fixture_copy'); }chmod($target,0600); }
    }
}
function fixtureDelete(string $path): void
{
    foreach (new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isLink()) { throw new RuntimeException('fixture_symlink'); }
        if ($file->isDir()) { fixtureDelete($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($path);
}
