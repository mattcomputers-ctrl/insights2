<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

/**
 * Save a new count and post it in one go: two requests, the way the
 * client sends "save" and then "post". Input as CreateCount.
 */
final class CreateAndPostCount extends CreateCount
{
    protected const POST = true;
}
