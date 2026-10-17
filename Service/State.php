<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
/** Local persistent store. A separate flock file survives atomic data replacement. */
final class State
{
    private $dir;
    public function __construct(string $dir)
    {
        $this->dir=$dir;
        if ((!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) || is_link($dir)) { throw new \RuntimeException('storage_unavailable'); }
        chmod($dir,0700);
    }
    public function dir(): string { return $this->dir; }
    public function read(): array { return $this->change(static function (&$s) { return $s; },false); }
    public function change(callable $fn,bool $write=true)
    {
        $lock=fopen($this->dir.'/state.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new \RuntimeException('storage_lock'); }
        try {
            $file=$this->dir.'/state.json';
            $s=is_file($file) ? json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR) : [];
            if (!is_array($s)) { throw new \RuntimeException('storage_corrupt'); }
            $s+=['installation_id'=>bin2hex(random_bytes(24)),'consent'=>false,'auto_patch'=>true,'check_hours'=>24,'wizard'=>0,'jobs'=>[],'events'=>[],'audit'=>[],'worker_at'=>0,'catalogue'=>null,'catalogue_sequence'=>0,'catalogue_digest'=>'','keys'=>[],'revoked'=>[],'rate'=>[],'next_inventory'=>0,'inventory'=>[],'feedback'=>[]];
            $result=$fn($s);
            if ($write || !is_file($file)) {
                $data=json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                if (strlen($data)>8*1024*1024) { throw new \RuntimeException('storage_capacity'); }
                $tmp=tempnam($this->dir,'state-');
                try {
                    chmod($tmp,0600);
                    if (file_put_contents($tmp,$data)!==strlen($data) || !rename($tmp,$file)) { throw new \RuntimeException('storage_write'); }
                } finally { if (is_file($tmp)) { unlink($tmp); } }
            }
            return $result;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public static function audit(array &$s,string $event,int $user=0,array $data=[]): void
    {
        $s['audit'][]=['at'=>time(),'event'=>$event,'user'=>$user,'data'=>$data];
        $s['audit']=array_slice($s['audit'],-2000);
    }
    public function limit(string $key,int $seconds=60): void
    {
        $this->change(static function (&$s) use ($key,$seconds) {
            $s['rate']=array_filter($s['rate'],static function($t) { return $t>time()-86400; });
            if (($s['rate'][$key] ?? 0)>time()-$seconds) { throw new \RuntimeException('rate_limited'); }
            $s['rate'][$key]=time();
        });
    }
}
