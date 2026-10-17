<?php
// Separate supervisor: its lock survives termination of the Mautic worker process.
if (PHP_SAPI!=='cli' || count($argv)!==5) { exit(64); }
$dir=realpath($argv[1]);$cwd=realpath($argv[2]);$command=json_decode(base64_decode($argv[3],true),true);$timeout=(int)$argv[4];
if (!$dir || !$cwd || !is_array($command) || !$command || $timeout<1 || $timeout>600) { exit(64); }
$lock=fopen($dir.'/execution.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { exit(75); }
$meta=$dir.'/active-process.json';
try {
    $pipes=[];$process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
    if (!is_resource($process)) { exit(70); }
    $status=proc_get_status($process);$pid=$status['pid'];
    file_put_contents($meta,json_encode(['pid'=>$pid,'supervisor'=>getmypid(),'started_at'=>time(),'deadline'=>time()+$timeout]));chmod($meta,0600);
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$start=microtime(true);$code=1;$stopped=false;
    do {
        foreach ([1=>STDOUT,2=>STDERR] as $i=>$out) { $chunk=stream_get_contents($pipes[$i]);if ($chunk!=='') { fwrite($out,$chunk); } }
        if (!$status['running']) { $code=$status['exitcode'];break; }
        if (microtime(true)-$start>$timeout && !$stopped) { proc_terminate($process);$stopped=true; }
        if (microtime(true)-$start>$timeout+2) { proc_terminate($process,9); }
        usleep(50000);$status=proc_get_status($process);
    } while (true);
    foreach ($pipes as $pipe) { fclose($pipe); }proc_close($process);
    if (is_file($meta)) { unlink($meta); }
    exit($stopped ? 124 : max(0,$code));
} finally { flock($lock,LOCK_UN);fclose($lock); }
