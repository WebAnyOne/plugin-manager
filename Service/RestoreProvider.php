<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
/** Fixed local operator-configured provider; never executable data from HTTP or the catalogue. */
final class RestoreProvider
{
    private $env;private $process;private $directory;
    public function __construct(Environment $env,Process $process,string $directory) { $this->env=$env;$this->process=$process;$this->directory=$directory; }
    public function call(string $action,string $job,?string $checkpoint=null): array
    {
        $path=$this->env->config['restore_provider'] ?? '';
        if (PHP_SAPI!=='cli' || !in_array($action,['probe','snapshot','verify','restore'],true) || !preg_match('/^[a-f0-9]{32}$/D',$job) || !$path || $path[0]!=='/' || !is_file($path) || !is_executable($path) || is_link($path) || (fileperms($path)&0022)) { throw new \RuntimeException('restore_provider_unavailable'); }
        $argv=[$path,$action,$this->env->project,$job];
        // A restore child must keep the exclusion lock if its Mautic parent dies.
        $raw=$action==='probe' ? $this->process->run($argv,$this->env->project,300) : $this->process->guarded($argv,$this->env->project,300,$this->directory);
        $r=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($r) || ($r['protocol'] ?? null)!==1 || ($r['ok'] ?? null)!==true || ($r['project'] ?? null)!==$this->env->project || ($r['code_and_database'] ?? null)!==true || ($r['restore_tested_at'] ?? 0)<time()-2592000 || ($r['restore_tested_at'] ?? 0)>time() || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/D',$r['checkpoint'] ?? '')) { throw new \RuntimeException('restore_not_verified'); }
        if ($checkpoint!==null && $r['checkpoint']!==$checkpoint) { throw new \RuntimeException('restore_checkpoint_mismatch'); }
        return array_intersect_key($r,array_flip(['protocol','ok','checkpoint','project','code_and_database','restore_tested_at']));
    }
}
