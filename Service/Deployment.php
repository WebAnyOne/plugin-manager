<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Deployment
{
    private $env;private $state;private $process;private $restore;private $https;private $queue;
    public function __construct(Environment $env,State $state,Process $process,RestoreProvider $restore,Https $https,Queue $queue)
    { $this->env=$env;$this->state=$state;$this->process=$process;$this->restore=$restore;$this->https=$https;$this->queue=$queue; }
    public function composer(): array
    {
        $path=$this->env->config['composer'] ?? '';
        if (!$path) { foreach (explode(PATH_SEPARATOR,getenv('PATH') ?: '') as $dir) { if ($dir && is_file($dir.'/composer') && is_executable($dir.'/composer')) { $path=realpath($dir.'/composer');break; } } }
        if (!$path || $path[0]!=='/' || !is_file($path)) { throw new \RuntimeException('composer_cli_unavailable'); }
        // Execute the Composer PHP entry point with the same interpreter as this worker.
        return [PHP_BINARY,$path];
    }
    public function checks(): array
    {
        $r=['php'=>PHP_VERSION,'composer_cli'=>false,'extensions'=>false,'restore_provider'=>false,'restore_verified'=>false,'execution_limits'=>false,'limited_identity'=>function_exists('posix_geteuid') && posix_geteuid()>0];
        try {
            $version=$this->process->run(array_merge($this->composer(),['--version']),$this->env->root,20);
            $r['composer_cli']=preg_match('/Composer version (2\.[0-9]+\.[0-9]+)/',$version,$match) && version_compare($match[1],'2.5.5','>=');
        } catch (\Throwable $e) {}
        $r['extensions']=!array_diff(['curl','json','sodium','zip','pdo_mysql','mbstring'],get_loaded_extensions());
        $limit=trim(ini_get('memory_limit'));
        $bytes=(int)$limit;
        foreach (['K'=>1024,'M'=>1048576,'G'=>1073741824] as $unit=>$factor) { if (strtoupper(substr($limit,-1))===$unit) { $bytes=(int)$limit*$factor; } }
        $seconds=(int)ini_get('max_execution_time');
        $r['execution_limits']=function_exists('proc_open') && ($bytes===-1 || $bytes>=268435456) && ($seconds===0 || $seconds>=600);
        try { $this->restore->call('probe',str_repeat('0',32));$r['restore_provider']=true;$r['restore_verified']=true; } catch (\Throwable $e) {}
        return $r;
    }
    /** Refuse changes to every package except the exact target, including removals and source/reference drift. */
    public static function assertPlan(array $before,array $after,array $plugin,array $release): void
    {
        $a=[];$b=[];
        foreach (array_merge($before['packages'] ?? [],$before['packages-dev'] ?? []) as $p) { $a[$p['name']]=$p; }
        foreach (array_merge($after['packages'] ?? [],$after['packages-dev'] ?? []) as $p) { $b[$p['name']]=$p; }
        $target=$plugin['package'];$p=$b[$target] ?? null;
        if (!$p || ltrim($p['version'],'v')!==$release['version'] || $p['type']!=='mautic-plugin' || ($p['dist']['url'] ?? null)!==$release['artifact']['url'] || ($p['dist']['reference'] ?? null)!==$release['artifact']['reference'] || ($p['extra']['install-directory-name'] ?? null)!==$plugin['bundle']) { throw new \RuntimeException('unexpected_target'); }
        if (isset($p['extra']['installer-name']) && $p['extra']['installer-name']!==$plugin['bundle']) { throw new \RuntimeException('unexpected_target_path'); }
        unset($a[$target],$b[$target]);ksort($a);ksort($b);
        if ($a!==$b) { throw new \RuntimeException('unrelated_dependency_change'); }
        foreach (['platform','platform-dev','platform-overrides','aliases','minimum-stability','stability-flags','prefer-stable','prefer-lowest','plugin-api-version'] as $k) { if (($before[$k] ?? null)!==($after[$k] ?? null)) { throw new \RuntimeException('platform_change'); } }
    }
    private function guarded(array $argv,string $cwd,int $timeout,array $env=[]): void
    {
        $runner=__DIR__.'/../bin/run-guarded.php';
        $this->process->run([PHP_BINARY,$runner,$this->state->dir(),$cwd,base64_encode(json_encode($argv,JSON_THROW_ON_ERROR)),(string)$timeout],$cwd,$timeout+10,$env);
    }
    public function execute(array $job,array $plugin,array $release,callable $revalidate): void
    {
        if (PHP_SAPI!=='cli' || !$this->env->project || (function_exists('posix_geteuid') && posix_geteuid()===0)) { throw new \RuntimeException('limited_cli_identity_required'); }
        if ($plugin['package']==='webanyone/mautic-plugin-manager') { throw new \RuntimeException('manager_self_update_disabled'); }
        $project=$this->env->project;$root=json_decode(file_get_contents($project.'/composer.json'),true,64,JSON_THROW_ON_ERROR);
        if (!empty($root['repositories'])) { throw new \RuntimeException('custom_repositories_require_review'); }
        if (($root['config']['vendor-dir'] ?? 'vendor')!=='vendor' || !empty($root['extra']['installer-disable'])) { throw new \RuntimeException('custom_vendor_path_requires_review'); }
        $relative=$this->env->root===$project ? '' : substr($this->env->root,strlen($project)+1).'/';
        $expected=$relative.'plugins/{$name}';
        $paths=$root['extra']['installer-paths'] ?? [];
        if ($paths && (!isset($paths[$expected]) || !in_array('type:mautic-plugin',$paths[$expected],true))) { throw new \RuntimeException('custom_plugin_path_requires_review'); }
        if (!$paths && $relative!=='') { throw new \RuntimeException('custom_plugin_path_requires_review'); }
        foreach ($paths as $path=>$selectors) {
            if ($path!==$expected && array_intersect($selectors,[$plugin['package'],'type:mautic-plugin','vendor:'.explode('/',$plugin['package'])[0],'*'])) { throw new \RuntimeException('custom_plugin_path_requires_review'); }
        }
        $before=json_decode(file_get_contents($project.'/composer.lock'),true,64,JSON_THROW_ON_ERROR);
        if (!empty($before['packages-dev'])) { throw new \RuntimeException('development_project_requires_review'); }
        $baseline=[];
        foreach ($before['packages'] as $package) { $baseline[$package['name']]=$package['version']; }
        $installed=$this->env->versions()['packages'];
        ksort($baseline);ksort($installed);
        if ($baseline!==$installed) { throw new \RuntimeException('installed_lock_drift'); }
        $fingerprint=hash_file('sha256',$project.'/composer.lock').hash_file('sha256',$project.'/composer.json');
        $stage=$this->state->dir().'/operations/'.$job['id'];
        if (!mkdir($stage,0700,true)) { throw new \RuntimeException('stage_unavailable'); }
        copy($project.'/composer.json',$stage.'/composer.before.json');copy($project.'/composer.lock',$stage.'/composer.before.lock');
        copy($project.'/composer.json',$stage.'/composer.json');copy($project.'/composer.lock',$stage.'/composer.lock');
        $env=['COMPOSER_HOME'=>$stage.'/home','COMPOSER_CACHE_DIR'=>$stage.'/cache','COMPOSER'=>false,'COMPOSER_AUTH'=>false,'COMPOSER_ALLOW_SUPERUSER'=>'0'];
        $this->queue->phase($job['id'],'resolving');
        $cmd=array_merge($this->composer(),['require',$plugin['package'].':'.$release['version'],'--no-install','--no-scripts','--no-plugins','--no-interaction','--no-progress']);
        $this->process->run($cmd,$stage,240,$env);
        $after=json_decode(file_get_contents($stage.'/composer.lock'),true,64,JSON_THROW_ON_ERROR);self::assertPlan($before,$after,$plugin,$release);
        // The original project may suppress advisories. The isolated candidate may not.
        $candidateText=file_get_contents($stage.'/composer.json');
        $auditRoot=json_decode($candidateText,true,64,JSON_THROW_ON_ERROR);
        unset($auditRoot['config']['audit']);
        file_put_contents($stage.'/composer.json',json_encode($auditRoot,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        try {
            $this->process->run(array_merge($this->composer(),['audit','--locked','--no-dev','--format=json','--no-interaction','--no-plugins','--no-scripts']),$stage,60,$env);
        } catch (\Throwable $e) { throw new \RuntimeException('dependency_security_audit_failed'); }
        finally { file_put_contents($stage.'/composer.json',$candidateText); }
        $this->process->run(array_merge($this->composer(),['install','--dry-run','--no-plugins','--no-scripts','--no-interaction','--no-dev']),$stage,120,$env);
        $this->queue->phase($job['id'],'artifact_verification');
        $archive=$this->https->request($release['artifact']['url'],['codeload.github.com','api.github.com','github.com'],null,67108864);
        if (!hash_equals($release['artifact']['sha256'],hash('sha256',$archive))) { throw new \RuntimeException('artifact_integrity'); }
        $artifact=$stage.'/artifact.zip';file_put_contents($artifact,$archive);chmod($artifact,0600);
        // Point only the approved target at the verified, private local immutable artifact.
        foreach ($after['packages'] as &$p) { if ($p['name']===$plugin['package']) { $p['dist']=['type'=>'zip','url'=>'file://'.$artifact,'reference'=>$release['artifact']['reference'],'shasum'=>sha1($archive)]; } }unset($p);unset($archive);
        $candidate=json_decode(file_get_contents($stage.'/composer.json'),true,64,JSON_THROW_ON_ERROR);
        $candidate['config']['allow-plugins']=['composer/installers'=>true];
        if (!isset($this->env->versions()['packages']['composer/installers'])) { throw new \RuntimeException('native_installer_missing'); }
        $revalidate();
        $this->queue->phase($job['id'],'snapshot');$point=$this->restore->call('snapshot',$job['id']);
        file_put_contents($stage.'/checkpoint.json',json_encode($point,JSON_THROW_ON_ERROR));
        if ($fingerprint!==hash_file('sha256',$project.'/composer.lock').hash_file('sha256',$project.'/composer.json')) { throw new \RuntimeException('external_composer_change'); }
        $revalidate();
        // Persist the mutation marker BEFORE touching the live project: crash recovery is fail-closed.
        $this->queue->phase($job['id'],'composer_install',true);
        $candidateJson=json_encode($candidate,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $lockJson=json_encode($after,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($project.'/composer.json',$candidateJson)!==strlen($candidateJson) || file_put_contents($project.'/composer.lock',$lockJson)!==strlen($lockJson)) { throw new \RuntimeException('composer_write'); }
        $this->guarded(array_merge($this->composer(),['install','--no-scripts','--no-interaction','--prefer-dist','--no-progress','--no-dev']),$project,300,$env);
        // Restore the operator's plugin policy; preserve the exact target requirement.
        if (isset($root['config']['allow-plugins'])) { $candidate['config']['allow-plugins']=$root['config']['allow-plugins']; } else { unset($candidate['config']['allow-plugins']); }
        file_put_contents($project.'/composer.json',json_encode($candidate,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        $this->queue->phase($job['id'],'native_reload');
        $this->guarded([PHP_BINARY,$this->env->console(),'cache:clear','--env=prod','--no-debug'],$project,180);
        $this->guarded([PHP_BINARY,$this->env->console(),'mautic:plugins:reload','--env=prod','--no-debug'],$project,180);
        $this->queue->phase($job['id'],'health_verification');
        $this->guarded([PHP_BINARY,$this->env->console(),'wao:plugins:verify',$plugin['id'],$release['version'],'--env=prod','--no-debug'],$project,60);
        $this->restore->call('verify',$job['id'],$point['checkpoint']);
    }
}
