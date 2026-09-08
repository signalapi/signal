<?php

namespace App\Controller\Admin;

use App\Service\AiDiagnoser;
use App\Service\PlatformSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Platform settings — currently the AI analysis section: the Anthropic API key
 * (sealed at rest, only a hint is ever rendered back) and the model. The
 * ANTHROPIC_API_KEY environment variable keeps working as a fallback so
 * nothing changes for installs configured from .env.
 */
#[Route('/admin/settings')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_settings', methods: ['GET'])]
    public function index(
        AiDiagnoser $ai,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] string $envKey = '',
    ): Response {
        return $this->render('admin/settings.html.twig', [
            'keySource' => $ai->keySource(),
            'keyHint' => $this->settings->hint(PlatformSettings::AI_API_KEY),
            'envKeySet' => '' !== trim($envKey),
            'model' => $this->settings->get(PlatformSettings::AI_MODEL),
            'activeModel' => $ai->activeModel(),
        ]);
    }

    #[Route('/ai', name: 'admin_settings_ai', methods: ['POST'])]
    public function saveAi(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('settings-ai', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $key = trim((string) $request->request->get('api_key'));
        if ('' !== $key) {
            if (\strlen($key) < 20 || preg_match('/\s/', $key)) {
                $this->addFlash('error', $this->translator->trans('That does not look like an API key.'));

                return $this->redirectToRoute('admin_settings');
            }
            $hint = mb_substr($key, 0, 7) . '…' . mb_substr($key, -4);
            $this->settings->setSecret(PlatformSettings::AI_API_KEY, $key, $hint);
        }

        $this->settings->set(PlatformSettings::AI_MODEL, (string) $request->request->get('model'));

        $this->addFlash('success', '' !== $key
            ? $this->translator->trans('API key saved (sealed). AI analysis is now available on run, suite and trend pages.')
            : $this->translator->trans('Settings saved.'));

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/ai/test', name: 'admin_settings_ai_test', methods: ['POST'])]
    public function testAi(Request $request, AiDiagnoser $ai): Response
    {
        if (!$this->isCsrfTokenValid('settings-ai-test', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$ai->isConfigured()) {
            $this->addFlash('error', $this->translator->trans('No API key is set yet — save one first.'));

            return $this->redirectToRoute('admin_settings');
        }

        try {
            $ai->ping();
            $this->addFlash('success', $this->translator->trans('Claude responded — the key works (model: %model%).', ['%model%' => $ai->activeModel()]));
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->translator->trans('Test failed: %error%', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/ai/remove', name: 'admin_settings_ai_remove', methods: ['POST'])]
    public function removeAiKey(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('settings-ai-remove', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->settings->remove(PlatformSettings::AI_API_KEY);
        $this->addFlash('success', $this->translator->trans('Panel API key removed. The ANTHROPIC_API_KEY environment variable, if set, takes over.'));

        return $this->redirectToRoute('admin_settings');
    }
}
