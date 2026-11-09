<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Carries one rendered request to CMS (or not).
 *
 * Implementations: DryRunTransport (default; sends nothing),
 * FakeTransport (tests; scripted outcomes), LiveTransport (calls
 * ProcessXMLRequest over the separate write connection).
 */
interface Transport
{
    /** 'dry-run' | 'fake' | 'live' — recorded in the audit table. */
    public function mode(): string;

    /**
     * @param string $xml     the complete <request> document
     * @param array{application:string,action:string,workstation:string,transDate:string} $meta
     *        envelope fields, used by the live transport to find the Log row
     *        when the call errors before returning its id
     */
    public function send(string $xml, array $meta): TransportResult;

    /**
     * Next number from one of CMS's own counters (the same procedure the
     * CMS client calls, e.g. the sales order change number). Null when this
     * transport sends nothing, in which case the request shows a placeholder.
     *
     * @throws WriteRefused when CMS could not hand out a number
     */
    public function generateId(string $kind, int $min, int $max): ?string;
}
