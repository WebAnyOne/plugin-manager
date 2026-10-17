<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Adapter;
interface AdapterInterface
{
    public function template(): string;
    public function controller(string $method): string;
    public function icon(): string;
}
