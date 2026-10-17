<?php
use MauticPlugin\WebAnyOnePluginManagerBundle\Service;
use MauticPlugin\WebAnyOnePluginManagerBundle\Controller;
$legacy=defined('MAUTIC_VERSION') && version_compare(MAUTIC_VERSION,'5.0','<');
$ui=static function($method) use ($legacy) { return $legacy ? 'WebAnyOnePluginManagerBundle:Manager:'.$method : Controller\ManagerController::class.'::'.$method.'Action'; };
$api=static function($method) use ($legacy) { return $legacy ? 'WebAnyOnePluginManagerBundle:Api:'.$method : Controller\ApiController::class.'::'.$method.'Action'; };
return [
    'name'=>'WebAnyOne Plugin Manager','description'=>'Certified plugin catalogue and asynchronous Composer operations.','version'=>'0.1.0-dev','author'=>'WebAnyOne',
    'routes'=>[
        'main'=>[
            'wao_manager_index'=>['path'=>'/webanyone/plugins','controller'=>$ui('index'),'method'=>'GET'],
            'wao_manager_action'=>['path'=>'/webanyone/plugins/action','controller'=>$ui('action'),'method'=>'POST'],
            'wao_manager_community'=>['path'=>'/webanyone/plugins/community','controller'=>$ui('community'),'method'=>'POST'],
            'wao_manager_download'=>['path'=>'/webanyone/plugins/diagnostic.json','controller'=>$ui('download'),'method'=>'GET'],
        ],
        'api'=>[
            'wao_manager_api_catalogue'=>['path'=>'/webanyone/plugins/catalogue','controller'=>$api('catalogue'),'method'=>'GET'],
            'wao_manager_api_inventory'=>['path'=>'/webanyone/plugins/installed','controller'=>$api('inventory'),'method'=>'GET'],
            'wao_manager_api_jobs'=>['path'=>'/webanyone/plugins/jobs','controller'=>$api('jobs'),'method'=>'GET|POST'],
            'wao_manager_api_job'=>['path'=>'/webanyone/plugins/jobs/{id}','controller'=>$api('job'),'method'=>'GET','requirements'=>['id'=>'[a-f0-9]{32}']],
            'wao_manager_api_diagnostic'=>['path'=>'/webanyone/plugins/diagnostic','controller'=>$api('diagnostic'),'method'=>'GET'],
            'wao_manager_api_settings'=>['path'=>'/webanyone/plugins/settings','controller'=>$api('settings'),'method'=>'GET|PATCH'],
        ],
    ],
    'menu'=>['main'=>['wao.manager.title'=>['route'=>'wao_manager_index','access'=>'webanyonepluginmanager:manager:view','iconClass'=>$legacy ? 'fa-puzzle-piece' : 'ri-puzzle-line','priority'=>20]]],
    'services'=>['other'=>[
        'wao.manager.access'=>['class'=>Service\Access::class,'arguments'=>['mautic.security','mautic.helper.user','security.token_storage']],
        'wao.manager'=>['class'=>Service\Manager::class,'arguments'=>['mautic.helper.paths','mautic.helper.core_parameters','doctrine.orm.entity_manager','wao.manager.access','kernel']],
        'wao.manager.community'=>['class'=>Service\Community::class,'arguments'=>['wao.manager','mautic.helper.mailer']],
    ]],
    'parameters'=>[
        'wao_plugin_manager_storage'=>'','wao_plugin_manager_catalogue_url'=>'https://raw.githubusercontent.com/WebAnyOne/plugin-manager/main/catalogue/manifest.json',
        'wao_plugin_manager_trusted_keys'=>json_decode(file_get_contents(__DIR__.'/../catalogue/trusted-keys.json'),true),
        'wao_plugin_manager_telemetry_url'=>'','wao_plugin_manager_restore_provider'=>'','wao_plugin_manager_composer'=>'',
    ],
];
