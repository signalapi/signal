<?php

namespace App\Controller\App;

use App\Entity\Environment;
use App\Entity\EnvVariable;
use App\Entity\Workspace;
use App\Repository\EnvironmentRepository;
use App\Service\EnvironmentResolver;
use App\Service\PostmanImporter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/workspaces/{workspace}/environments')]
#[IsGranted('ROLE_USER')]
class EnvironmentController extends AbstractAppController
{
    #[Route('', name: 'app_environment_index', methods: ['GET'])]
    public function index(Workspace $workspace, EnvironmentRepository $environments): Response
    {
        $this->assertWorkspace($workspace);

        return $this->render('app/environment/index.html.twig', [
            'workspace' => $workspace,
            'environments' => $environments->findByWorkspace($workspace),
        ]);
    }

    #[Route('/import', name: 'app_environment_import', methods: ['POST'])]
    public function import(Workspace $workspace, Request $request, PostmanImporter $importer, TranslatorInterface $translator): Response
    {
        $this->assertWorkspace($workspace, 'edit');

        if (!$this->isCsrfTokenValid('env-import', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('environment');
        if (null === $file) {
            $this->addFlash('error', $translator->trans('Select a Postman environment file.'));

            return $this->redirectToRoute('app_environment_index', ['workspace' => $workspace->getId()]);
        }

        try {
            $data = json_decode((string) file_get_contents($file->getPathname()), true);
            if (\JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
                throw new \InvalidArgumentException('Not valid JSON.');
            }
            $env = $importer->importEnvironment($data, $workspace);
            $this->addFlash('success', $translator->trans('Environment "%name%" imported.', ['%name%' => $env->getName()]));
        } catch (\Throwable $e) {
            $this->addFlash('error', $translator->trans('Import error: %error%', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('app_environment_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/new', name: 'app_environment_new', methods: ['POST'])]
    public function new(Workspace $workspace, Request $request, EnvironmentRepository $environments, TranslatorInterface $translator): Response
    {
        $this->assertWorkspace($workspace, 'edit');

        if (!$this->isCsrfTokenValid('new-environment', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $env = new Environment();
        $env->setWorkspace($workspace);
        $env->setName(trim((string) $request->request->get('name')) ?: $translator->trans('New environment'));
        $environments->save($env);
        $this->addFlash('success', $translator->trans('Environment created.'));

        return $this->redirectToRoute('app_environment_edit', [
            'workspace' => $workspace->getId(),
            'environment' => $env->getId(),
        ]);
    }

    #[Route('/{environment}', name: 'app_environment_edit', methods: ['GET', 'POST'])]
    public function edit(
        Workspace $workspace,
        #[MapEntity(mapping: ['environment' => 'id'])] Environment $environment,
        Request $request,
        EnvironmentRepository $environments,
        TranslatorInterface $translator,
        EnvironmentResolver $envResolver,
        \App\Repository\EnvUserValueRepository $userValues,
    ): Response {
        // Viewers may open the page to manage their own personal values; only
        // changing the shared definition requires edit rights.
        $this->assertWorkspace($workspace);
        $this->assertEnvironment($workspace, $environment);

        if ($request->isMethod('POST')) {
            $this->assertWorkspace($workspace, 'edit');
            if (!$this->isCsrfTokenValid('edit-environment' . $environment->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $environment->setName(trim((string) $request->request->get('name')) ?: $environment->getName());

            // Rebuild variables from the submitted rows (orphanRemoval clears the old ones).
            foreach ($environment->getVariables()->toArray() as $existing) {
                $environment->removeVariable($existing);
            }

            $names = (array) $request->request->all('var_name');
            $values = (array) $request->request->all('var_value');
            $secrets = (array) $request->request->all('var_secret');

            foreach ($names as $i => $name) {
                $name = trim((string) $name);
                if ('' === $name) {
                    continue;
                }
                $variable = new EnvVariable();
                $variable->setName($name);
                $variable->setValue((string) ($values[$i] ?? ''));
                $variable->setSecret(isset($secrets[$i]) && '1' === $secrets[$i]);
                $environment->addVariable($variable);
            }

            $environments->save($environment);
            $this->addFlash('success', $translator->trans('Environment saved.'));

            return $this->redirectToRoute('app_environment_edit', [
                'workspace' => $workspace->getId(),
                'environment' => $environment->getId(),
            ]);
        }

        return $this->render('app/environment/edit.html.twig', [
            'workspace' => $workspace,
            'environment' => $environment,
            'my_values' => $userValues->mapFor($environment, $this->currentUser()),
        ]);
    }

    /**
     * Personal variable values: anyone who can see the workspace may set their
     * own, without touching the shared definition.
     */
    #[Route('/{environment}/my-values', name: 'app_environment_my_values', methods: ['POST'])]
    public function saveMyValues(
        Workspace $workspace,
        #[MapEntity(mapping: ['environment' => 'id'])] Environment $environment,
        Request $request,
        EnvironmentResolver $envResolver,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertEnvironment($workspace, $environment);

        if (!$this->isCsrfTokenValid('my-values' . $environment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $values = [];
        foreach ((array) $request->request->all('my') as $name => $value) {
            $values[(string) $name] = \is_scalar($value) ? (string) $value : '';
        }
        $envResolver->saveUserValues($environment, $this->currentUser(), $values);
        $this->addFlash('success', $translator->trans('Your personal values have been saved.'));

        return $this->redirectToRoute('app_environment_edit', [
            'workspace' => $workspace->getId(),
            'environment' => $environment->getId(),
        ]);
    }

    #[Route('/{environment}/delete', name: 'app_environment_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['environment' => 'id'])] Environment $environment,
        Request $request,
        EnvironmentRepository $environments,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertEnvironment($workspace, $environment);

        if (!$this->isCsrfTokenValid('delete' . $environment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $environments->remove($environment);
        $this->addFlash('success', $translator->trans('Environment deleted.'));

        return $this->redirectToRoute('app_environment_index', ['workspace' => $workspace->getId()]);
    }

    private function assertEnvironment(Workspace $workspace, Environment $environment): void
    {
        if ($environment->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}
