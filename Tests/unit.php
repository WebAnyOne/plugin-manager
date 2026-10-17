<?php
if (PHP_SAPI!=='cli') { exit(1); }
$autoload=getenv('MAUTIC_AUTOLOAD');
if ($autoload && is_file($autoload)) { require $autoload; }
elseif (is_file(__DIR__.'/../vendor/autoload.php')) { require __DIR__.'/../vendor/autoload.php'; }
else { fwrite(STDERR,"Set MAUTIC_AUTOLOAD to a Mautic vendor/autoload.php or install dependencies.\n");exit(1); }
spl_autoload_register(static function($class) { $prefix='MauticPlugin\\WebAnyOnePluginManagerBundle\\';if (strpos($class,$prefix)===0) { require __DIR__.'/../'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; } });
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\State;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Catalogue;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Https;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Compatibility;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Queue;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Deployment;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Telemetry;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Environment;
$n=0;
function check($ok,$message) { global $n;++$n;if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $fn,$message) { try { $fn(); } catch (Throwable $e) { check(true,$message);return; }check(false,$message); }
$dir=sys_get_temp_dir().'/wao-manager-unit-'.bin2hex(random_bytes(8));$state=new State($dir);
$shipped=json_decode(file_get_contents(__DIR__.'/../catalogue/manifest.json'),true);
$payload=base64_decode($shipped['payload'],true);$roots=json_decode(file_get_contents(__DIR__.'/../catalogue/trusted-keys.json'),true);$signature=$shipped['signatures'][0];
check(sodium_crypto_sign_verify_detached(base64_decode($signature['signature']),$payload,base64_decode($roots[$signature['key_id']])),'Shipped manifest has a valid pinned signature');
MauticPlugin\WebAnyOnePluginManagerBundle\Service\Schema::check(json_decode($payload),json_decode(file_get_contents(__DIR__.'/../catalogue/schema.json'),true));check(true,'Shipped manifest conforms to the closed schema');
$keypair=sodium_crypto_sign_keypair();$public=sodium_crypto_sign_publickey($keypair);$secret=sodium_crypto_sign_secretkey($keypair);
$catalogue=new Catalogue($state,new Https(),['trusted_keys'=>['test-1'=>base64_encode($public)]]);
function envelope(array $manifest,string $key,string $id='test-1'): string {
 $raw=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return json_encode(['payload'=>base64_encode($raw),'signatures'=>[['key_id'=>$id,'signature'=>base64_encode(sodium_crypto_sign_detached($raw,$key))]]],JSON_THROW_ON_ERROR);
}
$m=json_decode(file_get_contents(__DIR__.'/../catalogue/bootstrap.json'),true);$m['sequence']=1;$m['issued_at']=time()-10;$m['expires_at']=time()+3600;$m['next_keys']=(object)[];
$m['plugins']=[$m['plugins'][0]];
$p=$m['plugins'][0];
$r=['version'=>'1.2.3','mautic'=>'4.4.13 || 5.2.11 || 6.0.9','php'=>'>=7.4 <8.4','extensions'=>['json'],'dependencies'=>(object)[],'conflicts'=>(object)[],
'certifications'=>[['mautic'=>'6.0.9','php'=>PHP_VERSION,'dependencies'=>['doctrine/dbal'=>'3.7.3','doctrine/orm'=>'2.18.0','symfony/framework-bundle'=>'6.4.13'],'result'=>'passed','report_url'=>'https://github.com/WebAnyOne/plugin-manager/blob/main/tests/report.json','report_sha256'=>str_repeat('a',64),'qualified_at'=>time()]],
'artifact'=>['url'=>'https://codeload.github.com/WebAnyOne/plugin-manager/zip/'.str_repeat('a',40),'sha256'=>str_repeat('b',64),'reference'=>str_repeat('a',40)],
'rollout'=>['state'=>'active','percentage'=>5,'campaign'=>'test','opens_at'=>time()-86400,'expand_after'=>time()+86400],'published_at'=>time()-100,'changelog'=>['fr'=>'Test','en'=>'Test']];
$m['plugins'][0]['releases']=[$r];$m['plugins'][0]['recommended']='1.2.3';
$catalogue->accept(envelope($m,$secret));check($catalogue->view()['fresh'],'valid signed catalogue');
$catalogue->accept(envelope($m,$secret));check(true,'identical sequence idempotent');
$bad=$m;$bad['sequence']=0;rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'rollback rejected');
$bad=$m;$bad['sequence']=2;$bad['expires_at']=time()-1;rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'expired rejected');
$bad=$m;$bad['evil']='command';rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'unknown schema property rejected');
$bad=$m;$bad['plugins'][0]['releases'][0]['php']='php >=|DROP';rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'invalid Composer constraint rejected before caching');
$bad=$m;$bad['plugins'][0]['documentation']['fr']='javascript:alert(1)';rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'executable documentation URL rejected');
$bad=$m;$bad['plugins'][0]['releases'][0]['command']='rm -rf';rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'executable release property rejected');
$bad=json_decode(envelope($m,$secret),true);$bad['payload']=base64_encode(str_replace('1.2.3','1.2.4',base64_decode($bad['payload'])));rejects(function() use ($catalogue,$bad) { $catalogue->accept(json_encode($bad)); },'tampered payload rejected');
$bad=$m;$bad['plugins'][0]['name']='different';rejects(function() use ($catalogue,$bad,$secret) { $catalogue->accept(envelope($bad,$secret)); },'same sequence different digest rejected');
$key2=sodium_crypto_sign_keypair();$pub2=sodium_crypto_sign_publickey($key2);$sec2=sodium_crypto_sign_secretkey($key2);
$m['sequence']=2;$m['next_keys']=['test-2'=>base64_encode($pub2)];$catalogue->accept(envelope($m,$secret));
$m['sequence']=3;$m['revoked_keys']=['test-1'];$catalogue->accept(envelope($m,$sec2,'test-2'));check(true,'rotation chained to trusted signing key');
$m['sequence']=4;rejects(function() use ($catalogue,$m,$secret) { $catalogue->accept(envelope($m,$secret)); },'revoked root rejected');
$catalogue->accept(envelope($m,$sec2,'test-2'));
$engine=new Compatibility();$env=['mautic'=>'6.0.9','php'=>PHP_VERSION,'extensions'=>['json'],'packages'=>['doctrine/dbal'=>'3.7.3','doctrine/orm'=>'2.18.0','symfony/framework-bundle'=>'6.4.13']];
$r=json_decode(json_encode($r),true);
check($engine->evaluate($p,$r,$env)==='certified','exact tested tuple certified');
$unknown=$env;$unknown['mautic']='6.0.8';check($engine->evaluate($p,$r,$unknown)==='incompatible','unsupported minor refused by declaration');
$unknown=$env;$unknown['php']='8.2.26';check($engine->evaluate($p,$r,$unknown)==='unverified','untested PHP patch not certified');
$unknown=$env;$unknown['packages']['doctrine/dbal']='3.7.4';check($engine->evaluate($p,$r,$unknown)==='unverified','dependency drift not certified');
$blocked=$p;$blocked['blocked_versions']=['1.2.3'];check($engine->evaluate($blocked,$r,$env)==='incompatible','blocked version never certified');
$r2=$r;$r2['rollout']['state']='suspended';check(!Compatibility::eligible('instance','plugin',$r2),'suspension prevents automatic rollout');
$r3=$r;$r3['rollout']['percentage']=100;$r3['rollout']['opens_at']=time()-3600;$r3['rollout']['expand_after']=time()-1;
check(!Compatibility::eligible('instance','plugin',$r3),'full rollout requires two observation days');
check(Compatibility::patch('1.2.3','1.2.4'),'patch accepted');foreach ([['1.2.3','1.3.0'],['1.2.3','2.0.0'],['1.2.3','1.2.3'],['1.2.3','1.2.4-beta'],['1.2.3','1.2.2']] as $v) { check(!Compatibility::patch(...$v),'non-patch refused'); }
$counts=[0,0,0];
for ($i=0;$i<10000;++$i) { $a=Compatibility::bucket('instance-'.$i,'plugin','1.2.3','campaign');check($a>=0 && $a<=9999,'bucket bounds');check($a===Compatibility::bucket('instance-'.$i,'plugin','1.2.3','campaign'),'bucket stable');$counts[0]+=$a<500;$counts[1]+=$a<2500;$counts[2]+=$a<10000; }
check($counts[0]>350 && $counts[0]<650 && $counts[1]>2200 && $counts[1]<2800 && $counts[2]===10000,'rollout distribution');
$layout=$dir.'/recommended';mkdir($layout.'/docroot',0700,true);mkdir($layout.'/vendor',0700);mkdir($layout.'/bin',0700);
file_put_contents($layout.'/composer.json',json_encode(['require'=>['mautic/core-lib'=>'6.0.9']]));file_put_contents($layout.'/composer.lock','{}');file_put_contents($layout.'/bin/console','<?php');
$discovery=new Environment($layout.'/docroot',[]);check($discovery->project===realpath($layout),'recommended-project Composer root discovery');check($discovery->console()===realpath($layout.'/bin/console'),'recommended-project console is outside docroot');
$q=new Queue($state);$key=str_repeat('a',20);$job=$q->enqueue('plugin','1.2.3','install',$key,1,'api');check($job['id']===$q->enqueue('plugin','1.2.3','install',$key,1,'api')['id'],'idempotent request');
rejects(function() use ($q,$key) { $q->enqueue('plugin','1.2.4','install',$key,1,'api'); },'idempotency payload conflict');
rejects(function() use ($q) { $q->enqueue('plugin','1.2.3','install',str_repeat('b',20),1,'api'); },'same plugin concurrent request blocked');
check($q->claim()['id']===$job['id'],'claim persists running state');$q->phase($job['id'],'composer_install',true);check($q->claim()===null,'interrupted mutation stops queue');check($state->read()['jobs'][$job['id']]['status']==='recovery_required','interrupted mutation requires recovery');
$job2=$q->enqueue('other','1.0.0','install',str_repeat('c',20),1,'api');check($q->claim()===null,'incident stops other jobs');
$state->change(static function(&$s) use ($job) { $s['jobs'][$job['id']]['status']='restored'; });check($q->claim()['id']===$job2['id'],'manual recovery unblocks queue');$q->finish($job2['id'],'resolution_failed');check($state->read()['jobs'][$job2['id']]['status']==='failed','pre-mutation error never falsely succeeds');
$target=['name'=>$p['package'],'version'=>'1.2.3','type'=>'mautic-plugin','extra'=>['install-directory-name'=>$p['bundle']],'dist'=>['url'=>$r['artifact']['url'],'reference'=>$r['artifact']['reference']]];
$before=['packages'=>[['name'=>'mautic/core-lib','version'=>'6.0.9']]];$after=['packages'=>array_merge($before['packages'],[$target])];Deployment::assertPlan($before,$after,$p,$r);check(true,'isolated exact target plan accepted');
$bad=$after;$bad['packages'][0]['version']='7.0.0';rejects(function() use ($before,$bad,$p,$r) { Deployment::assertPlan($before,$bad,$p,$r); },'core update rejected');
$bad=$after;$bad['packages'][]=['name'=>'evil/package','version'=>'1.0.0'];rejects(function() use ($before,$bad,$p,$r) { Deployment::assertPlan($before,$bad,$p,$r); },'unrelated dependency addition rejected');
$bad=$after;$bad['packages'][1]['dist']['reference']=str_repeat('f',40);rejects(function() use ($before,$bad,$p,$r) { Deployment::assertPlan($before,$bad,$p,$r); },'artifact reference drift rejected');
$telemetry=new Telemetry($state,new Environment(__DIR__,[]),new Https());
$state->change(static function(&$s) { Telemetry::append($s,'plugin_installed',['plugin'=>'plugin','email'=>'secret@example.com'],'ui'); });check(!$state->read()['events'],'no event before consent');
$telemetry->consent(true,1);$telemetry->inventory([['id'=>'plugin','version'=>'1.2.3','health'=>'unknown']]);$state->change(static function(&$s) { Telemetry::append($s,'plugin_updated',['plugin'=>'plugin','version'=>'1.2.4','email'=>'secret@example.com','domain'=>'private.invalid','logs'=>'password=secret'],'api'); });
$events=$state->read()['events'];check(count($events)===4,'registration, detection, inventory and update are separate');check(strpos(json_encode($events),'secret')===false && strpos(json_encode($events),'private.invalid')===false,'telemetry field allowlist');
$telemetry->consent(false,1);check(!$state->read()['events'],'revocation purges queued events');
foreach (['http://github.com/x','https://localhost/x','https://github.com:444/x','https://user:pass@github.com/x','https://github.com.evil.invalid/x'] as $url) { rejects(static function() use ($url) { Https::url($url,['github.com']); },'unapproved URL refused'); }
$state->limit('mail',300);rejects(function() use ($state) { $state->limit('mail',300); },'rate limiting persistent');
check((new State($dir))->read()['installation_id']===$state->read()['installation_id'],'stable identifier across processes');
check((fileperms($dir.'/state.json')&0777)===0600,'private state file');
echo json_encode(['suite'=>'unit-security','php'=>PHP_VERSION,'assertions'=>$n,'rollout_sample'=>$counts,'status'=>'passed'],JSON_PRETTY_PRINT)."\n";
