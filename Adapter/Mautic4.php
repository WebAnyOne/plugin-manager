<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Adapter;
final class Mautic4 implements AdapterInterface
{
    public function template(): string { return 'WebAnyOnePluginManagerBundle:Manager:index.html.php'; }
    public function controller(string $method): string { return 'WebAnyOnePluginManagerBundle:Manager:'.$method; }
    public function icon(): string { return 'fa-puzzle-piece'; }
}
