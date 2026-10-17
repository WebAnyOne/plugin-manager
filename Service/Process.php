<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
use Symfony\Component\Process\Process as SymfonyProcess;
final class Process
{
    public function guarded(array $argv,string $cwd,int $timeout,string $directory,array $env=[]): string
    {
        return $this->run([PHP_BINARY,__DIR__.'/../bin/run-guarded.php',$directory,$cwd,base64_encode(json_encode($argv,JSON_THROW_ON_ERROR)),(string)$timeout],$cwd,$timeout+10,$env);
    }
    public function run(array $argv,string $cwd,int $timeout=300,array $env=[]): string
    {
        if (PHP_SAPI!=='cli') { throw new \RuntimeException('cli_only'); }
        $p=new SymfonyProcess($argv,$cwd,$env);$p->setTimeout($timeout);
        // Never persist raw output: Composer and providers can contain credentials and personal information.
        $output='';$p->run(static function($type,$chunk) use (&$output) { if (strlen($output)<1048576) { $output.=substr($chunk,0,1048576-strlen($output)); } });
        if (!$p->isSuccessful()) { throw new \RuntimeException('process_failed'); }
        return $output;
    }
}
