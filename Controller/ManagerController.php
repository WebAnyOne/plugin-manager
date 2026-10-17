<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Controller;
use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Manager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
final class ManagerController extends CommonController
{
    private $manager;
    public function setManager(Manager $manager): void { $this->manager=$manager; }
    private function m(): Manager { return $this->manager ?: $this->container->get('wao.manager'); }
    private function csrf(Request $r): bool { return $this->container->get('security.csrf.token_manager')->isTokenValid(new CsrfToken('wao.manager',(string)$r->request->get('_token'))); }
    private function renderPage(Request $request,string $tab,?array $preview=null,?string $notice=null): Response
    {
        $m=$this->m();$state=$m->state->read();$catalogue=$m->catalogue->view();$env=$m->env->versions();$diagnostic=$m->env->diagnose($m->state);$inventory=$m->inventory();
        $token=$this->container->get('security.csrf.token_manager')->getToken('wao.manager')->getValue();
        $e=static function($v) { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); };
        $t=function($key) { return $this->translator->trans('wao.manager.'.$key); };
        $url=function($route,array $p=[]) { return $this->generateUrl($route,$p); };
        $locale=strpos($request->getLocale(),'fr')===0 ? 'fr' : 'en';
        ob_start();require __DIR__.'/../Resources/manager.php';$body=ob_get_clean();
        $response=$this->delegateView(['contentTemplate'=>$m->adapter()->template(),'viewParameters'=>['body'=>$body],'passthroughVars'=>['mauticContent'=>'waoPluginManager','route'=>$url('wao_manager_index',['tab'=>$tab])]]);
        $response->headers->set('Cache-Control','private, no-store');return $response;
    }
    public function indexAction(Request $request): Response
    {
        $m=$this->m();$m->access->require();$tab=(string)$request->query->get('tab',($m->access->admin() && $m->state->read()['wizard']<2) ? 'wizard' : 'catalogue');
        if (!in_array($tab,['catalogue','installed','history','diagnostic','settings','support','suggestion','newsletter','feedback','wizard'],true)) { throw $this->createNotFoundException(); }
        $preview=$request->hasSession() ? $request->getSession()->get('wao.manager.preview') : null;
        return $this->renderPage($request,$tab,$preview);
    }
    public function actionAction(Request $request): Response
    {
        $m=$this->m();$m->access->require();if (!$this->csrf($request)) { return new Response('',403); }
        $action=(string)$request->request->get('action');$notice='saved';$tab='catalogue';
        try {
            if (in_array($action,['install','update'],true)) {
                $m->access->require($action);
                $m->request(['plugin'=>(string)$request->request->get('plugin'),'version'=>(string)$request->request->get('version'),'operation'=>$action,'idempotency_key'=>(string)$request->request->get('idempotency_key')],$m->access->userId(),'ui');$tab='history';$notice='queued';
            } elseif ($action==='settings') {
                $m->access->require('configure');$data=['auto_patch'=>$request->request->get('auto_patch')==='1','notifications'=>$request->request->get('notifications')==='1','check_hours'=>$request->request->getInt('check_hours',24)];
                $consent=$request->request->get('consent');if (in_array($consent,['yes','no'],true)) { $data['consent']=$consent==='yes'; }
                $m->settings($data,$m->access->userId());$tab='settings';
            } elseif ($action==='wizard_consent') {
                $m->access->require('configure');$c=$request->request->get('consent');
                if (!in_array($c,['yes','no','skip'],true)) { throw new \InvalidArgumentException('consent_required'); }
                if ($c!=='skip') { $m->telemetry->consent($c==='yes',$m->access->userId()); }
                $m->state->change(static function (&$s) { $s['wizard']=1; });$tab='wizard';
            } elseif ($action==='wizard_finish' || $action==='wizard_reopen') {
                $m->access->require('configure');$m->state->change(static function (&$s) use ($action) { $s['wizard']=$action==='wizard_finish' ? 2 : 0; });$tab=$action==='wizard_finish' ? 'catalogue' : 'wizard';
            } else { throw new \InvalidArgumentException('unknown_action'); }
        } catch (\InvalidArgumentException $e) { $notice='invalid'; }
        catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) { throw $e; }
        catch (\RuntimeException $e) { $notice='operation_blocked'; }
        return $this->renderPage($request,$tab,null,$notice);
    }
    public function communityAction(Request $request): Response
    {
        $m=$this->m();$m->access->require();if (!$this->csrf($request)) { return new Response('',403); }
        $community=$this->container->get('wao.manager.community');$notice=null;$preview=null;
        try {
            if ($request->request->get('confirm')==='1') {
                $preview=$request->getSession()->get('wao.manager.preview');
                if (!$preview || ($preview['_expires'] ?? 0)<time()) { throw new \InvalidArgumentException('preview_expired'); }
                unset($preview['_expires']);$ok=$community->send($preview,$m->access->userId());
                $notice=$ok ? 'sent' : 'mail_failed';
                if ($ok) { $request->getSession()->remove('wao.manager.preview');$preview=null; }
            } else {
                $preview=$community->preview($request->request->all());$preview['_expires']=time()+900;$request->getSession()->set('wao.manager.preview',$preview);
            }
        } catch (\InvalidArgumentException $e) { $notice='invalid'; }
        catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) { throw $e; }
        catch (\RuntimeException $e) { $notice=$e->getMessage()==='rate_limited' ? 'rate_limited' : 'mail_failed'; }
        return $this->renderPage($request,'support',$preview,$notice);
    }
    public function downloadAction(): Response
    {
        $m=$this->m();$m->access->require();return new Response(json_encode($m->diagnosticExport(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json','Content-Disposition'=>'attachment; filename="webanyone-diagnostic.json"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
