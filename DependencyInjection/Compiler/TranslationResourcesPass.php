<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\DependencyInjection\Compiler;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
final class TranslationResourcesPass implements CompilerPassInterface
{
    private $path;
    public function __construct(string $path) { $this->path = $path; }
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('translator.default')) { return; }
        foreach (['en_US', 'fr_FR', 'fr'] as $locale) {
            $file = $this->path.'/Translations/'.$locale.'/messages.ini';
            $container->getDefinition('translator.default')->addMethodCall('addResource', ['ini', $file, $locale, 'messages']);
            $container->addResource(new FileResource($file));
        }
    }
}
