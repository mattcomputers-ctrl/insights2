<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PII\Cms\Write\FakeCmsReader;
use PII\Cms\Write\FakeTransport;
use PII\Cms\Write\Ops\MasterData\ItemRequests as MR;

/* ── Golden: templates reproduce the logged requests byte for byte ── */

t_section('Alias golden: ItemNameUpdateDataSet.SaveChanges');
$alShapes = [];
foreach (cms_fixture_ids(MR::ALIAS_SAVE) as $log) {
    $xml = cms_fixture(MR::ALIAS_SAVE, $log);
    $f   = cms_fixture_parse($xml);
    $s   = $f['steps'];
    t_same(MR::CMS_ALIAS, $f['program'], "log {$log}: sent by the client's alias screen");
    if ($s[0]['operation'] === 'Insert') {
        $d = $s[0]['data'];
        $alShapes['create'] = true;
        t_xml_same($xml, MR::aliasCreate($d['ItemCode'], $d['Description'], $d['ReplacedBy'], $d['CreateUser'], $d['CreateArea'])->toXml($f['env']), "log {$log} (create)");
    } elseif ($s[0]['operation'] === 'Update') {
        $alShapes['edit ' . implode('+', array_keys($s[0]['data']))] = true;
        t_xml_same($xml, MR::aliasEdit($s[0]['keys']['Item'], $s[0]['keys']['Version'], $s[0]['data'])->toXml($f['env']), "log {$log} (edit)");
    } else {
        // Deleting an alias is left to the client (three in the log); the fixture documents the shape.
        $alShapes['delete (not built)'] = true;
        t_same(['DeleteInventoryCostByItem', 'Item'], array_column($s, 'object'), "log {$log}: delete shape");
    }
}
ksort($alShapes);
t_same(['create', 'delete (not built)', 'edit Description', 'edit ReplacedBy'], array_keys($alShapes), 'fixtures cover create and edit');
t_same(['ItemCode', 'Description', 'ReplacedBy'], array_keys(MR::aliasEdit(5, 1, ['ReplacedBy' => 7, 'Description' => 'D', 'ItemCode' => 'C'])->steps()[0]->first('data')),
    'several columns go in the order the client sends them');
t_check(t_throws(\InvalidArgumentException::class, static fn () => MR::aliasEdit(5, 1, [])) !== null, 'an edit without changes is not built');
t_check(t_throws(\InvalidArgumentException::class, static fn () => MR::aliasEdit(5, 1, ['Context' => 'PP'])) !== null, 'an edit of any other column is not built');

/* ── Flows ── */

$alPerms  = ['write.item.alias'];
$alWriter = static fn (FakeCmsReader $cms, $transport, ?array $perms = null): \PII\Cms\Write\CmsWriter => new \PII\Cms\Write\CmsWriter(
    $cms, $transport, static fn (string $p) => in_array($p, $perms ?? $alPerms, true), 'pic\\user1', 'WS1', true, '7.15.0.680',
    static function (array $row): void {});
$alNorm = static fn (string $xml): string => preg_replace(['/Application="[^"]+"/', '/ TransDate="[^"]+"/'], ['Application="A"', ' TransDate="T"'], $xml);
$alRefused = static function (callable $fn, string $needle, string $label): void {
    $e = t_throws(\PII\Cms\Write\WriteRefused::class, $fn);
    t_check($e !== null && str_contains($e->getMessage(), $needle),
        "refused: {$label}" . ($e !== null ? ' [' . $e->getMessage() . ']' : ' (not refused)'));
};
/** A fake CMS of item rows: [Item, ItemCode, Description, Context, Version, ReplacedBy]. */
$alCms = static function (array $rows): FakeCmsReader {
    $row = static fn (?array $r): array => $r === null ? [] : [[
        'Item' => $r[0], 'ItemCode' => $r[1], 'Description' => $r[2], 'Context' => $r[3], 'Version' => $r[4], 'ReplacedBy' => $r[5], 'Unit' => 'lb']];
    $find = static function (int $col, mixed $v) use ($rows): ?array {
        foreach ($rows as $r) {
            if ((string) $r[$col] === (string) $v) {
                return $r;
            }
        }
        return null;
    };
    return (new FakeCmsReader())
        ->when('FROM CMS.dbo.Item i WHERE i.Item = ?', static fn (array $p) => $row($find(0, $p[0])))
        ->when('FROM CMS.dbo.Item i WHERE i.ItemCode = ?', static fn (array $p) => $row($find(1, $p[0])));
};
$alRows = [
    [123124, 'OR1457UV', 'NEW-GEN PANTONE 7579 ORANGE (80# GLOSS)', 'PP', 3, null],
    [122977, 'BK2000', 'DENSE BLACK', 'SUNDRY', 2, null],
    [113832, 'OS-HD-ORANGE', 'OS VINYLCURE ORANGE', 'NAME', 2, 123124],
    [122978, 'CUST-BLACK', 'CUSTOMER BLACK', 'NAME', 1, 123124],
    [101338, '50', '50', 'PROTOTYPE', 1, null],
];
$alNow = new DateTimeImmutable('2026-11-09T11:00:05');

t_section('Alias registry');
t_check(isset(\PII\Cms\Write\OperationRegistry::permissionsFor('items')['write.item.alias']), 'one permission for aliases');
t_same('Write to CMS: add and change aliases of items', \PII\Cms\Write\OperationRegistry::permissionsFor('items')['write.item.alias'], 'its label');

t_section('Alias flow: add an alias');
$fake = new FakeTransport();
$wr   = $alWriter($alCms($alRows), $fake);
$in   = ['item' => ' or1457uv ', 'code' => ' or1457uv-50 ', 'description' => ''];
$p    = $wr->preview('item.alias_create', $in, $alNow);
t_check(str_contains(json_encode($p->plan->summary), 'OR1457UV NEW-GEN PANTONE'), 'the review names the item');
t_check($wr->execute('item.alias_create', $in, $alNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($alNorm(cms_fixture(MR::ALIAS_SAVE, 866295)), $alNorm($fake->sent[0] ?? ''), 'the request matches log 866295 (code upper-cased, description taken from the item)');
$p = $wr->preview('item.alias_create', ['item' => 'CUST-BLACK', 'code' => 'X1', 'description' => ' My  name '], $alNow);
$d = cms_fixture_parse($p->rendered->stages[0]['xml'])['steps'][0]['data'];
t_same(['X1', 'My name', '123124'], [$d['ItemCode'], $d['Description'], $d['ReplacedBy']], 'an alias given as the item resolves to the item behind it');
foreach ([
    'no item'        => [['item' => ''], 'Choose the item'],
    'unknown item'   => [['item' => 'NOPE'], 'was not found'],
    'not stocked'    => [['item' => '50'], 'not a stock item'],
    'no code'        => [['code' => ' '], 'Enter a code'],
    'code in use'    => [['code' => 'bk2000'], 'already used by an item'],
    'alias in use'   => [['code' => 'CUST-BLACK'], 'already used by another alias'],
    'long code'      => [['code' => str_repeat('A', 31)], 'too long'],
    'odd characters' => [['code' => "AB\u{00e9}"], 'plain letters'],
    'long description' => [['description' => str_repeat('x', 257)], 'too long'],
] as $label => [$over, $needle]) {
    $alRefused(static fn () => $wr->preview('item.alias_create', $over + $in, $alNow), $needle, $label);
}
t_check(!$alWriter($alCms($alRows), $fake, [])->allowed('item.alias_create'), 'adding needs the permission');

t_section('Alias flow: change an alias');
$fake = new FakeTransport();
$wr   = $alWriter($alCms($alRows), $fake);
$in   = ['alias' => '113832', 'code' => 'os-hd-orange', 'description' => 'OS VINYLCURE HOME DEPOT ORANGE', 'item' => 'OR1457UV'];
$p    = $wr->preview('item.alias_edit', $in, $alNow);
t_check($wr->execute('item.alias_edit', $in, $alNow, $p->hash)->ok(), 'committed on the fake transport');
t_xml_same($alNorm(cms_fixture(MR::ALIAS_SAVE, 864876)), $alNorm($fake->sent[0] ?? ''), 'only the description differs, so only it is sent: log 864876');
$in = ['alias' => 122978, 'item' => 'BK2000'];
$p  = $wr->preview('item.alias_edit', $in, $alNow);
t_check(str_contains(json_encode($p->plan->summary), 'BK2000 DENSE BLACK (was OR1457UV)'), 'the review shows the old and the new item');
t_same(1, count($p->plan->warnings), 're-pointing warns');
t_check($wr->execute('item.alias_edit', $in, $alNow, $p->hash)->ok(), 'committed');
t_xml_same($alNorm(cms_fixture(MR::ALIAS_SAVE, 859363)), $alNorm($fake->sent[1] ?? ''), 're-pointing matches log 859363');
foreach ([
    'nothing changed'  => [['alias' => 113832, 'code' => 'OS-HD-ORANGE', 'description' => 'OS VINYLCURE ORANGE'], 'Nothing was changed'],
    'unknown alias'    => [['alias' => 5, 'code' => 'X'], 'was not found'],
    'an item'          => [['alias' => 123124, 'code' => 'X'], 'not an alias'],
    'code in use'      => [['alias' => 113832, 'code' => 'CUST-BLACK'], 'already used'],
    'unknown target'   => [['alias' => 113832, 'item' => 'NOPE'], 'was not found'],
    'target not stock' => [['alias' => 113832, 'item' => '50'], 'not a stock item'],
] as $label => [$over, $needle]) {
    $alRefused(static fn () => $wr->preview('item.alias_edit', $over, $alNow), $needle, $label);
}
