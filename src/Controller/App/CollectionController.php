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
    public function new(Workspace $workspace, Request $request, ApiCollectionRepository $collections): Response
    {
        $this->assertWorkspace($workspace, 'edit');

        $form = $this->createFormBuilder()
            ->add('name', TextType::class, ['label' => 'Collection adı', 'constraints' => [new Assert\NotBlank()]])
            ->add('description', TextareaType::class, ['label' => 'Açıklama', 'required' => false])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $collection = new ApiCollection();
            $collection->setWorkspace($workspace);
            $collection->setName($data['name']);
            $collection->setDescription($data['description'] ?? null);
            $collections->save($collection);
            $this->addFlash('success', 'Collection oluşturuldu.');

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
                        $this->addFlash('success', sprintf('OpenAPI spec\'inden "%s" collection\'ı oluşturuldu.', $collection->getName()));

                        $specEnv = $openApiImporter->importEnvironment($data, $workspace);
                        if (null !== $specEnv) {
                            $this->addFlash('success', sprintf('"%s" environment\'ı oluşturuldu — secret değerleri doldurmayı unutmayın.', $specEnv->getName()));
                        }
                    } else {
                        $collection = $importer->importCollection($data, $workspace);
                        $this->addFlash('success', sprintf('Collection "%s" içe aktarıldı.', $collection->getName()));

                        // Collection dosyasının içindeki değişkenler varsa otomatik environment yap.
                        $varsEnv = $importer->importCollectionVariables($data, $workspace, $collection->getName() . ' (collection değişkenleri)');
                        if (null !== $varsEnv) {
                            $this->addFlash('success', sprintf('Collection değişkenlerinden "%s" environment\'ı oluşturuldu.', $varsEnv->getName()));
                        }
                    }
                }

                if ($environmentFile) {
                    $envData = $this->decode($environmentFile);
                    $env = $importer->importEnvironment($envData, $workspace);
                    $this->addFlash('success', sprintf('Environment "%s" içe aktarıldı.', $env->getName()));
                }

                if (!$collectionFile && !$environmentFile) {
                    $this->addFlash('error', 'En az bir dosya seçin.');

                    return $this->redirectToRoute('app_collection_import', ['workspace' => $workspace->getId()]);
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', 'İçe aktarma hatası: ' . $e->getMessage());

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
                    $catalog[] = ['token' => '{{' . $name . '}}', 'label' => $name, 'desc' => 'env değişkeni', 'group' => 'var'];
                }
            }
        }
        foreach ($dataFactories->findByWorkspace($workspace) as $f) {
            $catalog[] = ['token' => '{{$' . $f->getName() . '}}', 'label' => $f->getName(), 'desc' => 'fabrika · ' . $f->getKind(), 'group' => 'gen'];
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
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertCollection($workspace, $collection);

        if (!$this->isCsrfTokenValid('delete' . $collection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $collections->remove($collection);
        $this->addFlash('success', 'Collection silindi.');

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
            throw new \InvalidArgumentException(sprintf('"%s" geçerli bir JSON ya da YAML değil.', $file->getClientOriginalName()));
        }

        return $data;
    }
}
