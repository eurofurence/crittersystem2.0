<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\PrivilegeCatalog;
use App\TwoFactor\StepUpManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Enforces step-up (2FA) re-authentication on exactly the permissions flagged
 * `twoFactor: true` in {@see PrivilegeCatalog} (audit, SSO config, group RBAC,
 * admin promotion, PII, ...).
 *
 * When the matched controller declares one of these permissions via #[IsGranted]
 * and the current user actually holds it, they must have 2FA enabled and a fresh
 * step-up before the controller runs - otherwise they are redirected to enrol or
 * re-confirm. Users who do not hold the permission are left to the normal
 * #[IsGranted] denial (403).
 *
 * This is deliberately scoped to the critical permissions rather than a blanket
 * "enrol before anything" gate, so ROLE_ADMIN can reach the rest of the
 * application without friction.
 */
final class TwoFactorStepUpSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly StepUpManager $stepUp,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        foreach ($this->twoFactorAttributes($event->getController()) as $permission) {
            if (!$this->authChecker->isGranted($permission)) {
                continue;
            }

            if (!$user->isTwoFactorEnabled()) {
                $event->setController(fn (): RedirectResponse => new RedirectResponse(
                    $this->urlGenerator->generate('app_2fa_setup'),
                ));

                return;
            }

            if (!$this->stepUp->isFresh()) {
                $return = $event->getRequest()->getRequestUri();
                $event->setController(fn (): RedirectResponse => new RedirectResponse(
                    $this->urlGenerator->generate('app_2fa_confirm', ['return' => $return]),
                ));

                return;
            }

            return;
        }
    }

    /**
     * Distinct permission names required by the controller (class- and
     * method-level #[IsGranted]) that demand step-up.
     *
     * An empty result means "this controller needs no step-up", so a reflection failure fails
     * *open* on a security gate. Symfony has already resolved the controller, so it should be
     * impossible, but it must not happen quietly.
     *
     * @return string[]
     */
    private function twoFactorAttributes(mixed $controller): array
    {
        try {
            if (\is_array($controller)) {
                [$object, $method] = $controller;
                $reflections = [new \ReflectionMethod($object, $method), new \ReflectionClass($object)];
            } elseif (\is_object($controller) && method_exists($controller, '__invoke')) {
                $reflections = [new \ReflectionMethod($controller, '__invoke'), new \ReflectionClass($controller)];
            } else {
                return [];
            }
        } catch (\ReflectionException $e) {
            $this->logger->error('Could not read step-up attributes; treating the controller as unprotected: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }

        $permissions = [];
        foreach ($reflections as $reflection) {
            foreach ($reflection->getAttributes(IsGranted::class) as $attribute) {
                $value = $attribute->newInstance()->attribute;
                if (\is_string($value) && PrivilegeCatalog::requiresTwoFactor($value)) {
                    $permissions[$value] = true;
                }
            }
        }

        return array_keys($permissions);
    }
}
