<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Adapter;
final class Mautic6 implements AdapterInterface
{
    public function template(): string { return '@WebAnyOnePluginManager/Manager/index.html.twig'; }
    public function controller(string $method): string { return \MauticPlugin\WebAnyOnePluginManagerBundle\Controller\ManagerController::class.'::'.$method.'Action'; }
    public function icon(): string { return 'ri-puzzle-line'; }
}
