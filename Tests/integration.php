<?php
require __DIR__.'/bootstrap.php';
use MauticPlugin\WebAnyOnePluginManagerBundle\Controller\ManagerController;
use MauticPlugin\WebAnyOnePluginManagerBundle\Controller\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
try {
$user=null;foreach ($em->getRepository(Mautic\UserBundle\Entity\User::class)->findAll() as $candidate) { if ($candidate->isAdmin()) { $user=$candidate;break; } }
check($user!==null,'Native admin available');loginAs($user);
$m=$c->get('wao.manager');check($m instanceof MauticPlugin\WebAnyOnePluginManagerBundle\Service\Manager,'Native DI resolves');
check($m->env->versions()['mautic']===MAUTIC_VERSION,'Runtime version detection');
check($m->access->can('install') && $m->access->can('configure'),'Admin permission discovery');
$permissions=$c->get('mautic.security')->getPermissionObject('webanyonepluginmanager');check($permissions && $permissions->isSupported('manager','update'),'Native role permission');
foreach (['wao_manager_index','wao_manager_action','wao_manager_community','wao_manager_download','wao_manager_api_catalogue','wao_manager_api_inventory','wao_manager_api_jobs','wao_manager_api_job','wao_manager_api_diagnostic','wao_manager_api_settings'] as $route) { check($c->get('router')->getRouteCollection()->get($route)!==null,'Native route '.$route); }
$model=$c->get('mautic.plugin.model.plugin');$configs=$model->getAllPluginsConfig();check(isset($configs['WebAnyOnePluginManagerBundle']),'Bundle configuration discovered');
$added=$c->get('mautic.plugin.helper.reload')->installPlugins(['WebAnyOnePluginManagerBundle'=>$configs['WebAnyOnePluginManagerBundle']],$model->getInstalledPlugins(),[],[]);foreach($added as $entity){$em->persist($entity);}$em->flush();
check($em->getRepository(Mautic\PluginBundle\Entity\Plugin::class)->findOneBy(['bundle'=>'WebAnyOnePluginManagerBundle'])!==null,'Native plugin installation recorded');
$session=new Session(new MockArraySessionStorage());$request=Request::create('/s/webanyone/plugins');$request->setSession($session);$c->get('request_stack')->push($request);
if ($c->has('session')) { $session=$c->get('session');$request->setSession($session); }
function controller($class) {
 global $c,$user,$request;
 if (version_compare(MAUTIC_VERSION,'5.0','>=')) { return $c->get($class); }
 $obj=new $class();$obj->setContainer($c);$obj->setFactory($c->get('mautic.factory'));$obj->setRequest($request);$obj->setTranslator($c->get('translator'));$obj->setCoreParametersHelper($c->get('mautic.helper.core_parameters'));$obj->setDispatcher($c->get('event_dispatcher'));$obj->setUser($user);return $obj;
}
$ui=controller(ManagerController::class);$api=controller(ApiController::class);
$m->state->change(static function(&$s) { $s['wizard']=0;$s['consent']=false;$s['events']=[]; });
$rendered=[];
foreach (['fr_FR','en_US'] as $locale) {
 $request->setLocale($locale);$c->get('translator')->setLocale($locale);
 foreach (['wizard','catalogue','installed','history','diagnostic','settings','support','suggestion','newsletter','feedback'] as $tab) {
  $request->query->set('tab',$tab);$response=$ui->indexAction($request);$html=$response->getContent();
  check($response->getStatusCode()===200,'Native view '.$locale.' '.$tab);check(strpos($html,'wao.manager.')===false,'All view labels translated '.$locale.' '.$tab);
  check(strpos($html,'WebAnyOne Plugin Manager')!==false,'Native wrapper '.$tab);
  $out=dirname($kernel->getCacheDir()).'/'.$locale.'-'.$tab.'.html';file_put_contents($out,$html);$rendered[]=$out;
 }
}
$bad=Request::create('/s/webanyone/plugins/action','POST',['action'=>'settings','consent'=>'yes']);$bad->setSession($session);
check($ui->actionAction($bad)->getStatusCode()===403,'CSRF rejection');check(!$m->state->read()['consent'],'Rejected POST cannot grant telemetry');
$rp=new ReflectionObject($ui);while(!$rp->hasProperty('container')){$rp=$rp->getParentClass();}$pr=$rp->getProperty('container');$pr->setAccessible(true);$tokens=$pr->getValue($ui)->get('security.csrf.token_manager');
$token=$tokens->getToken('wao.manager')->getValue();
$first=Request::create('/s/webanyone/plugins/action','POST',['_token'=>$token,'action'=>'wizard_consent','consent'=>'no']);$first->setSession($session);$first->setLocale('fr_FR');$c->get('translator')->setLocale('fr_FR');
$html=$ui->actionAction($first)->getContent();check($m->state->read()['wizard']===1 && !$m->state->read()['consent'],'Explicit refusal moves to second wizard screen');check(strpos($html,'Recevez les nouveautés')!==false,'Newsletter second screen');
$second=Request::create('/s/webanyone/plugins/action','POST',['_token'=>$token,'action'=>'wizard_finish']);$second->setSession($session);$ui->actionAction($second);check($m->state->read()['wizard']===2,'Exactly two wizard screens completed');
$request->query->replace([]);check(strpos($ui->indexAction($request)->getContent(),'1 / 2')===false,'Wizard does not repeat');
check(!$m->state->read()['events'],'No telemetry before consent');
$diagnostic=$m->diagnosticExport();check($diagnostic['checks']['restore_verified']===false,'Unconfigured restore provider blocks installations');check($diagnostic['checks']['worker']===false,'No pretend worker before CLI launch');
check(strpos(json_encode($diagnostic),'installation_id')===false,'Support export excludes tracking identifier');
try { $api->catalogueAction();check(false,'Session authentication must fail API'); } catch (Symfony\Component\Security\Core\Exception\AccessDeniedException $e) { check(true,'Session cannot call OAuth API'); }
$oauth=new FOS\OAuthServerBundle\Security\Authentication\Token\OAuthToken($user->getRoles());$oauth->setUser($user);$oauth->setToken('disposable-laboratory-token');if (method_exists($oauth,'setAuthenticated')){$oauth->setAuthenticated(true);}$reflection=new ReflectionObject($m->access);$property=$reflection->getProperty('tokens');$property->setAccessible(true);$property->getValue($m->access)->setToken($oauth);
check($api->catalogueAction()->getStatusCode()===200,'OAuth token accepted');check(json_decode($api->catalogueAction()->getContent(),true)['plugins']===[],'Standard API excludes unverified releases');
$invalid=Request::create('/api/webanyone/plugins/jobs','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],'{"operation":"install","plugin":"test","version":"1.0.0","repository":"https://evil.invalid"}');check($api->jobsAction($invalid)->getStatusCode()===400,'API refuses arbitrary repository parameter');
$bad=Request::create('/api/webanyone/plugins/settings','PATCH',[],[],[],['CONTENT_TYPE'=>'application/json'],'{"consent":"yes"}');check($api->settingsAction($bad)->getStatusCode()===400,'API consent requires JSON boolean');
$null=Request::create('/api/webanyone/plugins/settings','PATCH',[],[],[],['CONTENT_TYPE'=>'application/json'],'{"consent":null}');check($api->settingsAction($null)->getStatusCode()===400,'Null consent rejected');
$empty=Request::create('/api/webanyone/plugins/settings','PATCH',[],[],[],['CONTENT_TYPE'=>'application/json'],'{}');check($api->settingsAction($empty)->getStatusCode()===200,'Empty object is a valid settings no-op');
$community=$c->get('wao.manager.community');$preview=$community->preview(['kind'=>'suggestion','description'=>'Disposable preview — do not send','email'=>'','category'=>'usability','diagnostic'=>'1']);
check($preview['to']==='contact@webanyone.net' && $preview['reply']==='','Optional suggestion email and fixed recipient');check(isset($preview['diagnostic']['checks']),'Exact sanitized diagnostic available before send');
$members=$em->getRepository(Mautic\UserBundle\Entity\User::class)->findAll();$nonAdmin=null;foreach ($members as $candidate) { if (!$candidate->isAdmin()) { $nonAdmin=$candidate;break; } }
$fixture=null;$fixtureRole=null;
if (!$nonAdmin) {
 $fixtureRole=new Mautic\UserBundle\Entity\Role();$fixtureRole->setName('Manager disposable test');$fixtureRole->setIsAdmin(false);$em->persist($fixtureRole);
 $fixture=new Mautic\UserBundle\Entity\User();$fixture->setUsername('wao-manager-test-'.bin2hex(random_bytes(4)));$fixture->setFirstName('Disposable');$fixture->setLastName('Fixture');$fixture->setEmail('fixture@example.invalid');$fixture->setPassword(password_hash(bin2hex(random_bytes(24)),PASSWORD_BCRYPT));$fixture->setRole($fixtureRole);$em->persist($fixture);$em->flush();$nonAdmin=$fixture;
}
loginAs($nonAdmin);check(!$m->access->can('install'),'No install permission granted to existing non-admin roles');
try { $ui->indexAction($request);check(false,'Non-admin view permission missing'); }catch(Symfony\Component\Security\Core\Exception\AccessDeniedException $e){check(true,'Server-side UI authorization');}
$application=new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);$all=$application->all();foreach (['wao:plugins:process-queue','wao:plugins:catalogue:sync','wao:plugins:verify','wao:plugins:recover'] as $name) { check(isset($all[$name]),'Native console registration '.$name); }
if ($fixture) { $em->remove($fixture);$em->flush();$em->remove($fixtureRole);$em->flush(); }
echo json_encode(['suite'=>'native-integration','mautic'=>MAUTIC_VERSION,'php'=>PHP_VERSION,'assertions'=>$assertions,'views'=>count($rendered),'composer_project'=>$m->env->project,'status'=>'passed'],JSON_PRETTY_PRINT)."\n";

} catch (\Throwable $e) { fwrite(STDERR,get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1); }
