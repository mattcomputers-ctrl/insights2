<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/** What a Flow is given besides its input. */
final class FlowContext
{
    /**
     * @param string $cmsUser the acting user's CMS identity (e.g. pic\jsmith); also goes
     *                        into fields such as TestedBy / ReleasedBy
     * @param \DateTimeImmutable $now the moment of the user's action (fixed at preview)
     */
    public function __construct(
        public readonly CmsReader $cms,
        public readonly string $cmsUser,
        public readonly \DateTimeImmutable $now,
        public readonly string $owner = '4',
    ) {}
}
