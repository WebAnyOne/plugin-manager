<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
interface HealthProbeInterface
{
    /** Must inspect functionality and migrations without mutating data or returning secrets. */
    public function healthy(): bool;
}
