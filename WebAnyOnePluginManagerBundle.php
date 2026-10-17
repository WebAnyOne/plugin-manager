<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle;
use Mautic\PluginBundle\Bundle\PluginBundleBase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
final class WebAnyOnePluginManagerBundle extends PluginBundleBase
{
    public const MINIMUM_MAUTIC_VERSION='4.4.13';
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);$container->addCompilerPass(new DependencyInjection\Compiler\TranslationResourcesPass(__DIR__));
    }
}
