<?php

namespace App\Service;

use App\Entity\FlowStep;
use App\Entity\TestFlow;

/**
 * Works out which {{variables}} a flow needs the caller to supply — i.e. the
 * ones referenced somewhere in the steps but NOT produced within the flow
 * (by an extraction or a set-variable step) and NOT dynamic ({{$randomEmail}}).
 *
 * This turns the raw "variables JSON" / "dataset" inputs into a guided,
 * variable-aware form: we know exactly which fields to ask for.
 */
class FlowVariableScanner
{
    private const PLACEHOLDER = '/\{\{\s*(\$?[\w.\-]+)\s*\}\}/';

    /**
     * @return array<int, array{name: string, fromEnv: bool, envValue: ?string}>
     */
    public function externalVariables(TestFlow $flow): array
    {
        $used = [];
        $produced = [];

        foreach ($flow->getSteps() as $step) {
            foreach ($step->getExtractions() as $ex) {
                if (!empty($ex['var'])) {
                    $produced[$ex['var']] = true;
                }
            }
            if ($step->isSetvar()) {
                // Each "name = value" line produces `name`; the value may use vars.
                foreach (preg_split('/\r\n|\r|\n/', (string) $step->getQuery()) ?: [] as $line) {
                    if (str_contains($line, '=')) {
                        $name = trim(explode('=', $line, 2)[0]);
                        if ('' !== $name) {
                            $produced[$name] = true;
                        }
                    }
                }
            }

            foreach ($this->stepTexts($step) as $text) {
                if (preg_match_all(self::PLACEHOLDER, $text, $m)) {
                    foreach ($m[1] as $name) {
                        $used[$name] = true;
                    }
                }
            }
        }

        $envMap = null !== $flow->getDefaultEnvironment() ? $flow->getDefaultEnvironment()->toMap() : [];

        $out = [];
        foreach (array_keys($used) as $name) {
            if (str_starts_with($name, '$') || isset($produced[$name])) {
                continue; // dynamic, or produced inside the flow
            }
            $out[] = [
                'name' => $name,
                'fromEnv' => \array_key_exists($name, $envMap),
                'envValue' => $envMap[$name] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return string[] every text fragment of a step that may contain {{vars}}
     */
    private function stepTexts(FlowStep $step): array
    {
        $texts = [(string) $step->getQuery()];

        if (!$step->isDb() && !$step->isSetvar() && !$step->isDelay()) {
            $texts[] = $step->getReqUrl();
            $texts[] = (string) $step->getReqBody();
            foreach ([$step->getReqHeaders(), $step->getReqParams()] as $pairs) {
                foreach ($pairs as $p) {
                    $texts[] = (string) ($p['name'] ?? '');
                    $texts[] = (string) ($p['value'] ?? '');
                }
            }
            foreach ($step->getReqAuth() as $v) {
                if (\is_scalar($v)) {
                    $texts[] = (string) $v;
                }
            }
        }

        foreach ($step->getAssertions() as $a) {
            $texts[] = (string) ($a['expected'] ?? '');
        }

        return $texts;
    }
}
