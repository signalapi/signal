<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves the request locale: session choice first (set by the switcher),
 * then the logged-in user's saved preference, then the browser's
 * Accept-Language — defaulting to English.
 *
 * Priority 6 runs after the firewall (priority 8) so the user is known. By
 * then Symfony's own LocaleAwareListener has already pinned the translator to
 * the default locale, so we push the resolved locale into it ourselves.
 */
#[AsEventListener(event: RequestEvent::class, priority: 6)]
final class LocaleListener
{
    public const SESSION_KEY = '_locale';
    public const LOCALES = ['en', 'tr'];

    public function __construct(
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();

        $request->setLocale($this->resolve($request));
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($request->getLocale());
        }
    }

    private function resolve(\Symfony\Component\HttpFoundation\Request $request): string
    {
        if ($request->hasPreviousSession()) {
            $chosen = $request->getSession()->get(self::SESSION_KEY);
            if (\in_array($chosen, self::LOCALES, true)) {
                return $chosen;
            }
        }

        $user = $this->security->getUser();
        if ($user instanceof User && \in_array($user->getLocale(), self::LOCALES, true)) {
            return $user->getLocale();
        }

        return $request->getPreferredLanguage(self::LOCALES) ?? 'en';
    }
}
