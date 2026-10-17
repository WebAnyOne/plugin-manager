<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\DependencyInjection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use MauticPlugin\WebAnyOnePluginManagerBundle\Controller;
use MauticPlugin\WebAnyOnePluginManagerBundle\Console;
final class WebAnyOnePluginManagerExtension extends Extension
{
    public function load(array $configs,ContainerBuilder $container): void
    {
        foreach (['queue','verify','sync','recover'] as $operation) {
            $container->register('wao.manager.command.'.$operation,Console\ManagerCommand::class)->setPublic(true)->setArguments([new Reference('wao.manager'),$operation])->addTag('console.command');
        }
        if (defined('MAUTIC_VERSION') && version_compare(MAUTIC_VERSION,'5.0','<')) { return; }
        foreach ([Controller\ManagerController::class,Controller\ApiController::class] as $class) {
            $container->register($class,$class)->setAutowired(true)->setAutoconfigured(true)->setPublic(true)->addTag('controller.service_arguments')->addMethodCall('setManager',[new Reference('wao.manager')]);
        }
    }
}
