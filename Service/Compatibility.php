<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
use Composer\Semver\Semver;
final class Compatibility
{
    public function evaluate(array $plugin,array $release,array $env): string
    {
        if (in_array($release['version'],$plugin['blocked_versions'],true) || $release['rollout']['state']==='blocked' || !Semver::satisfies($env['mautic'],$release['mautic']) || !Semver::satisfies($env['php'],$release['php'])) { return 'incompatible'; }
        foreach ($release['extensions'] as $ext) { if (!in_array($ext,$env['extensions'],true)) { return 'incompatible'; } }
        foreach ($release['conflicts'] as $pkg=>$constraint) { if (isset($env['packages'][$pkg]) && Semver::satisfies($env['packages'][$pkg],$constraint)) { return 'incompatible'; } }
        foreach ($release['dependencies'] as $pkg=>$constraint) { if (!isset($env['packages'][$pkg]) || !Semver::satisfies($env['packages'][$pkg],$constraint)) { return 'unverified'; } }
        foreach ($release['certifications'] as $c) {
            if ($c['result']!=='passed' || $c['mautic']!==$env['mautic'] || $c['php']!==$env['php'] || !preg_match('/^[a-f0-9]{64}$/D',$c['report_sha256'])) { continue; }
            $ok=true;
            foreach ($c['dependencies'] as $pkg=>$version) { if (($env['packages'][$pkg] ?? null)!==$version) { $ok=false; } }
            foreach (['doctrine/dbal','doctrine/orm','symfony/framework-bundle'] as $pkg) { if (isset($env['packages'][$pkg]) && !isset($c['dependencies'][$pkg])) { $ok=false; } }
            if ($ok) { return 'certified'; }
        }
        return 'unverified';
    }
    public static function bucket(string $installation,string $plugin,string $version,string $campaign): int
    {
        $bytes=unpack('C4',substr(hash('sha256',implode("\0",[$installation,$plugin,$version,$campaign]),true),0,4));
        $bucket=0;
        foreach ($bytes as $byte) { $bucket=($bucket*256+$byte)%10000; }
        return $bucket;
    }
    public static function eligible(string $installation,string $plugin,array $release): bool
    {
        $r=$release['rollout'];
        $observation=$r['percentage']>25 ? 172800 : ($r['percentage']>5 ? 86400 : 0);
        return $r['state']==='active' && $r['opens_at']+$observation<=time() && ($r['percentage']<=5 || $r['expand_after']<=time()) && self::bucket($installation,$plugin,$release['version'],$r['campaign'])<$r['percentage']*100;
    }
    public static function patch(string $old,string $new): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$old,$a) && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$new,$b) && $a[1]===$b[1] && $a[2]===$b[2] && (int)$b[3]>(int)$a[3];
    }
}
