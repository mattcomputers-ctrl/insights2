<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Qc;

/** ReleaseDataSet.Reject — record the tested sublot as rejected. */
final class RejectSublot extends Disposition
{
    protected function approve(): bool
    {
        return false;
    }
}
