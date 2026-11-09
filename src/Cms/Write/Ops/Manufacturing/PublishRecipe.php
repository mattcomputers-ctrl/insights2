<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Publish a bulk recipe that was drafted in CMS, so tickets can be cut
 * from it. Input: recipe (int).
 */
final class PublishRecipe implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id = (int) ($input['recipe'] ?? 0);
        $r  = $id > 0 ? ManufacturingState::recipe($ctx->cms, $id) : null;
        if ($r === null) {
            throw new WriteRefused('That recipe was not found in CMS.');
        }
        $name = trim($r['RecipeNumber']) !== '' ? $r['RecipeNumber'] : (string) $id;
        if ($r['IsPublished']) {
            throw new WriteRefused("Recipe {$name} is already published.");
        }
        if ($r['Inactive']) {
            throw new WriteRefused("Recipe {$name} is inactive. Make it active first.");
        }
        if ($r['Context'] !== 'RMBA') {
            throw new WriteRefused("Recipe {$name} is not a bulk recipe. Publish it in CMS.");
        }
        $by  = ['BA' => [], 'PK' => [], 'UB' => [], 'UI' => []];
        $alt = [0];
        foreach ($r['lines'] as $l) {
            $by[$l['context']][] = $l;
            $alt[] = $l['altId'] ?? 0;
        }
        if (count($by['BA']) !== 1 || count($by['PK']) !== 1 || $by['UB'] !== [] || $by['BA'][0]['altId'] === null) {
            throw new WriteRefused("Recipe {$name} does not have exactly one batch and one product, or is partly published. Publish it in CMS.");
        }
        if ($by['UI'] === []) {
            throw new WriteRefused("Recipe {$name} has no ingredients.");
        }
        $pk = $by['PK'][0];
        if ($pk['item'] === null) {
            throw new WriteRefused("Recipe {$name} has no product item.");
        }
        $total = array_sum(array_map(static fn (array $l): float => (float) ($l['qty'] ?? 0.0), $by['UI']));
        $warnings = [];
        if ($pk['qty'] !== null && abs($total - $pk['qty']) > 0.001 * max(1.0, $pk['qty'])) {
            $warnings[] = 'The ingredients add up to ' . ManufacturingState::qty($total) . ' for '
                . ManufacturingState::qty($pk['qty']) . " {$pk['unit']} of product.";
        }
        foreach ($by['UI'] as $l) {
            if ($l['obsolete']) {
                $warnings[] = "Ingredient {$l['code']} is obsolete in CMS.";
            }
        }

        $request = ManufacturingRequests::publishRecipe($r['Recipe'], $r['Version'], $r['Owner'], $pk['id'],
            $by['BA'][0]['id'], $by['BA'][0]['altId'], max($alt) + 1);

        return new Plan(
            Sequence::single("Publish recipe {$name}", static fn () => $request),
            [
                ['Recipe', trim("{$name} {$r['Comment']}")],
                ['Makes', $pk['code']],
                ['Ingredients', (string) count($by['UI'])],
                ['Result', 'Published: tickets can be cut from it'],
            ],
            $warnings,
            'Recipe',
            (string) $r['Recipe'],
        );
    }
}
