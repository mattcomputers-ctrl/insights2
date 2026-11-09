<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * The record changed in CMS between the preview and the confirm click, so
 * what would be sent now is not what the user approved. Carries the fresh
 * preview to show them instead.
 */
final class StalePreview extends \RuntimeException
{
    public function __construct(public readonly Preview $fresh)
    {
        parent::__construct('CMS changed since this was previewed. Check the updated request and confirm again.');
    }
}
