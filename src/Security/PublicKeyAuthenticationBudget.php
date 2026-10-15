<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;

/** Shared by every eager or deferred crypt filter of exactly one source document. */
final class PublicKeyAuthenticationBudget
{
    private int $filters = 0;
    private int $recipients = 0;
    private int $recipientBytes = 0;
    private int $attempts = 0;

    public function __construct(private readonly PublicKeyAuthenticationLimits $limits)
    {
    }

    public function filter(): void
    {
        if ($this->filters >= $this->limits->maxFilters) {
            throw new ParseException('The cumulative public-key crypt-filter limit was exceeded.');
        }
        ++$this->filters;
    }

    public function recipient(int $bytes): void
    {
        if ($this->recipients >= $this->limits->maxRecipients || $bytes < 1
            || $bytes > $this->limits->maxRecipientBytes
            || $bytes > $this->limits->maxTotalRecipientBytes - $this->recipientBytes) {
            throw new ParseException('The public-key recipient count or byte limit was exceeded.');
        }
        ++$this->recipients;
        $this->recipientBytes += $bytes;
    }

    public function attempt(): void
    {
        if ($this->attempts >= $this->limits->maxCredentialAttempts) {
            throw new ParseException('The cumulative public-key credential-attempt limit was exceeded.');
        }
        ++$this->attempts;
    }
}
