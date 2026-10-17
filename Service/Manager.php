<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Manager
{
    public $env;public $state;public $catalogue;public $compatibility;public $queue;public $telemetry;public $access;private $em;private $kernel;
    public function __construct($paths,$params,$em,Access $access,$kernel)
    {
        $config=[];
        foreach (['storage','catalogue_url','trusted_keys','telemetry_url','restore_provider','composer'] as $key) { $config[$key]=$params->get('wao_plugin_manager_'.$key); }
        $this->env=new Environment($paths->getRootPath(),$config);$this->state=new State($this->env->storage());
        $https=new Https();$this->catalogue=new Catalogue($this->state,$https,$config);$this->compatibility=new Compatibility();$this->queue=new Queue($this->state);$this->telemetry=new Telemetry($this->state,$this->env,$https);$this->access=$access;$this->em=$em;$this->kernel=$kernel;
        try {
            $local=$this->state->read();$bundled=file_get_contents(__DIR__.'/../catalogue/manifest.json');
            $envelope=json_decode($bundled,true,64,JSON_THROW_ON_ERROR);
            $metadata=json_decode(base64_decode($envelope['payload'],true),true,64,JSON_THROW_ON_ERROR);
            if (!$local['catalogue'] || $metadata['sequence']>$local['catalogue_sequence']) { $this->catalogue->accept($bundled); }
        } catch (\Throwable $e) { /* Expired bundled metadata stays available as a read-only preview. */ }
    }
    public function adapter(): \MauticPlugin\WebAnyOnePluginManagerBundle\Adapter\AdapterInterface
    {
        $major=(int)explode('.',MAUTIC_VERSION)[0];$class='MauticPlugin\\WebAnyOnePluginManagerBundle\\Adapter\\Mautic'.$major;
        if (!class_exists($class)) { throw new \RuntimeException('unsupported_mautic'); }return new $class();
    }
    public function inventory(): array
    {
        $out=[];$env=$this->env->versions();$bundles=$this->kernel->getBundles();
        foreach ($this->catalogue->view()['manifest']['plugins'] as $p) {
            $version=$env['packages'][$p['package']] ?? null;$found=is_dir($this->env->root.'/plugins/'.$p['bundle']);
            if (!$version && !$found) { continue; }
            $registered=isset($bundles[$p['bundle']]);
            $entity=$this->em->getRepository(\Mautic\PluginBundle\Entity\Plugin::class)->findOneBy(['bundle'=>$p['bundle']]);
            $health='unknown';$issues=[];
            if (!$version) { $issues[]='package_not_in_composer'; }
            if (!$registered || !$entity) { $issues[]='bundle_not_registered';$health='error'; }
            $release=null;foreach ($p['releases'] as $r) { if ($r['version']===ltrim($version ?: '','v')) { $release=$r; } }
            if ($release && $this->compatibility->evaluate($p,$release,$env)==='incompatible') { $issues[]='incompatible';$health='error'; }
            if ($health!=='error') {
                $probe='wao.plugin_probe.'.$p['id'];$container=$this->kernel->getContainer();
                if ($entity && $entity->getIsMissing()) { $health='error';$issues[]='bundle_not_registered'; }
                elseif ($version && $registered && $release && $this->compatibility->evaluate($p,$release,$env)==='certified' && $container->has($probe) && $container->get($probe) instanceof HealthProbeInterface) {
                    try { $health=$container->get($probe)->healthy() ? 'healthy' : 'error'; }
                    catch (\Throwable $e) { $health='error'; }
                    if ($health==='error') { $issues[]='functional_probe_failed'; }
                } else { $issues[]='functional_probe_unavailable';$health='unknown'; }
            }
            $last=null;
            foreach ($this->state->read()['jobs'] as $j) { if ($j['plugin']!==$p['id']) { continue; }if ($j['status']==='recovery_required') { $health='error';$issues[]='incomplete_operation'; }if ($j['status']==='succeeded') { $last=max($last ?: 0,$j['finished_at']); } }
            $out[]=['id'=>$p['id'],'name'=>$p['name'],'version'=>$version ?: 'unknown','health'=>$health,'issues'=>array_unique($issues),'updated_at'=>$last,'available'=>$p['recommended']];
        }
        $knownBundles=array_column($this->catalogue->view()['manifest']['plugins'],'bundle');
        foreach ($bundles as $bundle=>$object) {
            if (strpos($bundle,'WebAnyOne')!==0 || $bundle==='WebAnyOnePluginManagerBundle' || in_array($bundle,$knownBundles,true)) { continue; }
            $entity=$this->em->getRepository(\Mautic\PluginBundle\Entity\Plugin::class)->findOneBy(['bundle'=>$bundle]);
            $version=$entity ? $entity->getVersion() : null;
            $id=strtolower(preg_replace('/(?<!^)[A-Z]/','-$0',substr($bundle,9,-6)));
            $out[]=['id'=>$id,'name'=>$entity ? $entity->getName() : $bundle,'version'=>$version ?: 'unknown','health'=>'unknown','issues'=>['outside_approved_catalogue'],'updated_at'=>null,'available'=>''];
        }
        return $out;
    }
    public function admissible(string $id,string $version,bool $automatic=false): array
    {
        [$p,$r]=$this->catalogue->release($id,$version);
        if ($p['package']==='webanyone/mautic-plugin-manager') { throw new \RuntimeException('manager_self_update_disabled'); }
        if ($this->compatibility->evaluate($p,$r,$this->env->versions())!=='certified') { throw new \RuntimeException('release_not_certified'); }
        if ($r['rollout']['state']!=='active') { throw new \RuntimeException('release_suspended'); }
        if ($automatic && !Compatibility::eligible($this->state->read()['installation_id'],$id,$r)) { throw new \RuntimeException('rollout_not_eligible'); }
        return [$p,$r];
    }
    public function request(array $input,int $user,string $origin): array
    {
        if (array_diff(array_keys($input),['plugin','version','operation','idempotency_key'])) { throw new \InvalidArgumentException('unknown_field'); }
        foreach (['plugin','version','operation','idempotency_key'] as $k) { if (!isset($input[$k]) || !is_string($input[$k])) { throw new \InvalidArgumentException('missing_field'); } }
        $existing=$this->queue->existing($input,$user,$origin);
        if ($existing) { return $existing; }
        [$p,$r]=$this->admissible($input['plugin'],$input['version'],$origin==='automation');
        $current=$this->env->versions()['packages'][$p['package']] ?? null;
        if ($input['operation']==='install' && $current) { throw new \RuntimeException('already_installed'); }
        if ($input['operation']==='update' && (!$current || !version_compare($r['version'],ltrim($current,'v'),'>'))) { throw new \RuntimeException('update_requires_newer_release'); }
        foreach ($this->env->diagnose($this->state) as $key=>$ok) { if (!$ok) { throw new \RuntimeException('prerequisite_'.$key); } }
        return $this->queue->enqueue($input['plugin'],$input['version'],$input['operation'],$input['idempotency_key'],$user,$origin);
    }
    public function deployment(): Deployment { $p=new Process();return new Deployment($this->env,$this->state,$p,new RestoreProvider($this->env,$p),new Https(),$this->queue); }
    public function worker(): array
    {
        if (PHP_SAPI!=='cli') { throw new \RuntimeException('cli_only'); }
        $lock=fopen($this->state->dir().'/composer.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { throw new \RuntimeException('worker_busy'); }
        try {
            $deployment=$this->deployment();$checks=$deployment->checks();
            $this->state->change(static function (&$s) use ($checks) { $s['worker_at']=time();$s['worker_checks']=$checks; });
            try { $this->catalogue->refresh(); } catch (\Throwable $e) { $this->state->change(static function (&$s) { $s['catalogue_error']='sync_failed'; }); }
            $s=$this->state->read();
            if ($s['auto_patch'] && $this->catalogue->view()['fresh'] && ($s['last_check'] ?? 0)<=time()-$s['check_hours']*3600) {
                foreach ($this->catalogue->view()['manifest']['plugins'] as $p) {
                    $old=$this->env->versions()['packages'][$p['package']] ?? '';
                    if (!$old || !Compatibility::patch(ltrim($old,'v'),$p['recommended'])) { continue; }
                    try { $this->request(['plugin'=>$p['id'],'version'=>$p['recommended'],'operation'=>'update','idempotency_key'=>hash('sha256',$p['id'].':'.$p['recommended'])],0,'automation'); } catch (\Throwable $e) {}
                }
                $this->state->change(static function (&$s) { $s['last_check']=time(); });
            }
            $results=[];$deadline=time()+45;
            while (time()<$deadline && ($job=$this->queue->claim())) {
                try {
                    $this->catalogue->refresh();[$p,$r]=$this->admissible($job['plugin'],$job['version'],$job['origin']==='automation');
                    foreach ($this->env->diagnose($this->state) as $key=>$ok) { if (!$ok) { throw new \RuntimeException('prerequisite_'.$key); } }
                    $revalidate=function() use ($job) { $this->catalogue->refresh();$this->admissible($job['plugin'],$job['version'],$job['origin']==='automation'); };
                    $deployment->execute($job,$p,$r,$revalidate);$this->queue->finish($job['id']);$error=null;
                } catch (\Throwable $e) { $error=preg_match('/^[a-z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'operation_failed';$this->queue->finish($job['id'],$error); }
                $this->state->change(static function (&$s) use ($job,$error) { $type=$error ? ($job['operation']==='install' ? 'plugin_install_failed' : 'plugin_update_failed') : ($job['operation']==='install' ? 'plugin_installed' : 'plugin_updated');Telemetry::append($s,$type,['plugin'=>$job['plugin'],'version'=>$job['version'],'error_category'=>$error ? 'deployment' : 'none'],$job['origin']); });
                $results[]=['job'=>$job['id'],'error'=>$error];
                $this->state->change(static function (&$s) { $s['worker_at']=time(); });
            }
            // After a deployment this worker's kernel predates the new bundles. Let the
            // next fresh worker observe inventory instead of reporting a false health error.
            if (!$results) {
                $inventory=array_map(static function($p) { return array_intersect_key($p,array_flip(['id','version','health'])); },$this->inventory());
                $this->telemetry->inventory($inventory);
            }
            $this->telemetry->flush();return $results;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function diagnosticExport(): array
    {
        $jobs=array_filter($this->state->read()['jobs'],static function($job) { return $job['error']!==null; });
        $failures=array_map(static function($job) {
            return array_intersect_key($job,array_flip(['id','plugin','version','status','phase','error','created_at','finished_at']));
        },array_slice(array_values($jobs),-5));
        $dependencies=array_intersect_key($this->env->versions()['packages'],array_flip(['doctrine/dbal','doctrine/orm','symfony/framework-bundle','composer/installers','symfony/process','composer/semver']));
        return ['manager'=>'0.1.0-dev','mautic'=>MAUTIC_VERSION,'php'=>PHP_VERSION,'checks'=>$this->env->diagnose($this->state),'dependencies'=>$dependencies,'recent_failures'=>$failures,'plugins'=>array_map(static function($p) { return array_intersect_key($p,array_flip(['id','version','health','issues'])); },$this->inventory())];
    }

    public function verify(string $id,string $version): void
    {
        [$p,$r]=$this->admissible($id,$version);
        $installed=$this->env->versions()['packages'][$p['package']] ?? '';
        $entity=$this->em->getRepository(\Mautic\PluginBundle\Entity\Plugin::class)->findOneBy(['bundle'=>$p['bundle']]);
        if (ltrim($installed,'v')!==$version || !isset($this->kernel->getBundles()[$p['bundle']]) || !$entity || $entity->getIsMissing()) { throw new \RuntimeException('bundle_verification_failed'); }
        $expected=realpath($this->env->root.'/plugins/'.$p['bundle']);
        $installedPath=method_exists(\Composer\InstalledVersions::class,'getInstallPath') ? realpath(\Composer\InstalledVersions::getInstallPath($p['package']) ?: '') : null;
        if (!$expected || $installedPath!==$expected || realpath($this->kernel->getBundles()[$p['bundle']]->getPath())!==$expected) { throw new \RuntimeException('unexpected_installed_path'); }
        $service='wao.plugin_probe.'.$id;$c=$this->kernel->getContainer();
        if (!$c->has($service) || !$c->get($service) instanceof HealthProbeInterface || !$c->get($service)->healthy()) { throw new \RuntimeException('functional_probe_required'); }
    }
    public function recover(string $id): void
    {
        if (PHP_SAPI!=='cli' || !preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new \InvalidArgumentException('invalid_job'); }
        $lock=fopen($this->state->dir().'/composer.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { throw new \RuntimeException('worker_busy'); }
        $execution=null;
        try {
            $j=$this->state->read()['jobs'][$id] ?? null;
            if (!$j || $j['status']!=='recovery_required') { throw new \RuntimeException('recovery_not_required'); }
            $execution=fopen($this->state->dir().'/execution.lock','c');
            if (!$execution || !flock($execution,LOCK_EX|LOCK_NB)) { throw new \RuntimeException('child_process_still_running'); }
            $meta=$this->state->dir().'/active-process.json';
            if (is_file($meta)) {
                $active=json_decode(file_get_contents($meta),true,16,JSON_THROW_ON_ERROR);
                if (!function_exists('posix_kill') || posix_kill((int)$active['pid'],0)) { throw new \RuntimeException('child_process_still_running'); }
                unlink($meta);
            }
            $point=json_decode(file_get_contents($this->state->dir().'/operations/'.$id.'/checkpoint.json'),true,16,JSON_THROW_ON_ERROR);
            $provider=new RestoreProvider($this->env,new Process());$provider->call('restore',$id,$point['checkpoint']);$provider->call('verify',$id,$point['checkpoint']);
            $this->state->change(static function (&$s) use ($id) { $s['jobs'][$id]['status']='restored';$s['jobs'][$id]['finished_at']=time();State::audit($s,'job_restored',0,['id'=>$id]); });
        } finally { if (is_resource($execution)) { flock($execution,LOCK_UN);fclose($execution); }flock($lock,LOCK_UN);fclose($lock); }
    }
    public function settings(array $data,int $user): void
    {
        if (array_diff(array_keys($data),['consent','auto_patch','check_hours','notifications'])) { throw new \InvalidArgumentException('unknown_setting'); }
        foreach (['consent','auto_patch','notifications'] as $k) { if (array_key_exists($k,$data) && !is_bool($data[$k])) { throw new \InvalidArgumentException('setting_type'); } }
        if (array_key_exists('check_hours',$data) && (!is_int($data['check_hours']) || $data['check_hours']<1 || $data['check_hours']>168)) { throw new \InvalidArgumentException('setting_frequency'); }
        if (array_key_exists('consent',$data)) { $this->telemetry->consent($data['consent'],$user); }
        $this->state->change(static function (&$s) use ($data,$user) { foreach (['auto_patch','check_hours','notifications'] as $key) { if (isset($data[$key])) { $s[$key]=$data[$key]; } }State::audit($s,'settings_changed',$user); });
    }
}
