<?php

namespace App\Controller\Admin;

use App\Entity\CatalogApi;
use App\Entity\CatalogApiVersion;
use App\Repository\CatalogApiRepository;
use App\Service\OpenApiImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Super-admin curation of the public API catalog: create entries and publish
 * immutable spec versions to them.
 */
#[Route('/admin/catalog')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class CatalogController extends AbstractController
{
    #[Route('', name: 'admin_catalog_index', methods: ['GET'])]
    public function index(CatalogApiRepository $catalog): Response
    {
        return $this->render('admin/catalog/index.html.twig', [
            'apis' => $catalog->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_catalog_new', methods: ['POST'])]
    public function new(
        Request $request,
        CatalogApiRepository $catalog,
        SluggerInterface $slugger,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('catalog-new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            $this->addFlash('error', 'API adı gerekli.');

            return $this->redirectToRoute('admin_catalog_index');
        }

        $api = new CatalogApi();
        $api->setName($name);
        $api->setSlug($slugger->slug($name)->lower()->toString());
        $api->setPublisher(trim((string) $request->request->get('publisher')) ?: null);
        $api->setCategory(trim((string) $request->request->get('category')) ?: null);
        $api->setDescription(trim((string) $request->request->get('description')) ?: null);
        $api->setLogo(trim((string) $request->request->get('logo')) ?: null);

        if (null !== $catalog->findOneBy(['slug' => $api->getSlug()])) {
            $this->addFlash('error', 'Bu isimde bir katalog kaydı zaten var.');

            return $this->redirectToRoute('admin_catalog_index');
        }

        $em->persist($api);
        $em->flush();
        $this->addFlash('success', sprintf('"%s" kataloğa eklendi. Şimdi bir spec versiyonu yayınlayın.', $api->getName()));

        return $this->redirectToRoute('admin_catalog_show', ['id' => $api->getId()]);
    }

    #[Route('/{id}', name: 'admin_catalog_show', methods: ['GET'])]
    public function show(CatalogApi $api): Response
    {
        return $this->render('admin/catalog/show.html.twig', [
            'api' => $api,
        ]);
    }

    /** Publishes a new immutable version from an uploaded OpenAPI JSON/YAML file. */
    #[Route('/{id}/versions', name: 'admin_catalog_publish', methods: ['POST'])]
    public function publish(CatalogApi $api, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('catalog-publish' . $api->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('spec');
        if (null === $file) {
            $this->addFlash('error', 'Bir spec dosyası seçin.');

            return $this->redirectToRoute('admin_catalog_show', ['id' => $api->getId()]);
        }

        try {
            $data = $this->decode($file);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_catalog_show', ['id' => $api->getId()]);
        }

        if (!OpenApiImporter::supports($data) || !isset($data['paths'])) {
            $this->addFlash('error', 'Dosya bir OpenAPI/Swagger spec\'i değil.');

            return $this->redirectToRoute('admin_catalog_show', ['id' => $api->getId()]);
        }

        $version = new CatalogApiVersion();
        $version->setCatalogApi($api);
        $version->setSpec($data);
        $version->setLabel(trim((string) $request->request->get('label')) ?: date('Y-m-d'));
        $version->setChangelog(trim((string) $request->request->get('changelog')) ?: null);

        $latest = $api->getLatestVersion();
        if (null !== $latest && $latest->getSpecHash() === $version->getSpecHash()) {
            $this->addFlash('error', sprintf('Spec, son versiyonla ("%s") birebir aynı — yeni versiyon açılmadı.', $latest->getLabel()));

            return $this->redirectToRoute('admin_catalog_show', ['id' => $api->getId()]);
        }

        $em->persist($version);
        $em->flush();
        $this->addFlash('success', sprintf('"%s" versiyonu yayınlandı.', $version->getLabel()));

        return $this->redirectToRoute('admin_catalog_show', ['id' => $api->getId()]);
    }

    #[Route('/{id}/toggle', name: 'admin_catalog_toggle', methods: ['POST'])]
    public function toggle(CatalogApi $api, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('catalog-toggle' . $api->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $api->setActive(!$api->isActive());
        $em->flush();
        $this->addFlash('success', 'Katalog kaydı güncellendi.');

        return $this->redirectToRoute('admin_catalog_index');
    }

    #[Route('/{id}', name: 'admin_catalog_delete', methods: ['POST'])]
    public function delete(CatalogApi $api, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('catalog-delete' . $api->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($api);
        $em->flush();
        $this->addFlash('success', 'Katalog kaydı silindi.');

        return $this->redirectToRoute('admin_catalog_index');
    }

    /** @return array<string, mixed> */
    private function decode(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getPathname());
        $data = json_decode($content, true);
        if (\JSON_ERROR_NONE === json_last_error() && \is_array($data)) {
            return $data;
        }

        try {
            $data = Yaml::parse($content);
        } catch (ParseException) {
            $data = null;
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(sprintf('"%s" geçerli bir JSON ya da YAML değil.', $file->getClientOriginalName()));
        }

        return $data;
    }
}
