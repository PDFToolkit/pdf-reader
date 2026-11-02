<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

/** Cumulative snapshot accounting. Failed reservations produce explicit partial results. */
final class InspectionBudget
{
    /** @var array<string, int> */
    private array $used = [];
    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];
    /** @var array<string, true> */
    private array $reported = [];
    public bool $truncated = false;

    public function __construct(public readonly InspectionLimits $limits)
    {
    }

    public function reserve(string $kind, int $amount = 1): bool
    {
        $maximum = match ($kind) {
            'nodes' => $this->limits->maxNodes,
            'objects' => $this->limits->maxObjects,
            'values' => $this->limits->maxValues,
            'bytes' => $this->limits->maxBytes,
            'associations' => $this->limits->maxAssociations,
            default => throw new \LogicException('Unknown inspection budget.'),
        };
        $used = $this->used[$kind] ?? 0;
        if ($amount < 0 || $amount > $maximum - $used) {
            $this->limited('limit-' . $kind, 'The cumulative inspection ' . $kind . ' limit was reached.');
            return false;
        }
        $this->used[$kind] = $used + $amount;
        return true;
    }

    public function depth(int $depth): bool
    {
        if ($depth > $this->limits->maxDepth) {
            $this->limited('limit-depth', 'The inspection depth limit was reached.');
            return false;
        }
        return true;
    }

    public function limited(string $code, string $message): void
    {
        $this->truncated = true;
        if (!isset($this->reported[$code])) {
            $this->reported[$code] = true;
            $this->diagnostic($code, $message);
        }
    }

    /** @param array{number: string, generation: int}|null $reference */
    public function diagnostic(string $code, string $message, ?string $nodeId = null, ?array $reference = null): void
    {
        if (count($this->diagnostics) >= $this->limits->maxDiagnostics) {
            $this->truncated = true;
            $this->diagnostics[$this->limits->maxDiagnostics - 1] = ['code' => 'limit-diagnostics', 'message' => 'The inspection diagnostic limit was reached; further diagnostics were omitted.', 'nodeId' => null, 'reference' => null];
            return;
        }
        $this->diagnostics[] = ['code' => $code, 'message' => $message, 'nodeId' => $nodeId, 'reference' => $reference];
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
