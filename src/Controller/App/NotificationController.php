<?php

namespace App\Controller\App;

use App\Entity\NotificationDestination;
use App\Entity\NotificationSubscription;
use App\Entity\Workspace;
use App\Repository\FlowGroupRepository;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationDestinationRepository;
use App\Repository\NotificationSubscriptionRepository;
use App\Repository\TestFlowRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\SecretCipher;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Where run results go: Slack incoming webhooks and plain HTTP endpoints, plus
 * the standing rules that decide which runs are worth a message. Sending itself
 * happens on the bus — see NotificationDispatcher.
 */
#[Route('/app/workspaces/{workspace}/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractAppController
{
    #[Route('', name: 'app_notification_index', methods: ['GET'])]
    public function index(
        Workspace $workspace,
        NotificationDestinationRepository $destinations,
        NotificationSubscriptionRepository $subscriptions,
        NotificationDeliveryRepository $deliveries,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');

        return $this->render('app/notification/index.html.twig', [
            'workspace' => $workspace,
            'destinations' => $destinations->findByWorkspace($workspace),
            'subscriptions' => $subscriptions->findByWorkspace($workspace),
            'deliveries' => $deliveries->findRecentByWorkspace($workspace, 20),
            'flows' => $flows->findByWorkspace($workspace),
            'groups' => $groups->findByWorkspace($workspace),
        ]);
    }

    #[Route('/destinations', name: 'app_notification_destination_create', methods: ['POST'])]
    public function createDestination(
        Workspace $workspace,
        Request $request,
        NotificationDestinationRepository $destinations,
        SecretCipher $cipher,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        if (!$this->isCsrfTokenValid('notif-destination', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = (string) $request->request->get('type', NotificationDestination::TYPE_SLACK);
        $url = trim((string) $request->request->get('url'));
        $name = trim((string) $request->request->get('name'));
        $label = trim((string) $request->request->get('label'));
        $secret = trim((string) $request->request->get('secret'));

        if (!\in_array($type, NotificationDestination::TYPES, true)) {
            throw $this->createAccessDeniedException();
        }
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host || !\in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->addFlash('error', $this->translator->trans('Enter a valid http(s) URL.'));

            return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
        }
        if (NotificationDestination::TYPE_SLACK === $type && !str_contains($host, 'slack.com')) {
            $this->addFlash('error', $this->translator->trans('A Slack incoming webhook URL looks like https://hooks.slack.com/services/…'));

            return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
        }

        $destination = new NotificationDestination();
        $destination->setWorkspace($workspace);
        $destination->setType($type);
        $destination->setName('' !== $name ? $name : ($label ?: $host));
        $destination->setLabel('' !== $label ? $label : null);
        $destination->setUrlEncrypted($cipher->encrypt($url));
        $destination->setUrlHost($host);
        $destination->setSecretEncrypted('' !== $secret ? $cipher->encrypt($secret) : null);
        $destination->setCreatedBy($this->currentUser());
        $destinations->save($destination);

        $this->addFlash('success', $this->translator->trans('Destination "%name%" added. Send a test message to confirm it works.', ['%name%' => $destination->getName()]));

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/destinations/{destination}/test', name: 'app_notification_destination_test', methods: ['POST'])]
    public function testDestination(
        Workspace $workspace,
        #[MapEntity(mapping: ['destination' => 'id'])] NotificationDestination $destination,
        Request $request,
        NotificationDispatcher $dispatcher,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $this->assertOwned($workspace, $destination);
        if (!$this->isCsrfTokenValid('notif-test' . $destination->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $dispatcher->queueTest($destination);
        $this->addFlash('success', $this->translator->trans('A test message is on its way. The result shows up in the delivery log below.'));

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/destinations/{destination}/toggle', name: 'app_notification_destination_toggle', methods: ['POST'])]
    public function toggleDestination(
        Workspace $workspace,
        #[MapEntity(mapping: ['destination' => 'id'])] NotificationDestination $destination,
        Request $request,
        NotificationDestinationRepository $destinations,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $this->assertOwned($workspace, $destination);
        if (!$this->isCsrfTokenValid('notif-toggle' . $destination->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $destination->setActive(!$destination->isActive());
        $destinations->save($destination);

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/destinations/{destination}/delete', name: 'app_notification_destination_delete', methods: ['POST'])]
    public function deleteDestination(
        Workspace $workspace,
        #[MapEntity(mapping: ['destination' => 'id'])] NotificationDestination $destination,
        Request $request,
        NotificationDestinationRepository $destinations,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $this->assertOwned($workspace, $destination);
        if (!$this->isCsrfTokenValid('notif-delete' . $destination->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = $destination->getName();
        $destinations->remove($destination);
        $this->addFlash('success', $this->translator->trans('Destination "%name%" deleted; its rules are gone with it.', ['%name%' => $name]));

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/rules', name: 'app_notification_rule_create', methods: ['POST'])]
    public function createRule(
        Workspace $workspace,
        Request $request,
        NotificationDestinationRepository $destinations,
        NotificationSubscriptionRepository $subscriptions,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        if (!$this->isCsrfTokenValid('notif-rule', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $destinationId = (string) $request->request->get('destination');
        $destination = Uuid::isValid($destinationId) ? $destinations->find(Uuid::fromString($destinationId)) : null;
        if (null === $destination || !$this->belongsTo($workspace, $destination->getWorkspace())) {
            throw $this->createNotFoundException();
        }

        // scope: "workspace", "flow:<id>" or "suite:<id>"
        [$scopeType, $scopeRaw] = array_pad(explode(':', (string) $request->request->get('scope', 'workspace'), 2), 2, '');
        $scopeId = null;
        if (NotificationSubscription::SCOPE_FLOW === $scopeType) {
            $flow = Uuid::isValid($scopeRaw) ? $flows->find(Uuid::fromString($scopeRaw)) : null;
            if (null === $flow || !$this->belongsTo($workspace, $flow->getWorkspace())) {
                throw $this->createNotFoundException();
            }
            $scopeId = $flow->getId();
        } elseif (NotificationSubscription::SCOPE_SUITE === $scopeType) {
            $group = Uuid::isValid($scopeRaw) ? $groups->find(Uuid::fromString($scopeRaw)) : null;
            if (null === $group || !$this->belongsTo($workspace, $group->getWorkspace())) {
                throw $this->createNotFoundException();
            }
            $scopeId = $group->getId();
        } elseif (NotificationSubscription::SCOPE_WORKSPACE !== $scopeType) {
            throw $this->createAccessDeniedException();
        }

        $condition = (string) $request->request->get('condition', NotificationSubscription::WHEN_FAILURE);
        if (!\in_array($condition, NotificationSubscription::CONDITIONS, true)) {
            throw $this->createAccessDeniedException();
        }

        $subscription = new NotificationSubscription();
        $subscription->setWorkspace($workspace);
        $subscription->setDestination($destination);
        $subscription->setScopeType($scopeType);
        $subscription->setScopeId($scopeId);
        $subscription->setCondition($condition);
        $subscriptions->save($subscription);

        $this->addFlash('success', $this->translator->trans('Rule added.'));

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/rules/{subscription}/toggle', name: 'app_notification_rule_toggle', methods: ['POST'])]
    public function toggleRule(
        Workspace $workspace,
        #[MapEntity(mapping: ['subscription' => 'id'])] NotificationSubscription $subscription,
        Request $request,
        NotificationSubscriptionRepository $subscriptions,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $this->assertOwned($workspace, $subscription);
        if (!$this->isCsrfTokenValid('notif-rule-toggle' . $subscription->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $subscription->setEnabled(!$subscription->isEnabled());
        $subscriptions->save($subscription);

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/rules/{subscription}/delete', name: 'app_notification_rule_delete', methods: ['POST'])]
    public function deleteRule(
        Workspace $workspace,
        #[MapEntity(mapping: ['subscription' => 'id'])] NotificationSubscription $subscription,
        Request $request,
        NotificationSubscriptionRepository $subscriptions,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $this->assertOwned($workspace, $subscription);
        if (!$this->isCsrfTokenValid('notif-rule-delete' . $subscription->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $subscriptions->remove($subscription);
        $this->addFlash('success', $this->translator->trans('Rule deleted.'));

        return $this->redirectToRoute('app_notification_index', ['workspace' => $workspace->getId()]);
    }

    /** A destination or rule reached by URL must belong to the workspace in the path. */
    private function assertOwned(Workspace $workspace, NotificationDestination|NotificationSubscription $entity): void
    {
        if (!$this->belongsTo($workspace, $entity->getWorkspace())) {
            throw $this->createNotFoundException();
        }
    }

    private function belongsTo(Workspace $workspace, Workspace $other): bool
    {
        return $workspace->getId()?->toRfc4122() === $other->getId()?->toRfc4122();
    }
}
