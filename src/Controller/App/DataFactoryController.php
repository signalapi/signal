<?php

namespace App\Controller\App;

use App\Entity\DataFactory;
use App\Entity\Workspace;
use App\Repository\DataFactoryRepository;
use App\Service\DynamicVariableGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/data-factories')]
#[IsGranted('ROLE_MERCHANT')]
class DataFactoryController extends AbstractAppController
{
    #[Route('', name: 'app_factory_index', methods: ['GET'])]
    public function index(Workspace $workspace, DataFactoryRepository $factories, DynamicVariableGenerator $gen): Response
    {
        $this->assertWorkspace($workspace);

        // A sample value per factory, for the list preview.
        $samples = [];
        foreach ($factories->findByWorkspace($workspace) as $f) {
            $gen->setFactories([$f->getName() => ['kind' => $f->getKind(), 'config' => $f->getConfig()]]);
            $samples[(string) $f->getId()] = $gen->generate('$' . $f->getName());
        }

        $gen->setFactories([]); // built-in samples shouldn't see factory state

        return $this->render('app/data_factory/index.html.twig', [
            'workspace' => $workspace,
            'factories' => $factories->findByWorkspace($workspace),
            'samples' => $samples,
            'builtins' => $gen->builtins(),
        ]);
    }

    #[Route('/new', name: 'app_factory_new', methods: ['POST'])]
    public function new(Workspace $workspace, Request $request, DataFactoryRepository $factories): Response
    {
        $this->assertWorkspace($workspace);
        if (!$this->isCsrfTokenValid('factory-new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $factory = new DataFactory();
        $factory->setWorkspace($workspace);
        $factory->setName($this->cleanName((string) $request->request->get('name')));
        $factory->setKind(DataFactory::KIND_ONE_OF);
        $factory->setConfig(['values' => ['Yuno', 'Payrails']]);

        if ('' === $factory->getName()) {
            $this->addFlash('error', 'Geçerli bir ad girin (harf, rakam, _ veya .).');

            return $this->redirectToRoute('app_factory_index', ['workspace' => $workspace->getId()]);
        }

        try {
            $factories->save($factory);
        } catch (\Throwable) {
            $this->addFlash('error', 'Bu adla bir fabrika zaten var.');

            return $this->redirectToRoute('app_factory_index', ['workspace' => $workspace->getId()]);
        }

        return $this->redirectToRoute('app_factory_edit', ['workspace' => $workspace->getId(), 'factory' => $factory->getId()]);
    }

    #[Route('/{factory}', name: 'app_factory_edit', methods: ['GET', 'POST'])]
    public function edit(
        Workspace $workspace,
        #[MapEntity(mapping: ['factory' => 'id'])] DataFactory $factory,
        Request $request,
        DataFactoryRepository $factories,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFactory($workspace, $factory);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('factory-edit' . $factory->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $factory->setName($this->cleanName((string) $request->request->get('name')) ?: $factory->getName());
            $factory->setDescription(trim((string) $request->request->get('description')) ?: null);
            $kind = (string) $request->request->get('kind');
            $factory->setKind(\in_array($kind, DataFactory::KINDS, true) ? $kind : DataFactory::KIND_ONE_OF);
            $factory->setConfig($this->configFromRequest($factory->getKind(), $request));

            try {
                $factories->save($factory);
                $this->addFlash('success', 'Fabrika kaydedildi.');
            } catch (\Throwable) {
                $this->addFlash('error', 'Bu adla bir fabrika zaten var.');
            }

            return $this->redirectToRoute('app_factory_edit', ['workspace' => $workspace->getId(), 'factory' => $factory->getId()]);
        }

        return $this->render('app/data_factory/edit.html.twig', [
            'workspace' => $workspace,
            'factory' => $factory,
        ]);
    }

    #[Route('/{factory}/preview', name: 'app_factory_preview', methods: ['POST'])]
    public function preview(
        Workspace $workspace,
        #[MapEntity(mapping: ['factory' => 'id'])] DataFactory $factory,
        Request $request,
        DynamicVariableGenerator $gen,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFactory($workspace, $factory);

        // Preview the on-screen (unsaved) config.
        $kind = (string) $request->request->get('kind', $factory->getKind());
        $kind = \in_array($kind, DataFactory::KINDS, true) ? $kind : $factory->getKind();
        $gen->setFactories([$factory->getName() => ['kind' => $kind, 'config' => $this->configFromRequest($kind, $request)]]);

        $samples = [];
        for ($i = 0; $i < 5; ++$i) {
            $samples[] = $gen->generate('$' . $factory->getName());
        }

        return new JsonResponse(['samples' => $samples]);
    }

    #[Route('/{factory}/delete', name: 'app_factory_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['factory' => 'id'])] DataFactory $factory,
        Request $request,
        DataFactoryRepository $factories,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFactory($workspace, $factory);
        if (!$this->isCsrfTokenValid('factory-delete' . $factory->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $factories->remove($factory);
        $this->addFlash('success', 'Fabrika silindi.');

        return $this->redirectToRoute('app_factory_index', ['workspace' => $workspace->getId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function configFromRequest(string $kind, Request $request): array
    {
        return match ($kind) {
            DataFactory::KIND_ONE_OF => ['values' => array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', (string) $request->request->get('values')) ?: []),
                static fn (string $v): bool => '' !== $v,
            ))],
            DataFactory::KIND_TEMPLATE => ['template' => (string) $request->request->get('template')],
            DataFactory::KIND_INT_RANGE => ['min' => (int) $request->request->get('min', 0), 'max' => (int) $request->request->get('max', 100)],
            DataFactory::KIND_PATTERN => ['pattern' => (string) $request->request->get('pattern')],
            default => [],
        };
    }

    private function cleanName(string $name): string
    {
        return preg_replace('/[^\w.\-]/', '', trim($name)) ?? '';
    }

    private function assertFactory(Workspace $workspace, DataFactory $factory): void
    {
        if ($factory->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}
