<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/** One request of a Sequence. See Sequence::stage(). */
final class Stage
{
    public function __construct(
        public readonly string $name,
        public readonly \Closure $build,
        public readonly ?\Closure $alreadyDone = null,
    ) {}
}
