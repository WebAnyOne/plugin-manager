<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Helper\UserHelper;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
final class Access
{
    private $security;private $users;private $tokens;
    public function __construct(CorePermissions $security,UserHelper $users,$tokens) { $this->security=$security;$this->users=$users;$this->tokens=$tokens; }
    public function can(string $action): bool { return (bool)$this->security->isGranted('webanyonepluginmanager:manager:'.$action); }
    public function require(string $action='view',bool $api=false): void
    {
        if (!$this->can('view') || !$this->can($action)) { throw new AccessDeniedException(); }
        if ($api) {
            $t=$this->tokens->getToken();
            if (!$t instanceof \FOS\OAuthServerBundle\Security\Authentication\Token\OAuthToken || (method_exists($t,'isAuthenticated') && !$t->isAuthenticated()) || !is_object($t->getUser())) { throw new AccessDeniedException('OAuth2 required'); }
        }
    }
    public function userId(): int { return (int)$this->users->getUser()->getId(); }
    public function admin(): bool { return $this->users->getUser()->isAdmin(); }
}
