<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Telemetry
{
    private $state;private $env;private $https;
    public function __construct(State $state,Environment $env,Https $https) { $this->state=$state;$this->env=$env;$this->https=$https; }
    public function consent(bool $enabled,int $user): void
    {
        $this->state->change(static function (&$s) use ($enabled,$user) {
            $changed=$s['consent']!==$enabled;$s['consent']=$enabled;
            if (!$enabled) { $s['events']=[];$s['inventory']=[];$s['inventory_health']=[];$s['next_inventory']=0; }
            State::audit($s,'telemetry_consent',$user,['enabled'=>$enabled]);
            if ($enabled && $changed) { self::append($s,'manager_registered',[],'ui'); }
        });
    }
    public static function append(array &$s,string $type,array $data,string $origin): void
    {
        if (!$s['consent']) { return; }
        if (!in_array($type,['manager_registered','inventory_snapshot','plugin_detected','plugin_installed','plugin_updated','plugin_removed','plugin_install_failed','plugin_update_failed','plugin_health_changed'],true)) { throw new \InvalidArgumentException('telemetry_type'); }
        // An allowlist, not redaction: arbitrary diagnostic fields never enter the outbox.
        $clean=array_intersect_key($data,array_flip(['plugin','version','health','error_category','plugins']));
        $s['events'][]=['id'=>bin2hex(random_bytes(16)),'installation_id'=>$s['installation_id'],'manager_version'=>'0.1.0-dev','type'=>$type,'mautic'=>defined('MAUTIC_VERSION') ? MAUTIC_VERSION : 'unknown','php'=>PHP_VERSION,'collected_at'=>time(),'origin'=>$origin,'data'=>$clean,'attempts'=>0,'next_at'=>time()];
        $s['events']=array_slice($s['events'],-500);
    }
    public function inventory(array $plugins): void
    {
        $this->state->change(static function (&$s) use ($plugins) {
            if (!$s['consent'] || $s['next_inventory']>time()) { return; }
            $now=[];
            foreach ($plugins as $p) {
                $now[$p['id']]=$p['version'];
                $previous=$s['inventory_health'][$p['id']] ?? null;
                if ($previous!==null && $previous!==$p['health']) { self::append($s,'plugin_health_changed',['plugin'=>$p['id'],'version'=>$p['version'],'health'=>$p['health']],'automation'); }
                $s['inventory_health'][$p['id']]=$p['health'];
                if (!isset($s['inventory'][$p['id']])) { self::append($s,'plugin_detected',['plugin'=>$p['id'],'version'=>$p['version']],'automation'); }
            }
            foreach ($s['inventory'] as $id=>$v) { if (!isset($now[$id])) { self::append($s,'plugin_removed',['plugin'=>$id,'version'=>$v],'automation'); } }
            self::append($s,'inventory_snapshot',['plugins'=>$plugins],'automation');$s['inventory']=$now;$s['next_inventory']=time()+86400+random_int(0,3600);
        });
    }
    public function flush(): void
    {
        $endpoint=$this->env->config['telemetry_url'] ?? '';if (!$endpoint) { return; }
        // Keep the consent lock while transmitting. Revocation returns only after in-flight transmission ends.
        $this->state->change(function (&$s) use ($endpoint) {
            if (!$s['consent']) { $s['events']=[];return; }
            $sent=0;
            foreach ($s['events'] as $i=>&$e) {
                if ($e['attempts']>=5 || $e['collected_at']<time()-604800) { unset($s['events'][$i]);continue; }
                if ($e['next_at']>time() || $sent>=10) { continue; }++$sent;
                $payload=$e;unset($payload['attempts'],$payload['next_at']);
                try { $this->https->request($endpoint,['go.webanyone.net'],$payload);unset($s['events'][$i]); }
                catch (\Throwable $error) { ++$e['attempts'];$e['next_at']=time()+min(86400,60*(2**$e['attempts'])); }
            }unset($e);$s['events']=array_values($s['events']);
        });
    }
}
