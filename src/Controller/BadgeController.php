<?php

namespace App\Controller;

use App\Entity\FlowGroupRun;
use App\Repository\FlowGroupRepository;
use App\Repository\FlowGroupRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, login-less SVG status badge for a suite — the thing a README embeds.
 * The token is a dedicated one-bit credential (see FlowGroup::$badgeToken):
 * whoever holds it learns only "passing / failing / running", never data.
 */
class BadgeController extends AbstractController
{
    #[Route('/badge/{token}.svg', name: 'suite_badge', methods: ['GET'])]
    public function badge(string $token, FlowGroupRepository $groups, FlowGroupRunRepository $groupRuns): Response
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw $this->createNotFoundException();
        }
        $group = $groups->findOneBy(['badgeToken' => $token]);
        if (null === $group) {
            throw $this->createNotFoundException();
        }

        $last = $groupRuns->recentForGroup($group, 3);
        $status = 'unknown';
        foreach ($last as $run) {
            if (FlowGroupRun::STATUS_RUNNING !== $run->getStatus()) {
                $status = $run->getStatus();
                break;
            }
        }
        if ('unknown' === $status && [] !== $last) {
            $status = 'running';
        }

        [$label, $color] = match ($status) {
            FlowGroupRun::STATUS_PASSED => ['passing', '#2EA44F'],
            FlowGroupRun::STATUS_FAILED => ['failing', '#D5372F'],
            'running' => ['running', '#3E7BFA'],
            default => ['no runs', '#8B949E'],
        };

        $response = new Response($this->svg($group->getName(), $label, $color), 200, [
            'Content-Type' => 'image/svg+xml',
            // GitHub fetches through camo; keep it fresh enough to be useful.
            'Cache-Control' => 'no-cache, max-age=300',
        ]);

        return $response;
    }

    /**
     * A flat shields-style badge. Width is estimated from character count —
     * good enough for the two short texts a badge carries.
     */
    private function svg(string $name, string $label, string $color): string
    {
        $name = mb_substr($name, 0, 40);
        $leftW = (int) round(mb_strlen($name) * 6.7) + 14;
        $rightW = (int) round(mb_strlen($label) * 6.9) + 14;
        $w = $leftW + $rightW;
        $nameEsc = htmlspecialchars($name, \ENT_XML1);
        $labelEsc = htmlspecialchars($label, \ENT_XML1);

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="20" role="img" aria-label="{$nameEsc}: {$labelEsc}">
              <linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
              <clipPath id="r"><rect width="{$w}" height="20" rx="3" fill="#fff"/></clipPath>
              <g clip-path="url(#r)">
                <rect width="{$leftW}" height="20" fill="#555"/>
                <rect x="{$leftW}" width="{$rightW}" height="20" fill="{$color}"/>
                <rect width="{$w}" height="20" fill="url(#s)"/>
              </g>
              <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
                <text x="{$this->half($leftW)}" y="14">{$nameEsc}</text>
                <text x="{$this->rightMid($leftW, $rightW)}" y="14">{$labelEsc}</text>
              </g>
            </svg>
            SVG;
    }

    private function half(int $w): string
    {
        return (string) (int) round($w / 2);
    }

    private function rightMid(int $left, int $right): string
    {
        return (string) (int) round($left + $right / 2);
    }
}
