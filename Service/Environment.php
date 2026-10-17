<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Environment
{
    public $root;public $project;public $config;
    public function __construct(string $root,array $config)
    {
        $this->root=realpath($root) ?: $root;$this->project=null;$this->config=$config;
        $p=$this->root;
        for ($i=0;$i<4;++$i) {
            if (is_file($p.'/composer.json') && is_file($p.'/composer.lock') && is_dir($p.'/vendor')) {
                $j=json_decode(file_get_contents($p.'/composer.json'),true);
                if (isset($j['require']['mautic/core-lib']) || isset($j['require']['mautic/core'])) { $this->project=$p;break; }
            }
            $p=dirname($p);
        }
    }
    public function storage(): string
    {
        $path=$this->config['storage'] ?? '';
        if (!$path) { $path=dirname($this->root).'/wao-private/plugin-manager-'.substr(hash('sha256',$this->root),0,16); }
        $parent=realpath(is_dir($path) ? $path : dirname($path));
        if ($path[0]!=='/' || strpos($path,$this->root.'/')===0 || ($parent && ($parent===$this->root || strpos($parent,$this->root.'/')===0))) { throw new \RuntimeException('storage_must_be_outside_webroot'); }
        return $path;
    }
    public function console(): string
    {
        foreach (array_unique(array_filter([$this->root.'/bin/console',$this->project ? $this->project.'/bin/console' : null])) as $path) {
            if (is_file($path) && is_readable($path)) { return realpath($path); }
        }
        throw new \RuntimeException('native_console_unavailable');
    }
    public function versions(): array
    {
        $versions=[];
        $file=$this->root.'/vendor/composer/installed.json';
        if (!is_file($file) && $this->project) { $file=$this->project.'/vendor/composer/installed.json'; }
        if (is_file($file)) { $j=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);foreach ($j['packages'] ?? $j as $pkg) { $versions[$pkg['name']]=$pkg['version']; } }
        return ['mautic'=>defined('MAUTIC_VERSION') ? MAUTIC_VERSION : '0.0.0','php'=>PHP_VERSION,'extensions'=>get_loaded_extensions(),'packages'=>$versions];
    }
    /** HTTP diagnostics deliberately never launch a process. CLI checks are supplied by the worker heartbeat. */
    public function diagnose(State $state): array
    {
        $s=$state->read();$w=$s['worker_checks'] ?? [];
        $project=$this->project;
        try { $console=$this->console(); } catch (\Throwable $e) { $console=null; }
        return [
            'composer_project'=>(bool)$project,
            'native_console'=>(bool)$console,
            'composer_writable'=>$project && is_writable($project.'/composer.json') && is_writable($project.'/composer.lock') && is_writable($project),
            'plugins_writable'=>is_writable($this->root.'/plugins'),
            'composer_cli'=>($w['composer_cli'] ?? false),
            'php_cli'=>isset($w['php']) && $w['php']===PHP_VERSION,
            'extensions_cli'=>($w['extensions'] ?? false),
            'execution_limits'=>($w['execution_limits'] ?? false),
            'limited_identity'=>($w['limited_identity'] ?? false),
            'worker'=>($s['worker_at'] ?? 0)>time()-300,
            'https'=>($s['catalogue_at'] ?? 0)>time()-3600,
            'disk'=>disk_free_space($state->dir())>1024*1024*1024,
            'restore_provider'=>($w['restore_provider'] ?? false),
            'restore_verified'=>($w['restore_verified'] ?? false),
            'no_incident'=>!count(array_filter($s['jobs'],static function($j) { return $j['status']==='recovery_required'; })),
        ];
    }
}
