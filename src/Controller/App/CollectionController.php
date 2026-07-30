<?php

namespace App\Controller\App;

use App\Entity\ApiCollection;
use App\Entity\Workspace;
use App\Repository\ApiCollectionRepository;
use App\Service\PostmanImporter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/workspaces/{workspace}/collections')]
#[IsGranted('ROLE_USER')]
class CollectionController extends AbstractAppController
{
    #[Route('', name: 'app_collection_index', methods: ['GET'])]
    public function index(Workspace $workspace, ApiCollectionRepository $collections): Response
    {
        $this->assertWorkspace($workspace);

        return $this->render('app/collection/index.html.twig', [
            'workspace' => $workspace,
            'collections' => $collections->findByWorkspace($workspace),
        ]);
    }

    #[Route('/new', name: 'app_collection_new', methods: ['GET', 'POST'])]
    public function new(Workspace $workspace, Request $request, ApiCollectionRepository $collections, TranslatorInterface $translator): Response
    {
        $this->assertWorkspace($workspace, 'edit');

        $form = $this->createFormBuilder()
            ->add('name', TextType::class, ['label' => 'Collection name', 'constraints' => [new Assert\NotBlank()]])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $collection = new ApiCollection();
            $collection->setWorkspace($workspace);
            $collection->setName($data['name']);
            $collection->setDescription($data['description'] ?? null);
            $collections->save($collection);
            $this->addFlash('success', $translator->trans('Collection created.'));

            return $this->redirectToRoute('app_collection_show', ['workspace' => $workspace->getId(), 'collection' => $collection->getId()]);
        }

        return $this->render('app/collection/new.html.twig', [
            'workspace' => $workspace,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/import', name: 'app_collection_import', methods: ['GET', 'POST'])]
    public function import(
        Workspace $workspace,
        Request $request,
        PostmanImporter $importer,
        \App\Service\OpenApiImporter $openApiImporter,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            /** @var UploadedFile|null $collectionFile */
            $collectionFile = $request->files->get('collection');
            /** @var UploadedFile|null $environmentFile */
            $environmentFile = $request->files->get('environment');

            try {
                if ($collectionFile) {
                    $data = $this->decode($collectionFile);

                    if (\App\Service\OpenApiImporter::supports($data)) {
                        $collection = $openApiImporter->importCollection($data, $workspace);
                        $this->addFlash('success', $translator->trans('Collection "%name%" created from the OpenAPI spec.', ['%name%' => $collection->getName()]));

                        $specEnv = $openApiImporter->importEnvironment($data, $workspace);
                        if (null !== $specEnv) {
                            $this->addFlash('success', $translator->trans('Environment "%name%" created — don\'t forget to fill in the secret values.', ['%name%' => $specEnv->getName()]));
                        }
                    } else {
                        $collection = $importer->importCollection($data, $workspace);
                        $this->addFlash('success', $translator->trans('Collection "%name%" imported.', ['%name%' => $collection->getName()]));

                        // If the collection file carries variables, build an environment automatically.
                        $varsEnv = $importer->importCollectionVariables($data, $workspace, $translator->trans('%name% (collection variables)', ['%name%' => $collection->getName()]));
                        if (null !== $varsEnv) {
                            $this->addFlash('success', $translator->trans('Environment "%name%" created from collection variables.', ['%name%' => $varsEnv->getName()]));
                        }
                    }
                }

                if ($environmentFile) {
                    $envData = $this->decode($environmentFile);
                    $env = $importer->importEnvironment($envData, $workspace);
                    $this->addFlash('success', $translator->trans('Environment "%name%" imported.', ['%name%' => $env->getName()]));
                }

                if (!$collectionFile && !$environmentFile) {
                    $this->addFlash('error', $translator->trans('Select at least one file.'));

                    return $this->redirectToRoute('app_collection_import', ['workspace' => $workspace->getId()]);
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $translator->trans('Import error: %error%', ['%error%' => $e->getMessage()]));

                return $this->redirectToRoute('app_collection_import', ['workspace' => $workspace->getId()]);
            }

            return $this->redirectToRoute('app_collection_index', ['workspace' => $workspace->getId()]);
        }

        return $this->render('app/collection/import.html.twig', [
            'workspace' => $workspace,
        ]);
    }

    #[Route('/{collection}', name: 'app_collection_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        \App\Repository\EnvironmentRepository $environments,
        \App\Repository\DataFactoryRepository $dataFactories,
        \App\Service\DynamicVariableGenerator $dynamic,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertCollection($workspace, $collection);

        $envs = $environments->findByWorkspace($workspace);

        // Autocomplete catalog: env variable names + factories + built-ins.
        $catalog = [];
        $seen = [];
        foreach ($envs as $env) {
            foreach (array_keys($env->toMap()) as $name) {
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    $catalog[] = ['token' => '{{' . $name . '}}', 'label' => $name, 'desc' => $translator->trans('env variable'), 'group' => 'var'];
                }
            }
        }
        foreach ($dataFactories->findByWorkspace($workspace) as $f) {
            $catalog[] = ['token' => '{{$' . $f->getName() . '}}', 'label' => $f->getName(), 'desc' => $translator->trans('factory · %kind%', ['%kind%' => $f->getKind()]), 'group' => 'gen'];
        }
        foreach ($dynamic->builtins() as $b) {
            $catalog[] = ['token' => $b['token'], 'label' => $b['name'], 'desc' => $b['description'], 'group' => 'gen'];
        }

        return $this->render('app/collection/workbench.html.twig', [
            'workspace' => $workspace,
            'collection' => $collection,
            'environments' => $envs,
            'vc_catalog' => $catalog,
        ]);
    }

    #[Route('/{collection}/delete', name: 'app_collection_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        Request $request,
        ApiCollectionRepository $collections,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertCollection($workspace, $collection);

        if (!$this->isCsrfTokenValid('delete' . $collection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $collections->remove($collection);
        $this->addFlash('success', $translator->trans('Collection deleted.'));

        return $this->redirectToRoute('app_collection_index', ['workspace' => $workspace->getId()]);
    }

    private function assertCollection(Workspace $workspace, ApiCollection $collection): void
    {
        if ($collection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Decodes an uploaded JSON — or, for OpenAPI specs, YAML — document.
     *
     * @return array<string, mixed>
     */
    private function decode(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getPathname());
        $data = json_decode($content, true);
        if (\JSON_ERROR_NONE === json_last_error() && \is_array($data)) {
            return $data;
        }

        try {
            $data = \Symfony\Component\Yaml\Yaml::parse($content);
        } catch (\Symfony\Component\Yaml\Exception\ParseException) {
            $data = null;
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not valid JSON or YAML.', $file->getClientOriginalName()));
        }

        return $data;
    }
}
