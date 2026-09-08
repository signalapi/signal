<?php

namespace App\Service;

use App\Entity\Environment;
use App\Entity\FlowStep;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Repository\ApiRequestRepository;
use App\Repository\FlowStepRepository;
use App\Repository\TestFlowRepository;

/**
 * Turns a plain-language brief into a draft test flow. Claude gets the
 * workspace's request catalog and the chosen environment's variable NAMES
 * (never values — secrets stay home), answers with a strict JSON plan, and the
 * plan is validated step by step: anything malformed is dropped with a warning
 * instead of failing the whole generation. The result is a draft — the UI
 * sends the user to review it before the first run.
 */
class AiFlowArchitect
{
    private const MAX_CATALOG = 80;
    private const MAX_STEPS = 25;
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly AiDiagnoser $ai,
        private readonly ApiRequestRepository $requests,
        private readonly TestFlowRepository $flows,
        private readonly FlowStepRepository $steps,
        private readonly FlowExpressionParser $parser,
    ) {
    }

    /**
     * @return array{flow: TestFlow, warnings: list<string>, stepCount: int}
     */
    public function generate(Workspace $workspace, string $brief, ?Environment $environment, string $locale = 'en'): array
    {
        $plan = $this->plan($workspace, $brief, $environment, $locale);

        $flow = new TestFlow();
        $flow->setWorkspace($workspace);
        $flow->setName(mb_substr(trim((string) ($plan['name'] ?? '')) ?: 'AI draft flow', 0, 150));
        $description = trim((string) ($plan['description'] ?? ''));
        $flow->setDescription('' !== $description ? $description . "\n\n[AI draft — review before relying on it]" : '[AI draft — review before relying on it]');
        $flow->setStopOnFailure((bool) ($plan['stopOnFailure'] ?? true));
        if (null !== $environment) {
            $flow->setDefaultEnvironment($environment);
        }
        $this->flows->save($flow);

        $warnings = [];
        $position = 0;
        foreach (\array_slice((array) ($plan['steps'] ?? []), 0, self::MAX_STEPS) as $i => $raw) {
            if (!\is_array($raw)) {
                $warnings[] = sprintf('Step %d was not an object and was dropped.', $i + 1);
                continue;
            }
            $step = $this->buildStep($flow, $raw, $i, $warnings);
            if (null === $step) {
                continue;
            }
            $step->setPosition($position++);
            $this->steps->save($step);
        }

        if (0 === $position) {
            // A flow with no steps helps nobody; surface it loudly.
            $warnings[] = 'Claude returned no usable steps — the flow is empty.';
        }

        return ['flow' => $flow, 'warnings' => $warnings, 'stepCount' => $position];
    }

    /**
     * @return array<string, mixed> the decoded plan
     */
    private function plan(Workspace $workspace, string $brief, ?Environment $environment, string $locale): array
    {
        $catalog = [];
        foreach (\array_slice($this->requests->findByWorkspace($workspace), 0, self::MAX_CATALOG) as $request) {
            $catalog[] = [
                'name' => $request->getName(),
                'method' => $request->getMethod(),
                'url' => $request->getUrl(),
            ];
        }

        $variableNames = [];
        if (null !== $environment) {
            foreach ($environment->getVariables() as $variable) {
                $variableNames[] = $variable->getName();
            }
        }

        $language = 'tr' === $locale ? 'Turkish' : 'English';
        $system = <<<PROMPT
            You design API test flows. Answer with ONE JSON object and nothing else — no markdown fences, no prose.

            Schema:
            {"name": string, "description": string, "stopOnFailure": bool,
             "steps": [
               {"type":"http","name":string,"method":"GET|POST|...","url":string,
                "headers":{...}, "params":{...}, "bodyMode":"none|json|raw|form", "body":string|null,
                "assertions":[string], "extractions":[string]},
               {"type":"setvar","name":string,"assignments":[string]},
               {"type":"delay","name":string,"ms":int}
             ]}

            Rules:
            - assertions are single lines: "<path> <op> <value>". <path> is "status", "responseTime" or a JSON path into the response body like "meta.errorCode" or "result.items.0.id". Operators: == != > < >= <= contains matches exists empty notEmpty (the last three take no value). Example: "status == 200", "result.token notEmpty".
            - extractions are single lines: "varName = json.path" — the variable is usable as {{varName}} in later steps.
            - setvar assignments are single lines: "name = value" (value may use generators).
            - Reference variables as {{name}}. Built-in generators: {{\$guid}}, {{\$timestamp}}, {{\$isoTimestamp}}, {{\$isoDate}}, {{\$randomInt}}, {{\$randomEmail}}.
            - Prefer URLs and shapes from the provided request catalog; keep the base URL as a {{variable}} when the catalog does.
            - Every http step needs at least one assertion. Chain values between steps with extractions instead of hardcoding.
            - Step names and the flow name/description in {$language}.
            PROMPT;

        $user = json_encode([
            'brief' => $brief,
            'requestCatalog' => $catalog,
            'environmentVariables' => $variableNames,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $text = trim($this->ai->completeText($system, (string) $user, 4000));
        // Belt and braces: strip a markdown fence if the model added one anyway.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/', '', $text) ?? $text;
        }

        $plan = json_decode($text, true);
        if (!\is_array($plan)) {
            throw new \RuntimeException('Claude did not return valid JSON. Try rephrasing the brief.');
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>         $warnings
     */
    private function buildStep(TestFlow $flow, array $raw, int $index, array &$warnings): ?FlowStep
    {
        $type = (string) ($raw['type'] ?? 'http');
        $step = new FlowStep();
        $step->setFlow($flow);

        if ('delay' === $type) {
            $ms = max(0, min(60000, (int) ($raw['ms'] ?? 0)));
            $step->setType(FlowStep::TYPE_DELAY);
            $step->setQuery((string) $ms);
            $step->setName((string) ($raw['name'] ?? 'Wait ' . $ms . ' ms'));

            return $step;
        }

        if ('setvar' === $type) {
            $assignments = implode("\n", array_map(strval(...), (array) ($raw['assignments'] ?? [])));
            if ('' === trim($assignments)) {
                $warnings[] = sprintf('Step %d (setvar) had no assignments and was dropped.', $index + 1);

                return null;
            }
            $step->setType(FlowStep::TYPE_SETVAR);
            $step->setQuery($assignments);
            $step->setName((string) ($raw['name'] ?? 'Set variables'));

            return $step;
        }

        if ('http' !== $type) {
            $warnings[] = sprintf('Step %d used unsupported type "%s" and was dropped.', $index + 1, $type);

            return null;
        }

        $url = trim((string) ($raw['url'] ?? ''));
        if ('' === $url) {
            $warnings[] = sprintf('Step %d had no URL and was dropped.', $index + 1);

            return null;
        }
        $method = strtoupper((string) ($raw['method'] ?? 'GET'));
        if (!\in_array($method, self::METHODS, true)) {
            $warnings[] = sprintf('Step %d: method "%s" replaced with GET.', $index + 1, $method);
            $method = 'GET';
        }

        $step->setType(FlowStep::TYPE_HTTP);
        $step->setName((string) ($raw['name'] ?? $method . ' request'));
        $step->setReqMethod($method);
        $step->setReqUrl($url);
        $step->setReqHeaders($this->pairs($raw['headers'] ?? []));
        $step->setReqParams($this->pairs($raw['params'] ?? []));
        $mode = (string) ($raw['bodyMode'] ?? (isset($raw['body']) ? 'json' : 'none'));
        $step->setReqBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
        $body = $raw['body'] ?? null;
        $step->setReqBody(null === $body ? null : (\is_string($body) ? $body : (string) json_encode($body, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
        $step->setExtractions($this->parser->parseExtractions(implode("\n", array_map(strval(...), (array) ($raw['extractions'] ?? [])))));
        $step->setAssertions($this->parser->parseAssertions(implode("\n", array_map(strval(...), (array) ($raw['assertions'] ?? [])))));
        if ([] === $step->getAssertions()) {
            $warnings[] = sprintf('Step %d ("%s") ended up with no valid assertions — add one before trusting it.', $index + 1, $step->getName());
        }

        return $step;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function pairs(mixed $input): array
    {
        $out = [];
        foreach ((array) $input as $name => $value) {
            $name = trim((string) $name);
            if ('' !== $name) {
                $out[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return $out;
    }
}
