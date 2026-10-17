<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Security\Permissions;
use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;
final class WebAnyOnePluginManagerPermissions extends AbstractPermissions
{
    public function __construct($params) { parent::__construct($params);$this->permissions['manager']=['view'=>1,'install'=>2,'update'=>4,'configure'=>8,'full'=>1024]; }
    public function getName(): string { return 'webanyonepluginmanager'; }
    public function buildForm(FormBuilderInterface &$builder,array $options,array $data): void
    {
        $choices=[];foreach (['view','install','update','configure','full'] as $level) { $choices['wao.manager.permission.'.$level]=$level; }
        $builder->add('webanyonepluginmanager:manager',\Mautic\UserBundle\Form\Type\PermissionListType::class,['choices'=>$choices,'label'=>'wao.manager.title','data'=>$data['manager'] ?? [],'bundle'=>'webanyonepluginmanager','level'=>'manager']);
    }
}
