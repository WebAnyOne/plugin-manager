<?php
// Run only against a newly provisioned disposable recommended-project.
require __DIR__.'/bootstrap.php';
use Mautic\ApiBundle\Entity\oAuth2\Client;
use Mautic\ApiBundle\Entity\oAuth2\AccessToken;
use Symfony\Component\HttpFoundation\Request;
try {
$operation=$argv[2] ?? '';$scratch=$argv[3] ?? '';
if (strpos($lab,'/private/tmp/wao-manager-resolution/')!==0 && strpos($lab,'/tmp/wao-manager-resolution/')!==0) { throw new RuntimeException('Fresh Manager laboratory required'); }
if (strpos($scratch,'/tmp/pm-http-fixtures/')!==0) { throw new RuntimeException('Private test scratch path required'); }
$local=$c->get('mautic.helper.paths')->getLocalConfigurationFile();
if ($operation==='seed') {
 if (!is_dir($scratch)) { mkdir($scratch,0700,true); }chmod($scratch,0700);
 if (is_file($scratch.'/fixture.json')) { throw new RuntimeException('Clean up existing test fixtures first'); }
 copy($local,$scratch.'/original-local.php');chmod($scratch.'/original-local.php',0600);
 $parameters=[];include $local;$parameters['api_enabled']=true;$parameters['api_enable_basic_auth']=false;
 file_put_contents($local,"<?php\n\$parameters = ".var_export($parameters,true).";\n");chmod($local,0600);
 $admin=$em->getRepository(Mautic\UserBundle\Entity\User::class)->findOneBy(['username'=>'manager-admin']);
 if (!$admin || !$admin->isAdmin()) { throw new RuntimeException('Disposable admin required'); }
 $role=new Mautic\UserBundle\Entity\Role();$role->setName('Manager HTTP test role');$role->setIsAdmin(false);$em->persist($role);
 $limited=new Mautic\UserBundle\Entity\User();$limited->setUsername('wao-http-'.bin2hex(random_bytes(4)));$limited->setFirstName('Disposable');$limited->setLastName('API test');$limited->setEmail('fixture@example.invalid');$limited->setPassword(password_hash(bin2hex(random_bytes(24)),PASSWORD_BCRYPT));$limited->setRole($role);$em->persist($limited);$em->flush();
 $fixture=['clients'=>[],'tokens'=>[],'limited_user'=>$limited->getId(),'limited_role'=>$role->getId(),'local'=>$local];
 foreach (['admin'=>$admin,'limited'=>$limited] as $kind=>$user) {
  $client=new Client();$client->setName('Disposable Manager HTTP '.$kind);$client->setRedirectUris(['https://example.invalid/callback']);if (method_exists($client,'setRole')) { $client->setRole($user->getRole()); }$client->addUser($user);$em->persist($client);
  $token=new AccessToken();$value=bin2hex(random_bytes(32));$token->setToken($value);$token->setClient($client);$token->setUser($user);$token->setExpiresAt(time()+900);$em->persist($token);$em->flush();$fixture['clients'][]=$client->getId();$fixture['tokens'][$kind]=$value;
 }
 file_put_contents($scratch.'/fixture.json',json_encode($fixture,JSON_THROW_ON_ERROR));chmod($scratch.'/fixture.json',0600);
 echo "{\"status\":\"seeded\"}\n";exit;
}
$fixture=json_decode(file_get_contents($scratch.'/fixture.json'),true,16,JSON_THROW_ON_ERROR);
if ($operation==='cleanup') {
 foreach ($fixture['tokens'] as $value) { $token=$em->getRepository(AccessToken::class)->findOneBy(['token'=>$value]);if ($token) { $em->remove($token); } }$em->flush();
 foreach ($fixture['clients'] as $id) { $client=$em->find(Client::class,$id);if ($client) { $em->remove($client); } }$em->flush();
 $user=$em->find(Mautic\UserBundle\Entity\User::class,$fixture['limited_user']);if ($user) { $em->remove($user);$em->flush(); }
 $role=$em->find(Mautic\UserBundle\Entity\Role::class,$fixture['limited_role']);if ($role) { $em->remove($role);$em->flush(); }
 copy($scratch.'/original-local.php',$fixture['local']);chmod($fixture['local'],0600);unlink($scratch.'/fixture.json');unlink($scratch.'/original-local.php');echo "{\"status\":\"cleaned\"}\n";exit;
}
$kind=$argv[4] ?? 'anonymous';$path=$argv[5] ?? '/api/webanyone/plugins/catalogue';$method=$argv[6] ?? 'GET';$body=$argv[7] ?? '';
if (strpos($path,'/api/webanyone/plugins/')!==0) { throw new RuntimeException('Only Manager routes'); }
$server=['HTTPS'=>'on','HTTP_HOST'=>'manager-lab.example.invalid','CONTENT_TYPE'=>'application/json','SCRIPT_NAME'=>'/index.php','SCRIPT_FILENAME'=>$lab.'/index.php'];
if (isset($fixture['tokens'][$kind])) { $server['HTTP_AUTHORIZATION']='Bearer '.$fixture['tokens'][$kind]; }
if ($kind==='invalid') { $server['HTTP_AUTHORIZATION']='Bearer invalid-disposable-token'; }
$request=Request::create('https://manager-lab.example.invalid'.$path,$method,[],[],[],$server,$body);
$response=$kernel->handle($request);$data=json_decode($response->getContent(),true);
echo json_encode(['status'=>$response->getStatusCode(),'is_json'=>is_array($data),'has_plugins'=>isset($data['plugins']),'error'=>$data['error'] ?? null],JSON_THROW_ON_ERROR)."\n";
$kernel->terminate($request,$response);
} catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1); }
