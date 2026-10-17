<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Controller;
use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Manager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
final class ApiController extends CommonController
{
    private $manager;
    public function setManager(Manager $manager): void { $this->manager=$manager; }
    private function m(): Manager { return $this->manager ?: $this->container->get('wao.manager'); }
    private function response($data,int $status=200): JsonResponse { return new JsonResponse($data,$status,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']); }
    private function input(Request $r): array
    {
        if (strlen($r->getContent())>4096 || strpos((string)$r->headers->get('Content-Type'),'application/json')!==0) { throw new \InvalidArgumentException('json_required'); }
        $object=json_decode($r->getContent(),false,16,JSON_THROW_ON_ERROR);
        if (!is_object($object)) { throw new \InvalidArgumentException('object_required'); }
        return json_decode($r->getContent(),true,16,JSON_THROW_ON_ERROR);
    }
    public function catalogueAction(): JsonResponse
    {
        $m=$this->m();$m->access->require('view',true);$v=$m->catalogue->view();$plugins=[];
        if ($v['fresh']) { foreach ($v['manifest']['plugins'] as $p) { $p['releases']=array_values(array_filter($p['releases'],function($r) use ($m,$p) { return $m->compatibility->evaluate($p,$r,$m->env->versions())==='certified' && $r['rollout']['state']==='active'; }));if ($p['releases']) { $plugins[]=$p; } } }
        return $this->response(['plugins'=>$plugins,'fresh'=>$v['fresh'],'synced_at'=>$v['synced_at']]);
    }
    public function inventoryAction(): JsonResponse { $m=$this->m();$m->access->require('view',true);return $this->response(['plugins'=>$m->inventory()]); }
    public function jobsAction(Request $request): JsonResponse
    {
        $m=$this->m();$m->access->require('view',true);
        if ($request->isMethod('POST')) {
            try {
                $i=$this->input($request);$op=$i['operation'] ?? '';
                if (!in_array($op,['install','update'],true)) { throw new \InvalidArgumentException('invalid_operation'); }
                $m->access->require($op,true);$job=$m->request($i,$m->access->userId(),'api');
                return $this->response(['job'=>$this->publicJob($job)],202);
            } catch (\InvalidArgumentException $e) { return $this->response(['error'=>'invalid_request'],400); }
            catch (\JsonException $e) { return $this->response(['error'=>'invalid_json'],400); }
            catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) { throw $e; }
        catch (\RuntimeException $e) { return $this->response(['error'=>preg_match('/^[a-z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'operation_blocked'],409); }
        }
        $jobs=array_values($m->state->read()['jobs']);return $this->response(['jobs'=>array_map([$this,'publicJob'],array_slice(array_reverse($jobs),0,100))]);
    }
    public function publicJob(array $job): array { unset($job['key']);return $job; }
    public function jobAction(string $id): JsonResponse
    {
        $m=$this->m();$m->access->require('view',true);$j=$m->state->read()['jobs'][$id] ?? null;return $j ? $this->response(['job'=>$this->publicJob($j)]) : $this->response(['error'=>'not_found'],404);
    }
    public function diagnosticAction(): JsonResponse { $m=$this->m();$m->access->require('view',true);return $this->response($m->diagnosticExport()); }
    public function settingsAction(Request $request): JsonResponse
    {
        $m=$this->m();$m->access->require('configure',true);
        if ($request->isMethod('PATCH')) {
            try { $m->settings($this->input($request),$m->access->userId()); }
            catch (\InvalidArgumentException $e) { return $this->response(['error'=>'invalid_settings'],400); }
            catch (\JsonException $e) { return $this->response(['error'=>'invalid_json'],400); }
        }
        return $this->response(array_intersect_key($m->state->read(),array_flip(['consent','auto_patch','check_hours','notifications'])));
    }
}
