<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Insights itself declined to build or send a request: bad input, the
 * record is not in a state the operation applies to, the user has no CMS
 * identity, the operation is not allowed. The message is shown to the user.
 */
final class WriteRefused extends \RuntimeException
{
}
