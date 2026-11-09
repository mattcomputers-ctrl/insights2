<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingInput;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingState;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\WriteRefused;

/**
 * Pack a made batch out through one of its packaging tickets: packages
 * are filled from the vessel and the packaged item is made into stock.
 * The components and the bulk drawn follow the packaging ticket's own
 * proportions. A packout is several requests, as the floor screen sends
 * them; the ones CMS already shows as done are left out.
 * Input: ordr (int, the bulk ticket), pack (int, its packaging ticket),
 * qty (number made, in the packaged item's unit), packages (whole number
 * of packages filled), package (optional item code of the package filled;
 * blank for NA, which takes no package from stock).
 */
final class Packout implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms = $ctx->cms;
        $t   = ExecutionState::bulk($cms, $input);
        ExecutionState::made($t);
        if ($t['packedHere']) {
            throw new WriteRefused("Ticket {$t['Ordr']} was packed directly, without a packaging ticket. Pack it in CMS.");
        }
        if ($t['vessel'] === null || $t['sublot'] === null) {
            throw new WriteRefused("The vessel or the lot of ticket {$t['Ordr']} was not found. Pack it in CMS.");
        }
        $p = ExecutionState::pack($cms, $t, (int) ($input['pack'] ?? 0));
        if (in_array($p['Status'], ['CMP', 'CLS'], true)) {
            throw new WriteRefused("Packaging ticket {$p['Ordr']} is " . ManufacturingInput::statusText($p) . ' already.');
        }
        $made  = ManufacturingInput::quantity($input['qty'] ?? '', 'Quantity made');
        $count = trim((string) ($input['packages'] ?? ''));
        if (!ctype_digit($count) || (int) $count < 1 || (int) $count > 100000) {
            throw new WriteRefused('Enter the number of packages filled, as a whole number.');
        }
        $count   = (string) (int) $count;
        $package = self::package($cms, trim((string) ($input['package'] ?? '')));

        // What one unit of the packaged item takes, from the packaging ticket's own lines.
        $per        = $made / (float) $p['product']['qty'];
        $draw       = (float) ManufacturingState::qty($p['bulkLine']['qty'] * $per);
        $components = [];
        $list       = [];
        $noCost     = [];
        $bulkCost   = $t['cost']['unitCost'] ?? $p['bulkLine']['cost'] ?? 0.0;
        $value      = $draw * $bulkCost;
        $seen       = [(int) $t['product']['item'] => true];
        foreach ($p['components'] as $c) {
            // The client sends what is used in item order; no logged packout has the same item on two lines.
            if (isset($seen[$c['item']])) {
                throw new WriteRefused("Packaging ticket {$p['Ordr']} has {$c['code']} on more than one line. Pack it in CMS.");
            }
            $seen[$c['item']] = true;
            $q = (float) ManufacturingState::qty(($c['qty'] ?? 0.0) * $per);
            if ($q <= 0.0) {
                continue;
            }
            $components[] = ['line' => $c['id'], 'item' => $c['item'], 'qty' => ManufacturingState::qty($q)];
            $list[]       = ManufacturingState::qty($q) . " {$c['unit']} {$c['code']}";
            if ($c['cost'] === null || $c['cost'] <= 0.0) {
                $noCost[] = $c['code'];
            }
            $value += $q * ($c['cost'] ?? 0.0);
        }
        $madeText = ManufacturingState::qty($made);
        $pack     = ['ordr' => $p['Ordr'], 'line' => $p['product']['id'], 'item' => (int) $p['product']['item']];
        if ($package !== null) {
            $pack['package'] = $package['item'];
        }
        $bulk     = ['line' => $p['bulkLine']['id'], 'item' => (int) $t['product']['item'], 'sublot' => $t['sublot'],
                     'vessel' => $t['vessel']['id'], 'qty' => ManufacturingState::qty($draw)];

        $later = $p['packouts'] !== [];
        $cost  = null;
        if ($later) {
            if ($p['container'] === null || $p['cost'] === null) {
                throw new WriteRefused("Packaging ticket {$p['Ordr']} has been packed before, but its container or cost record was not found. Pack it in CMS.");
            }
        } else {
            $cost = ManufacturingState::qty(round($value / $made, 6));
        }

        $seq     = new Sequence("Pack out batch ticket {$t['Ordr']} into {$p['product']['code']}");
        $stageNo = 0;
        $version = static fn (SequenceState $s, int $ordr, int $known): int
            => $s->preview ? $known : ManufacturingState::version($cms, $ordr);

        $bulkProduct = ExecutionState::step($t, $t['product']['id']);
        if (($bulkProduct['status'] ?? '') !== 'STD') {
            $line = $t['product']['id'];
            $seq->stage('Open the packaging phase', static fn (): Request => ExecutionRequests::executePackagingPhase($line));
            $stageNo++;
        }
        if (ManufacturingInput::unreleased($p)) {
            $lot = $p['hasLot'] ? null : ['item' => $pack['item'], 'productLine' => $pack['line'],
                'lotPrefix' => ManufacturingInput::lotPrefix($ctx->now), 'user' => $ctx->cmsUser,
                'manfDate' => $p['PlanStartDate'] ?? Fmt::dateTime($ctx->now)];
            $day = Fmt::date($ctx->now);
            $seq->stage("Release packaging ticket {$p['Ordr']}", static fn (SequenceState $s): Request
                => ExecutionRequests::releasePack($p['Ordr'], $version($s, $p['Ordr'], $p['Version']), $day, $lot));
            $stageNo++;
        }
        $seq->stage('Ask for the packout', static fn (): Request => ExecutionRequests::packoutRequested($t['Ordr'], $pack['item']));
        $stageNo++;

        $record = null;
        if ($p['changeSet'] === null) {
            $owner = $p['Owner'];
            $seq->stage("Open the production record of packaging ticket {$p['Ordr']}", static fn (SequenceState $s): Request
                => ExecutionRequests::createChangeSet($p['Ordr'], $version($s, $p['Ordr'], $p['Version']), $owner));
            $record = ++$stageNo;
        }
        $seq->stage('Pack out', static function (SequenceState $s) use ($t, $p, $pack, $count, $components, $bulk, $madeText, $later, $cost, $record, $version): Request {
            $into = $later
                ? ['container' => $p['container']['id'], 'inventoryCost' => $p['cost']['id']]
                : ['changeSet' => $record === null ? $p['changeSet']
                        : $s->output($record, ExecutionRequests::STEP_CHANGE_SET, 'ChangeSet', 'ChangeSet'),
                   'unitCost' => $cost];
            return ExecutionRequests::packaging($t['Ordr'], $version($s, $t['Ordr'], $t['Version']), $t['Owner'], $pack, $count,
                $components, $bulk, $madeText, $into);
        });

        $unit    = $p['product']['unit'];
        $summary = [
            ['Batch ticket', "{$t['Ordr']}: {$t['product']['code']} {$t['product']['description']}"],
            ['Packaging ticket', "{$p['Ordr']}: {$p['product']['code']} {$p['product']['description']}"
                . (ManufacturingInput::unreleased($p) ? ' (released first)' : '')],
            ['Made into stock', "{$madeText} {$unit} in {$count} package" . ($count === '1' ? '' : 's')],
            ['Drawn from vessel ' . $t['vessel']['code'], "{$bulk['qty']} lb of {$t['product']['code']}"],
        ];
        if ($package !== null) {
            $summary[] = ['Packages taken from stock', trim("{$count} {$package['unit']}") . " of {$package['code']} {$package['description']}"];
        }
        if ($list !== []) {
            $summary[] = ['Components used', implode(', ', $list)];
        }
        if ($cost !== null) {
            $summary[] = ['Estimated cost', "{$cost} per {$unit}, from the batch cost and the components' replacement costs"];
        }
        $warnings = [];
        $planned  = (float) $p['product']['qty'];
        if ($made > $planned + 1e-9) {
            $warnings[] = "More than the packaging ticket asks for ({$madeText} against " . ManufacturingState::qty($planned) . " {$unit}).";
        }
        if ($later) {
            $warnings[] = 'This packaging ticket has been packed before; this adds another packout to it.';
        }
        if ($cost !== null && $noCost !== []) {
            $warnings[] = 'No replacement cost in CMS for ' . implode(', ', $noCost) . ': the estimated cost leaves them out.';
        }

        return new Plan($seq, $summary, $warnings, 'Ordr', (string) $t['Ordr']);
    }

    /**
     * The package item picked for this packout, or null for NA. In the
     * client it is the user's choice at each packout, not something the
     * ticket holds.
     *
     * @return null|array{item:int,code:string,description:string,unit:string}
     */
    private static function package(CmsReader $cms, string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        $i = $cms->fetch(
            'SELECT i.Item, i.ItemCode, i.Description, i.Unit, i.Status, i.Context FROM CMS.dbo.Item i WHERE i.ItemCode = ?', [$code]);
        if ($i === null) {
            throw new WriteRefused("Package {$code} was not found in CMS.");
        }
        if (strtoupper(trim((string) ($i['Context'] ?? ''))) !== 'PACKAGE') {
            throw new WriteRefused("{$i['ItemCode']} is not a package.");
        }
        if (strtoupper(trim((string) ($i['Status'] ?? ''))) === 'OBS') {
            throw new WriteRefused("Package {$i['ItemCode']} is obsolete in CMS.");
        }
        if ((string) (int) $i['Item'] === ExecutionRequests::PACKAGE_ITEM) {
            return null;
        }
        return ['item' => (int) $i['Item'], 'code' => (string) $i['ItemCode'], 'description' => (string) ($i['Description'] ?? ''),
                'unit' => trim((string) ($i['Unit'] ?? ''))];
    }
}
