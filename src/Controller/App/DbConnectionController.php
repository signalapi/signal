<?php

namespace App\Controller\App;

use App\Entity\DbConnection;
use App\Entity\Workspace;
use App\Repository\DbConnectionRepository;
use App\Service\Db\DbQueryRunner;
use App\Service\SecretCipher;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/db-connections')]
#[IsGranted('ROLE_USER')]
class DbConnectionController extends AbstractAppController
{
    #[Route('', name: 'app_dbconn_index', methods: ['GET'])]
    public function index(Workspace $workspace, DbConnectionRepository $connections): Response
    {
        $this->assertWorkspace($workspace);

        return $this->render('app/dbconn/index.html.twig', [
            'workspace' => $workspace,
            'connections' => $connections->findByWorkspace($workspace),
        ]);
    }

    #[Route('/new', name: 'app_dbconn_new', methods: ['GET', 'POST'])]
    public function new(Workspace $workspace, Request $httpRequest, DbConnectionRepository $connections, SecretCipher $cipher): Response
    {
        $this->assertWorkspace($workspace, 'edit');

        if ($httpRequest->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('dbconn-new', (string) $httpRequest->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $conn = new DbConnection();
            $conn->setWorkspace($workspace);
            $this->applyForm($conn, $httpRequest, $cipher);
            $connections->save($conn);
            $this->addFlash('success', 'Bağlantı oluşturuldu.');

            return $this->redirectToRoute('app_dbconn_index', ['workspace' => $workspace->getId()]);
        }

        return $this->render('app/dbconn/edit.html.twig', [
            'workspace' => $workspace,
            'connection' => null,
        ]);
    }

    #[Route('/{connection}/edit', name: 'app_dbconn_edit', methods: ['GET', 'POST'])]
    public function edit(
        Workspace $workspace,
        #[MapEntity(mapping: ['connection' => 'id'])] DbConnection $connection,
        Request $httpRequest,
        DbConnectionRepository $connections,
        SecretCipher $cipher,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertConnection($workspace, $connection);

        if ($httpRequest->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('dbconn-edit' . $connection->getId(), (string) $httpRequest->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $this->applyForm($connection, $httpRequest, $cipher);
            $connections->save($connection);
            $this->addFlash('success', 'Bağlantı kaydedildi.');

            return $this->redirectToRoute('app_dbconn_index', ['workspace' => $workspace->getId()]);
        }

        return $this->render('app/dbconn/edit.html.twig', [
            'workspace' => $workspace,
            'connection' => $connection,
        ]);
    }

    #[Route('/{connection}/test', name: 'app_dbconn_test', methods: ['POST'])]
    public function test(
        Workspace $workspace,
        #[MapEntity(mapping: ['connection' => 'id'])] DbConnection $connection,
        Request $httpRequest,
        DbQueryRunner $runner,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertConnection($workspace, $connection);

        if (!$this->isCsrfTokenValid('dbconn-test' . $connection->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $result = $runner->test($connection);
        if ($result->ok) {
            $this->addFlash('success', sprintf('Bağlantı başarılı (%d ms).', (int) round($result->durationMs)));
        } else {
            $this->addFlash('error', 'Bağlantı hatası: ' . $result->error);
        }

        return $this->redirectToRoute('app_dbconn_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{connection}/delete', name: 'app_dbconn_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['connection' => 'id'])] DbConnection $connection,
        Request $httpRequest,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertConnection($workspace, $connection);

        if (!$this->isCsrfTokenValid('delete' . $connection->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $connections->remove($connection);
        $this->addFlash('success', 'Bağlantı silindi.');

        return $this->redirectToRoute('app_dbconn_index', ['workspace' => $workspace->getId()]);
    }

    private function applyForm(DbConnection $conn, Request $request, SecretCipher $cipher): void
    {
        $type = (string) $request->request->get('type');
        $conn->setName(trim((string) $request->request->get('name')) ?: 'Bağlantı');
        $conn->setType(\in_array($type, DbConnection::TYPES, true) ? $type : DbConnection::TYPE_POSTGRES);
        $conn->setHost(trim((string) $request->request->get('host')));
        $conn->setPort((int) $request->request->get('port') ?: 5432);
        $conn->setDatabaseName($this->nullable((string) $request->request->get('databaseName')));
        $conn->setUsername($this->nullable((string) $request->request->get('username')));

        $password = (string) $request->request->get('password');
        if ('' !== $password) {
            $conn->setPasswordEncrypted($cipher->encrypt($password));
        }

        $options = [];
        $authSource = trim((string) $request->request->get('authSource'));
        if ('' !== $authSource) {
            $options['authSource'] = $authSource;
        }
        $redisDb = trim((string) $request->request->get('redisDb'));
        if ('' !== $redisDb) {
            $options['db'] = (int) $redisDb;
        }
        $conn->setOptions($options);
    }

    private function assertConnection(Workspace $workspace, DbConnection $connection): void
    {
        if ($connection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
