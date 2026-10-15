<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Security\AuthenticatedPublicKey;
use PDF\Security\PublicKeySubFilter;

/** Document permissions from authenticated default-filter envelopes, never the legacy Encrypt.P. */
final readonly class PublicKeyReadPermissions implements DocumentPermissions
{
    /** @var array<string, PublicKeyRecipientAccess> */
    public array $filters;

    /** @param array<string, AuthenticatedPublicKey> $authenticatedFilters */
    public function __construct(array $authenticatedFilters, public PublicKeySubFilter $subFilter = PublicKeySubFilter::Pkcs7S5)
    {
        $filters = [];
        foreach ($authenticatedFilters as $name => $authenticated) {
            if ($authenticated->permissions === null) {
                throw new \InvalidArgumentException('Document permissions require a document-scoped recipient envelope.');
            }
            $filters[$name] = new PublicKeyRecipientAccess($authenticated->permissions, $authenticated->recipientIndex, $subFilter);
        }
        $this->filters = $filters;
    }

    /** With distinct document filters, grant only rights common to all authenticated lists. */
    public function effectiveMask(): int
    {
        $mask = -4;
        foreach ($this->filters as $filter) {
            $mask &= $filter->effectiveMask();
        }
        return $mask;
    }
}
