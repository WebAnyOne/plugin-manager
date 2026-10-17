<?php
if ('cli'!==PHP_SAPI) { exit(1); }
$lab=realpath($argv[1] ?? '');
if (!$lab || !is_file($lab.'/autoload.php')) { throw new RuntimeException('Supply a disposable Mautic laboratory'); }
$_SERVER['SCRIPT_FILENAME']=$lab.'/index.php'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['PHP_SELF']='/index.php';
chdir($lab); $composerLoader=require $lab.'/autoload.php';
$composerLoader->addPsr4('MauticPlugin\\WebAnyOnePluginManagerBundle\\',$lab.'/plugins/WebAnyOnePluginManagerBundle',true);
$_SERVER['APP_ENV']='prod'; $_ENV['APP_ENV']='prod';
$inputs=[];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/..',FilesystemIterator::SKIP_DOTS)) as $file) {
 if (in_array($file->getExtension(),['php','ini'],true)) { $inputs[$file->getPathname()]=hash_file('sha256',$file->getPathname()); }
}
foreach ([$lab.'/app/config/local.php',$lab.'/config/local.php',dirname($lab).'/config/local.php'] as $file) { if (is_file($file)) { $inputs[$file]=hash_file('sha256',$file); } }
ksort($inputs);$bundleFingerprint=substr(hash('sha256',implode('',$inputs)),0,16);
class PluginManagerTestKernel extends AppKernel
{
    public function getProjectDir(): string { $p=$GLOBALS['lab'];if (version_compare(MAUTIC_VERSION,'5.0','<')) { return $p; }return is_file(dirname($p).'/composer.json') && is_dir(dirname($p).'/vendor') ? dirname($p) : $p; }
    public function getRootDir(): string { return $GLOBALS['lab'].'/app'; }
    public function getApplicationDir(): string { return $GLOBALS['lab']; }
    public function getCacheDir(): string { return sys_get_temp_dir().'/wao-manager-tests/'.basename($GLOBALS['lab']).'-'.substr(hash('sha256',$GLOBALS['lab']),0,8).'/cache-'.$GLOBALS['bundleFingerprint']; }
    public function getLogDir(): string { return sys_get_temp_dir().'/wao-manager-tests/'.basename($GLOBALS['lab']).'-'.substr(hash('sha256',$GLOBALS['lab']),0,8).'/logs'; }
}
$kernel=new PluginManagerTestKernel('prod',false);
if (version_compare(MAUTIC_VERSION,'5.0','>=')) { $loader=new ReflectionProperty(AppKernel::class,'parameterLoader'); $loader->setAccessible(true); $parameters=new Mautic\CoreBundle\Loader\ParameterLoader($lab.'/app');$loader->setValue($kernel,$parameters);if (method_exists($parameters,'loadIntoEnvironment')) { $parameters->loadIntoEnvironment(); }$prefix=(string)$parameters->getParameterBag()->get('db_table_prefix');$_SERVER['MAUTIC_TABLE_PREFIX']=$prefix;$_ENV['MAUTIC_TABLE_PREFIX']=$prefix;putenv('MAUTIC_TABLE_PREFIX='.$prefix); }
try { $kernel->boot(); } catch (\Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString());exit(1); } $c=$kernel->getContainer();
if (!is_dir($kernel->getCacheDir().'/htmlpurifier')) { mkdir($kernel->getCacheDir().'/htmlpurifier',0700,true); }
$em=$c->get('doctrine.orm.entity_manager'); $db=$em->getConnection(); $em->getProxyFactory()->generateProxyClasses($em->getMetadataFactory()->getAllMetadata());
if (strpos($db->getDatabase(),'apimonitor_lab_')!==0) { throw new RuntimeException('Only disposable apimonitor_lab_* databases are allowed'); }
function check($ok,$message) { global $assertions; ++$assertions; if (!$ok) { throw new RuntimeException($message); } }
$assertions=0;
function loginAs($user): void
{
    global $c;
    $token=version_compare(MAUTIC_VERSION,'6.0','>=')
        ? new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user,'main',$user->getRoles())
        : new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user,'unused','main',$user->getRoles());
    $security=$c->get('mautic.security');
    $property=new ReflectionProperty($security,'grantedPermissions'); $property->setAccessible(true); $property->setValue($security,[]);
    $helper=$c->get('mautic.helper.user');
    $r=new ReflectionObject($helper);
    while (!$r->hasProperty('tokenStorage')) { $r=$r->getParentClass(); }
    $storage=$r->getProperty('tokenStorage'); $storage->setAccessible(true); $storage->getValue($helper)->setToken($token);
    // UserHelper reads the token, rather than relying on an entity injected in a test-only stub.
}

set_exception_handler(static function($e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
set_error_handler(static function($severity,$message,$file,$line) { if (error_reporting() & $severity) { throw new ErrorException($message,0,$severity,$file,$line); } return false; });
