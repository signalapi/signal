<?php

namespace App\Controller\App;

use App\Entity\NotificationSubscription;
use App\Entity\Schedule;
use App\Entity\Workspace;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\NotificationDestinationRepository;
use App\Repository\ScheduleRepository;
use App\Repository\TestFlowRepository;
use App\Service\ScheduleCompiler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Recurring runs. A schedule targets one flow or one suite and holds a list of
 * timing rules; see App\Service\ScheduleCompiler for the rule shape.
 */
#[Route('/app/workspaces/{workspace}/schedules')]
class ScheduleController extends AbstractAppController
{
    #[Route('', name: 'app_schedule_index', methods: ['GET'])]
    public function index(
        Workspace $workspace,
        ScheduleRepository $schedules,
        ScheduleCompiler $compiler,
    ): Response {
        $this->assertWorkspace($workspace);

        $rows = [];
        foreach ($schedules->findByWorkspace($workspace) as $schedule) {
            $rows[] = [
                'schedule' => $schedule,
                'rules' => $compiler->describe($schedule),
                'next' => $schedule->isEnabled() ? $compiler->nextRun($schedule) : null,
            ];
        }

        return $this->render('app/schedule/index.html.twig', [
            'workspace' => $workspace,
            'rows' => $rows,
        ]);
    }

    #[Route('/new', name: 'app_schedule_new', methods: ['GET', 'POST'])]
    public function new(
        Workspace $workspace,
        Request $request,
        ScheduleRepository $schedules,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
        EnvironmentRepository $environments,
        NotificationDestinationRepository $destinations,
        ScheduleCompiler $compiler,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');

        $schedule = new Schedule();
        $schedule->setWorkspace($workspace);

        return $this->editor($schedule, $workspace, $request, $schedules, $flows, $groups, $environments, $destinations, $compiler, $translator);
    }

    #[Route('/{schedule}/edit', name: 'app_schedule_edit', methods: ['GET', 'POST'])]
    public function edit(
        Workspace $workspace,
        #[MapEntity(mapping: ['schedule' => 'id'])] Schedule $schedule,
        Request $request,
        ScheduleRepository $schedules,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
        EnvironmentRepository $environments,
        NotificationDestinationRepository $destinations,
        ScheduleCompiler $compiler,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertSchedule($workspace, $schedule);

        return $this->editor($schedule, $workspace, $request, $schedules, $flows, $groups, $environments, $destinations, $compiler, $translator);
    }

    #[Route('/{schedule}/toggle', name: 'app_schedule_toggle', methods: ['POST'])]
    public function toggle(
        Workspace $workspace,
        #[MapEntity(mapping: ['schedule' => 'id'])] Schedule $schedule,
        Request $request,
        ScheduleRepository $schedules,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertSchedule($workspace, $schedule);
        if (!$this->isCsrfTokenValid('schedule-toggle' . $schedule->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $schedule->setEnabled(!$schedule->isEnabled());
        $schedules->save($schedule);

        return $this->redirectToRoute('app_schedule_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{schedule}/delete', name: 'app_schedule_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['schedule' => 'id'])] Schedule $schedule,
        Request $request,
        ScheduleRepository $schedules,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertSchedule($workspace, $schedule);
        if (!$this->isCsrfTokenValid('schedule-delete' . $schedule->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $schedules->remove($schedule);
        $this->addFlash('success', $translator->trans('Schedule deleted.'));

        return $this->redirectToRoute('app_schedule_index', ['workspace' => $workspace->getId()]);
    }

    // ------------------------------------------------------------------ shared

    private function editor(
        Schedule $schedule,
        Workspace $workspace,
        Request $request,
        ScheduleRepository $schedules,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
        EnvironmentRepository $environments,
        NotificationDestinationRepository $destinations,
        ScheduleCompiler $compiler,
        TranslatorInterface $translator,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('schedule-save', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            // target: "flow:<id>" or "suite:<id>"
            $target = (string) $request->request->get('target');
            [$kind, $id] = array_pad(explode(':', $target, 2), 2, '');
            if ('suite' === $kind) {
                $group = $groups->find($id);
                $schedule->setFlowGroup($group && $this->sameWorkspace($workspace, $group->getWorkspace()) ? $group : null);
            } elseif ('flow' === $kind) {
                $flow = $flows->find($id);
                $schedule->setFlow($flow && $this->sameWorkspace($workspace, $flow->getWorkspace()) ? $flow : null);
            }
            if (!$schedule->hasTarget()) {
                $errors[] = $translator->trans('Pick a flow or a suite to run.');
            }

            $schedule->setName(trim((string) $request->request->get('name')) ?: $schedule->getTargetName());
            $schedule->setEnabled((bool) $request->request->get('enabled'));

            $tz = (string) $request->request->get('timezone');
            $schedule->setTimezone(\in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Europe/Istanbul');

            $envId = (string) $request->request->get('environment');
            $env = '' === $envId ? null : $environments->find($envId);
            $schedule->setEnvironment($env && $this->sameWorkspace($workspace, $env->getWorkspace()) ? $env : null);

            $rules = [];
            foreach ($this->submittedRules($request) as $raw) {
                $rule = $compiler->normaliseRule($raw);
                if (null !== $rule) {
                    $rules[] = $rule;
                }
            }
            if (!$rules) {
                $errors[] = $translator->trans('Add at least one timing rule.');
            }
            $schedule->setRules($rules);

            // Where this schedule's result goes, on top of the workspace rules.
            $picked = array_map('strval', (array) $request->request->all('notify_destinations'));
            $valid = [];
            foreach ($destinations->findActiveByWorkspaceAndIds($workspace, array_values(array_filter($picked, static fn ($id) => Uuid::isValid($id)))) as $destination) {
                $valid[] = (string) $destination->getId();
            }
            $condition = (string) $request->request->get('notify_condition', NotificationSubscription::WHEN_ALWAYS);
            $schedule->setNotify([] === $valid ? [] : [
                'destinations' => $valid,
                'condition' => \in_array($condition, NotificationSubscription::CONDITIONS, true)
                    ? $condition
                    : NotificationSubscription::WHEN_ALWAYS,
            ]);

            if (!$errors) {
                $schedules->save($schedule);
                $this->addFlash('success', $translator->trans('Schedule saved.'));

                return $this->redirectToRoute('app_schedule_index', ['workspace' => $workspace->getId()]);
            }
        }

        return $this->render('app/schedule/edit.html.twig', [
            'workspace' => $workspace,
            'schedule' => $schedule,
            'flows' => $flows->findByWorkspace($workspace),
            'groups' => $groups->findByWorkspace($workspace),
            'environments' => $environments->findByWorkspace($workspace),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'notify_destinations' => array_values(array_filter(
                $destinations->findByWorkspace($workspace),
                static fn ($d) => $d->isActive(),
            )),
            'errors' => $errors,
            'preview' => $compiler->describe($schedule),
            'next' => $schedule->getRules() ? $compiler->nextRun($schedule) : null,
        ]);
    }

    /**
     * The rule rows arrive as parallel arrays (rule_mode[], rule_days[0][], …)
     * because the editor adds and removes rows client-side.
     *
     * @return list<array<string, mixed>>
     */
    private function submittedRules(Request $request): array
    {
        $modes = (array) $request->request->all('rule_mode');
        $days = (array) $request->request->all('rule_days');
        $monthDays = (array) $request->request->all('rule_month_days');
        $at = (array) $request->request->all('rule_at');
        $n = (array) $request->request->all('rule_n');
        $unit = (array) $request->request->all('rule_unit');
        $from = (array) $request->request->all('rule_from');
        $to = (array) $request->request->all('rule_to');
        $expr = (array) $request->request->all('rule_expr');

        $out = [];
        foreach ($modes as $i => $mode) {
            $out[] = [
                'mode' => $mode,
                'days' => (array) ($days[$i] ?? []),
                'monthDays' => array_filter(array_map('trim', explode(',', (string) ($monthDays[$i] ?? '')))),
                // "09:00, 14:00 18:30" — commas, spaces or newlines all work
                'at' => array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($at[$i] ?? '')) ?: [])),
                'n' => $n[$i] ?? 1,
                'unit' => $unit[$i] ?? 'hour',
                'from' => $from[$i] ?? '',
                'to' => $to[$i] ?? '',
                'expr' => $expr[$i] ?? '',
            ];
        }

        return $out;
    }

    private function sameWorkspace(Workspace $a, Workspace $b): bool
    {
        return $a->getId()?->toRfc4122() === $b->getId()?->toRfc4122();
    }

    private function assertSchedule(Workspace $workspace, Schedule $schedule): void
    {
        if (!$this->sameWorkspace($workspace, $schedule->getWorkspace())) {
            throw $this->createNotFoundException();
        }
    }
}
