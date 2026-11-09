<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Take a recipe out of use, or put it back.
 * Input: recipe (int), active (bool).
 */
final class SetRecipeActive implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id = (int) ($input['recipe'] ?? 0);
        $r  = $id > 0 ? ManufacturingState::recipe($ctx->cms, $id) : null;
        if ($r === null) {
            throw new WriteRefused('That recipe was not found in CMS.');
        }
        $active = in_array($input['active'] ?? null, [true, 1, '1', 'on', 'true'], true);
        $name   = trim($r['RecipeNumber']) !== '' ? $r['RecipeNumber'] : (string) $id;
        if ($active === !$r['Inactive']) {
            throw new WriteRefused("Recipe {$name} is already " . ($active ? 'active.' : 'inactive.'));
        }
        $request = ManufacturingRequests::setRecipeInactive($r['Recipe'], $r['Version'], !$active);

        return new Plan(
            Sequence::single(($active ? 'Make recipe ' : 'Retire recipe ') . $name . ($active ? ' active' : ''), static fn () => $request),
            [
                ['Recipe', trim("{$name} {$r['Comment']}")],
                ['Result', $active ? 'Active again: tickets can be cut from it' : 'Inactive: no new tickets can be cut from it'],
            ],
            $active || !$r['IsPublished'] ? [] : ['Open tickets already cut from this recipe are not changed.'],
            'Recipe',
            (string) $r['Recipe'],
        );
    }
}
