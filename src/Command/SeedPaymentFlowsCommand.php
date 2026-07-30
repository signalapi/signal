<?php

namespace App\Command;

use App\Entity\EnvVariable;
use App\Entity\FlowGroup;
use App\Entity\FlowStep;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\MerchantMemberRepository;
use App\Repository\TestFlowRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\FlowExpressionParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds an intelligent payment test-flow matrix into a merchant workspace, grouped
 * into runnable suites (Yuno, Payrails, General). Each scenario validates the real
 * chain against the Zotlo merchant API: pay → confirm the payment went through the
 * EXPECTED provider (transaction/detail.provider_name) → verify subscription/transaction.
 *
 * Idempotent: a flow whose name already exists is skipped.
 */
#[AsCommand(name: 'app:seed-payment-flows', description: 'Seeds the payment test-flow suites (Yuno / Payrails / General).')]
class SeedPaymentFlowsCommand extends Command
{
    /** subscription payment with a raw card; vaults the card on success. */
    private const SUB_BODY = <<<'JSON'
        {
          "cardNo": "{{generalCreditCard}}", "cardOwner": "{{$randomFullName}}",
          "expireMonth": "12", "expireYear": "35", "cvv": "{{generalCreditCardCvv}}",
          "language": "en", "packageId": "{{monthlyPkg}}", "packageCountry": "US", "platform": "{{platform}}",
          "subscriberId": "{{subscriberId}}", "subscriberEmail": "{{subscriberId}}",
          "subscriberFirstname": "{{$randomFirstName}}", "subscriberLastname": "{{$randomLastName}}",
          "subscriberCountry": "US", "subscriberIpAddress": "92.44.149.10", "subscriberPhoneNumber": "+79009999999",
          "quantity": 1, "installment": 1, "force3ds": false
        }
        JSON;

    /** one-time consumable payment with a raw card. */
    private const CONS_BODY = <<<'JSON'
        {
          "cardNo": "{{generalCreditCard}}", "cardOwner": "{{$randomFullName}}",
          "expireMonth": "12", "expireYear": "35", "cvv": "{{generalCreditCardCvv}}",
          "language": "en", "packageId": "{{consumablePkg}}", "packageCountry": "US", "platform": "{{platform}}",
          "subscriberId": "{{subscriberId}}", "subscriberEmail": "{{subscriberId}}",
          "subscriberFirstname": "{{$randomFirstName}}", "subscriberLastname": "{{$randomLastName}}",
          "subscriberCountry": "US", "subscriberIpAddress": "92.44.149.10", "subscriberPhoneNumber": "+79009999999",
          "quantity": 1, "installment": 1, "force3ds": false
        }
        JSON;

    /** consumable payment using a saved-card token (no raw card). */
    private const TOKEN_BODY = <<<'JSON'
        {
          "cardToken": "{{cardToken}}", "cvvCheck": false, "language": "en",
          "packageId": "{{consumablePkg}}", "packageCountry": "US", "platform": "{{platform}}",
          "subscriberId": "{{subscriberId}}", "subscriberEmail": "{{subscriberId}}",
          "subscriberCountry": "US", "subscriberIpAddress": "92.44.149.10", "subscriberPhoneNumber": "+79009999999",
          "quantity": 1
        }
        JSON;

    /** custom-pay (v2) using a saved-card token + full customer block. */
    private const CUSTOM_BODY = <<<'JSON'
        {
          "paymentMethod": { "useWallet": false, "useCard": true, "cardToken": "{{cardToken}}" },
          "price": { "amount": 10, "currency": "USD", "installment": 1 },
          "customer": {
            "phoneNumber": "+79009999999", "firstname": "{{$randomFirstName}}", "lastname": "{{$randomLastName}}",
            "email": "{{subscriberId}}", "id": "{{subscriberId}}", "ipAddress": "92.44.149.10",
            "country": "US", "language": "en"
          },
          "checkout": { "force3ds": false }
        }
        JSON;

    /** @var array<int, array{0:string,1:string,2:string,3:string}> [suite, monthlyVar, consumableVar, providerNameNeedle] */
    private const PROVIDERS = [
        ['Yuno Suite', '{{yunoPackageId}}', '{{yunoConsumablePackageId}}', 'Yuno'],
        ['Payrails Suite', '{{payrailsPackageId}}', '{{payrailsConsumablePackageId}}', 'Payrails'],
    ];

    /** @var array<string, FlowGroup> */
    private array $groupCache = [];

    public function __construct(
        private readonly UserRepository $users,
        private readonly MerchantMemberRepository $merchantMembers,
        private readonly WorkspaceRepository $workspaces,
        private readonly EnvironmentRepository $environments,
        private readonly TestFlowRepository $flows,
        private readonly FlowGroupRepository $groups,
        private readonly FlowExpressionParser $parser,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Merchant owner email', 'yd@yusufdgn.com')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace name', 'Zotlo')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Delete existing seeded suites/flows first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->users->findOneBy(['email' => (string) $input->getOption('email')]);
        $membership = null !== $user ? ($this->merchantMembers->findByUser($user)[0] ?? null) : null;
        if (null === $membership) {
            $io->error('User/merchant not found.');

            return Command::FAILURE;
        }
        $workspace = null;
        foreach ($this->workspaces->findByMerchant($membership->getMerchant()) as $w) {
            if ($w->getName() === (string) $input->getOption('workspace')) {
                $workspace = $w;
            }
        }
        if (null === $workspace) {
            $io->error('Workspace not found.');

            return Command::FAILURE;
        }

        if ($input->getOption('fresh')) {
            foreach (['Yuno Suite', 'Payrails Suite', 'General Payments'] as $gn) {
                foreach ($this->groups->findByWorkspace($workspace) as $g) {
                    if ($g->getName() === $gn) {
                        foreach ($g->getFlows() as $f) {
                            $this->em->remove($f);
                        }
                        $this->em->remove($g);
                    }
                }
            }
            $this->em->flush();
            $io->note('Existing suites/flows deleted (--fresh).');
        }

        $env = $this->environments->findByWorkspace($workspace)[0] ?? null;
        if (null !== $env) {
            $this->ensureVar($env, 'yunoPackageId', 'yuno_monthly');
            $this->ensureVar($env, 'yunoConsumablePackageId', 'yuno_consumable');
            $this->ensureVar($env, 'payrailsPackageId', 'payrails_monthly');
            $this->ensureVar($env, 'payrailsConsumablePackageId', 'payrails_consumable');
            $this->em->flush();
        }

        $created = [];
        $skipped = [];
        foreach ($this->flowSpecs() as $spec) {
            if ($this->flowExists($workspace, $spec['name'])) {
                $skipped[] = $spec['name'];
                continue;
            }
            $this->buildFlow($workspace, $env, $spec);
            $created[] = $spec['name'];
        }
        $this->em->flush();

        if ($created) {
            $io->success(\count($created) . ' flow(s) created.');
            $io->listing($created);
        }
        if ($skipped) {
            $io->note(\count($skipped) . ' flow(s) already existed and were skipped (use --fresh to rebuild).');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flowSpecs(): array
    {
        $specs = [];

        foreach (self::PROVIDERS as [$suite, $monthly, $consumable, $pname]) {
            $prov = str_replace(' Suite', '', $suite);
            $set = ['kind' => 'setvar', 'name' => 'Variables',
                'query' => "subscriberId = {{\$randomEmail}}\nmonthlyPkg = $monthly\nconsumablePkg = $consumable"];

            $paySub = ['kind' => 'http', 'name' => "Subscription payment ($prov)", 'method' => 'POST', 'url' => '{{API_URL}}payment/credit-card', 'body' => self::SUB_BODY,
                'extract' => "transactionId = result.profile.lastTransactionId\nsubscriptionId = result.profile.subscriptionId",
                'assert' => "status == 200\nmeta.httpStatus == 200\nresult.profile.status == active\nresponseTime < 25000"];

            $providerCheck = ['kind' => 'http', 'name' => 'Verify provider + transaction', 'method' => 'GET',
                'url' => '{{API_URL}}transaction/detail?transactionId={{transactionId}}',
                'assert' => "meta.httpStatus == 200\nresult.transaction.provider_name contains $pname\nresult.transaction.status notEmpty"];

            $profileActive = ['kind' => 'http', 'name' => 'Is the subscription active', 'method' => 'GET',
                'url' => '{{API_URL}}subscription/profile?subscriberId={{subscriberId}}&packageId={{monthlyPkg}}',
                'assert' => "meta.httpStatus == 200\nresult.profile.status == active\nresult.profile.subscriptionId notEmpty"];

            $getToken = ['kind' => 'http', 'name' => 'Fetch the saved card', 'method' => 'GET',
                'url' => '{{API_URL}}subscription/card-list?subscriberId={{subscriberId}}',
                'extract' => 'cardToken = result.cardList.0.token',
                'assert' => "meta.httpStatus == 200\nresult.cardList.0.token notEmpty"];

            // 1) Subscription payment + provider + subscription verification
            $specs[] = ['name' => "$prov · Subscription payment", 'group' => $suite,
                'desc' => "Subscription payment; verifies the payment went through $prov and that the subscription is active.",
                'steps' => [$set, $paySub, $providerCheck, $profileActive]];

            // 2) Consumable (one-time) payment + provider verification
            $specs[] = ['name' => "$prov · Consumable payment", 'group' => $suite,
                'desc' => "One-time (consumable) payment; the transaction is verified against the $prov provider.",
                'steps' => [$set,
                    ['kind' => 'http', 'name' => "Consumable payment ($prov)", 'method' => 'POST', 'url' => '{{API_URL}}payment/credit-card', 'body' => self::CONS_BODY,
                        'extract' => 'transactionId = result.response.transactionId',
                        'assert' => "status == 200\nmeta.httpStatus == 200\nresult.response.isSuccess == true\nresult.package.packageType == consumable"],
                    $providerCheck]];

            // 3) Saved-card (token) consumable payment
            $specs[] = ['name' => "$prov · Saved-card payment (token)", 'group' => $suite,
                'desc' => 'The first payment vaults the card; a second (consumable) payment is made with the saved token and verified.',
                'steps' => [$set, $paySub, $getToken,
                    ['kind' => 'http', 'name' => 'Consumable payment with token', 'method' => 'POST', 'url' => '{{API_URL}}payment/credit-card', 'body' => self::TOKEN_BODY,
                        'extract' => 'transactionId = result.response.transactionId',
                        'assert' => "status == 200\nmeta.httpStatus == 200\nresult.response.isSuccess == true"],
                    $providerCheck]];

            // 4) Custom-pay with a saved card token
            $specs[] = ['name' => "$prov · Custom-pay (saved card)", 'group' => $suite,
                'desc' => 'After the first payment, a custom-pay (v2) payment with the saved token plus provider verification.',
                'steps' => [$set, $paySub, $getToken,
                    ['kind' => 'http', 'name' => 'Custom-pay (token)', 'method' => 'POST', 'url' => '{{API_URL_V2}}payment/custom-pay', 'body' => self::CUSTOM_BODY,
                        'extract' => 'transactionId = result.response.transactionId',
                        'assert' => "status == 200\nmeta.httpStatus == 200\nresult.response.isSuccess == true"],
                    $providerCheck]];

            // 5) Refund
            $specs[] = ['name' => "$prov · Refund", 'group' => $suite,
                'desc' => 'A subscription payment is made, refunded via lastTransactionId, then the transaction is checked again.',
                'steps' => [$set, $paySub,
                    ['kind' => 'http', 'name' => 'Refund', 'method' => 'POST', 'url' => '{{API_URL}}payment/refund',
                        'body' => "{\n  \"transactionId\": \"{{transactionId}}\",\n  \"refundReason\": \"automated test refund\",\n  \"refundPrice\": 1,\n  \"refundCurrency\": \"USD\"\n}",
                        'assert' => "status == 200\nmeta.httpStatus == 200"],
                    ['kind' => 'http', 'name' => 'Transaction after refund', 'method' => 'GET', 'url' => '{{API_URL}}transaction/detail?transactionId={{transactionId}}',
                        'assert' => "meta.httpStatus == 200\nresult.transaction.provider_name contains $pname"]]];

            // 6) Cancel
            $specs[] = ['name' => "$prov · Cancel", 'group' => $suite,
                'desc' => 'A subscription is created, cancelled, and the cancellation is verified on the profile.',
                'steps' => [$set, $paySub,
                    ['kind' => 'http', 'name' => 'Cancel subscription', 'method' => 'POST', 'url' => '{{API_URL}}subscription/cancellation',
                        'body' => "{\n  \"subscriberId\": \"{{subscriberId}}\",\n  \"cancellationReason\": \"automated test\",\n  \"force\": 0,\n  \"packageId\": \"{{monthlyPkg}}\"\n}",
                        'assert' => "status == 200\nmeta.httpStatus == 200"],
                    ['kind' => 'http', 'name' => 'Profile after cancellation', 'method' => 'GET', 'url' => '{{API_URL}}subscription/profile?subscriberId={{subscriberId}}&packageId={{monthlyPkg}}',
                        'assert' => "meta.httpStatus == 200\nresult.profile.cancellation notEmpty"]]];

            // 7) Direct renewal
            $specs[] = ['name' => "$prov · Direct renewal", 'group' => $suite,
                'desc' => 'A subscription is created, renewed directly, and stays active.',
                'steps' => [$set, $paySub,
                    ['kind' => 'http', 'name' => 'Direct renewal', 'method' => 'POST', 'url' => '{{API_URL_V2}}subscription/direct-renewal',
                        'body' => "{\n  \"subscriberId\": \"{{subscriberId}}\",\n  \"packageId\": \"{{monthlyPkg}}\"\n}",
                        'assert' => "status == 200\nmeta.httpStatus == 200"],
                    $profileActive]];
        }

        // ---- General Payments: negative / safety scenarios (payrails baseline) ----
        $genSet = ['kind' => 'setvar', 'name' => 'Variables', 'query' => "subscriberId = {{\$randomEmail}}\nconsumablePkg = {{payrailsConsumablePackageId}}"];
        $specs[] = ['name' => 'General · Invalid package (400)', 'group' => 'General Payments',
            'desc' => 'Paying with an invalid package must return 400 + errorCode.',
            'steps' => [
                ['kind' => 'setvar', 'name' => 'Variables', 'query' => "subscriberId = {{\$randomEmail}}\nmonthlyPkg = invalid-package-xyz"],
                ['kind' => 'http', 'name' => 'Payment with invalid package', 'method' => 'POST', 'url' => '{{API_URL}}payment/credit-card', 'body' => self::SUB_BODY,
                    'assert' => "status == 400\nmeta.httpStatus == 400\nmeta.errorCode notEmpty"]]];
        $specs[] = ['name' => 'General · Invalid card rejected', 'group' => 'General Payments',
            'desc' => 'Paying with an invalid card number must fail (isSuccess false / 4xx).',
            'steps' => [
                $genSet,
                ['kind' => 'http', 'name' => 'Consumable with invalid card', 'method' => 'POST', 'url' => '{{API_URL}}payment/credit-card',
                    'body' => str_replace('{{generalCreditCard}}', '4000000000000002', self::CONS_BODY),
                    'assert' => "result.response.isSuccess == false"]]];

        return $specs;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function buildFlow(Workspace $workspace, ?\App\Entity\Environment $env, array $spec): void
    {
        $flow = new TestFlow();
        $flow->setWorkspace($workspace);
        $flow->setName((string) $spec['name']);
        $flow->setDescription($spec['desc'] ?? null);
        $flow->setStopOnFailure(true);
        if (null !== $env) {
            $flow->setDefaultEnvironment($env);
        }
        $this->em->persist($flow);
        if (!empty($spec['group'])) {
            $group = $this->ensureGroup($workspace, (string) $spec['group']);
            $group->addFlow($flow);
        }

        /** @var FlowStep[] $steps */
        $steps = [];
        $i = 0;
        foreach ($spec['steps'] as $s) {
            $step = new FlowStep();
            $step->setFlow($flow);
            $step->setName((string) $s['name']);
            $step->setPosition($i);
            $step->setCanvasX(180);
            $step->setCanvasY(60 + $i * 150);

            if ('setvar' === $s['kind']) {
                $step->setType(FlowStep::TYPE_SETVAR);
                $step->setQuery((string) $s['query']);
            } else {
                $step->setType(FlowStep::TYPE_HTTP);
                $step->setReqMethod((string) $s['method']);
                $step->setReqUrl((string) $s['url']);
                $step->setReqHeaders($this->authHeaders());
                $step->setReqBodyMode(isset($s['body']) ? 'json' : 'none');
                $step->setReqBody($s['body'] ?? null);
                if (!empty($s['extract'])) {
                    $step->setExtractions($this->parser->parseExtractions((string) $s['extract']));
                }
                if (!empty($s['assert'])) {
                    $step->setAssertions($this->parser->parseAssertions((string) $s['assert']));
                }
            }
            $this->em->persist($step);
            $steps[] = $step;
            ++$i;
        }
        $this->em->flush();

        $edges = [];
        for ($k = 0; $k < \count($steps) - 1; ++$k) {
            $edges[] = [(string) $steps[$k]->getId(), (string) $steps[$k + 1]->getId()];
        }
        $flow->setCanvasEdges($edges);
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function authHeaders(): array
    {
        return [
            ['name' => 'AccessKey', 'value' => '{{ACCESS_KEY}}'],
            ['name' => 'AccessSecret', 'value' => '{{ACCESS_SECURITY}}'],
            ['name' => 'ApplicationId', 'value' => '{{APPLICATION_ID}}'],
            ['name' => 'Language', 'value' => 'en'],
        ];
    }

    private function ensureGroup(Workspace $workspace, string $name): FlowGroup
    {
        if (isset($this->groupCache[$name])) {
            return $this->groupCache[$name];
        }
        foreach ($this->groups->findByWorkspace($workspace) as $g) {
            if ($g->getName() === $name) {
                return $this->groupCache[$name] = $g;
            }
        }
        $group = new FlowGroup();
        $group->setWorkspace($workspace);
        $group->setName($name);
        $this->em->persist($group);

        return $this->groupCache[$name] = $group;
    }

    private function ensureVar(\App\Entity\Environment $env, string $name, string $value): void
    {
        foreach ($env->getVariables() as $v) {
            if ($v->getName() === $name) {
                return;
            }
        }
        $var = new EnvVariable();
        $var->setName($name);
        $var->setValue($value);
        $var->setSecret(false);
        $env->addVariable($var);
        $this->em->persist($var);
    }

    private function flowExists(Workspace $workspace, string $name): bool
    {
        foreach ($this->flows->findByWorkspace($workspace) as $f) {
            if ($f->getName() === $name) {
                return true;
            }
        }

        return false;
    }
}
