<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Queue
{
    private $state;
    public function __construct(State $state) { $this->state=$state; }
    public function existing(array $input,int $user,string $origin): ?array
    {
        foreach ($this->state->read()['jobs'] as $job) {
            if ($job['key']!==$input['idempotency_key'] || $job['user']!==$user || $job['origin']!==$origin) { continue; }
            foreach (['plugin','version','operation'] as $key) {
                if ($job[$key]!==$input[$key]) { throw new \RuntimeException('idempotency_conflict'); }
            }
            return $job;
        }
        return null;
    }
    public function enqueue(string $plugin,string $version,string $operation,string $key,int $user,string $origin): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$plugin) || !preg_match('/^\d+\.\d+\.\d+$/D',$version) || !in_array($operation,['install','update'],true) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key) || !in_array($origin,['ui','api','automation'],true)) { throw new \InvalidArgumentException('invalid_operation'); }
        return $this->state->change(static function (&$s) use ($plugin,$version,$operation,$key,$user,$origin) {
            foreach ($s['jobs'] as $j) {
                if ($j['key']===$key && $j['user']===$user && $j['origin']===$origin) { if ($j['plugin']!==$plugin || $j['version']!==$version || $j['operation']!==$operation) { throw new \RuntimeException('idempotency_conflict'); } return $j; }
                if ($j['plugin']===$plugin && in_array($j['status'],['queued','running','recovery_required'],true)) { throw new \RuntimeException('operation_pending'); }
            }
            if (count($s['jobs'])>=2000) { throw new \RuntimeException('queue_capacity'); }
            $id=bin2hex(random_bytes(16));
            $j=['id'=>$id,'key'=>$key,'plugin'=>$plugin,'version'=>$version,'operation'=>$operation,'user'=>$user,'origin'=>$origin,'status'=>'queued','created_at'=>time(),'expires_at'=>time()+86400,'started_at'=>null,'finished_at'=>null,'attempts'=>[],'phase'=>'queued','mutated'=>false,'error'=>null];
            $s['jobs'][$id]=$j;State::audit($s,'job_requested',$user,['id'=>$id,'plugin'=>$plugin,'version'=>$version,'origin'=>$origin]);return $j;
        });
    }
    public function claim(): ?array
    {
        return $this->state->change(static function (&$s) {
            foreach ($s['jobs'] as &$j) {
                if ($j['status']==='running') { $j['status']=$j['mutated'] ? 'recovery_required' : 'failed';$j['error']='worker_interrupted';$j['finished_at']=time();$j['attempts'][]=['at'=>time(),'result'=>$j['status']]; }
            } unset($j);
            foreach ($s['jobs'] as $j) { if ($j['status']==='recovery_required') { return null; } }
            foreach ($s['jobs'] as &$j) {
                if ($j['status']!=='queued') { continue; }
                if ($j['expires_at']<=time()) { $j['status']='expired';$j['finished_at']=time();continue; }
                $j['status']='running';$j['started_at']=time();$j['attempts'][]=['at'=>time(),'result'=>'started'];return $j;
            }
            return null;
        });
    }
    public function phase(string $id,string $phase,bool $mutated=false): void
    {
        $this->state->change(static function (&$s) use ($id,$phase,$mutated) { $s['jobs'][$id]['phase']=$phase;$s['jobs'][$id]['mutated']=$s['jobs'][$id]['mutated']||$mutated; });
    }
    public function finish(string $id,?string $error=null): void
    {
        $this->state->change(static function (&$s) use ($id,$error) {
            $j=&$s['jobs'][$id];$j['status']=$error ? ($j['mutated'] ? 'recovery_required' : 'failed') : 'succeeded';$j['error']=$error;$j['finished_at']=time();$j['attempts'][]=['at'=>time(),'result'=>$j['status']];State::audit($s,'job_'.$j['status'],$j['user'],['id'=>$id,'code'=>$error]);
        });
    }
}
