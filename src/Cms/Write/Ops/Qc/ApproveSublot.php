<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Qc;

/** ReleaseDataSet.Approve — release the tested sublot and complete the ticket's testing step. */
final class ApproveSublot extends Disposition
{
    protected function approve(): bool
    {
        return true;
    }
}
