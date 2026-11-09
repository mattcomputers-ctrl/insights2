<?php

declare(strict_types=1);

namespace PII\Modules\InventoryBrowser;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Inventory\InventoryState;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Inventory counts (new, change, post, delete), containers waiting for a
 * label, and the planned-date recalculation.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact
 * requests and only sends them after the user confirms.
 */
class InventoryCountController
{
    private const COUNT_OPS = ['inv.count_create', 'inv.count_edit', 'inv.count_delete', 'inv.count_post', 'inv.count_create_post'];
    private const NEW_ROWS  = 12;

    /** The newest counts: open ones first. */
    public function index(): void
    {
        $w = $this->need(self::COUNT_OPS);
        [$counts, $error] = $this->read(static fn (LiveCmsReader $cms): array => InventoryState::counts($cms, 100), []);
        layout('inventory::counts', [
            'pageTitle' => 'Inventory counts',
            'counts'    => $counts,
            'error'     => $error,
            'canSave'   => $w->allowed('inv.count_create'),
            'canPlan'   => $w->allowed('inv.recalc_plan'),
            'canLabel'  => $w->allowed('inv.reset_label'),
            'mode'      => $w->mode(),
        ], 'inventory');
    }

    public function newCount(): void
    {
        $w = $this->need(['inv.count_create', 'inv.count_create_post']);
        // ?item=A,B&qty=1,2 fills the first rows (a link from elsewhere, or coming back after a refusal).
        $codes = [];
        $qtys  = explode(',', is_string($_GET['qty'] ?? '') ? (string) ($_GET['qty'] ?? '') : '');
        foreach (explode(',', is_string($_GET['item'] ?? '') ? (string) ($_GET['item'] ?? '') : '') as $i => $code) {
            $code = trim($code);
            if ($code !== '' && strlen($code) <= 40 && count($codes) < 100) {
                $codes[] = [$code, substr(trim($qtys[$i] ?? ''), 0, 20)];
            }
        }
        layout('inventory::count_new', [
            'pageTitle' => 'New inventory count',
            'codes'     => $codes,
            'date'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d'),
            'about'     => substr(is_string($_GET['description'] ?? '') ? (string) ($_GET['description'] ?? '') : '', 0, 50),
            'rows'      => max(self::NEW_ROWS, count($codes) + 4),
            'canSave'   => $w->allowed('inv.count_create'),
            'canPost'   => $w->allowed('inv.count_create_post'),
            'mode'      => $w->mode(),
        ], 'inventory');
    }

    public function create(): void
    {
        CSRF::validateRequest();
        $op = ($_POST['then'] ?? '') === 'post' ? 'inv.count_create_post' : 'inv.count_create';
        $this->need([$op]);
        $items = (array) ($_POST['item'] ?? []);
        $qtys  = (array) ($_POST['qty'] ?? []);
        $lines = [];
        $codes = [];
        foreach ($items as $i => $code) {
            $code = is_array($code) ? '' : trim((string) $code);
            $qty  = is_array($qtys[$i] ?? '') ? '' : trim((string) ($qtys[$i] ?? ''));
            if ($code !== '' || $qty !== '') {
                $lines[] = ['item' => $code, 'qty' => $qty];
            }
            if ($code !== '' && !str_contains($code . $qty, ',') && count($codes) < 100) {
                $codes[] = $code;
                $back[]  = $qty;
            }
        }
        $date  = trim((string) ($_POST['date'] ?? ''));
        $about = trim((string) ($_POST['description'] ?? ''));
        $query = array_filter(['item' => implode(',', $codes), 'qty' => implode(',', $back ?? []),
                               'date' => $date, 'description' => $about], static fn (string $v): bool => trim($v, ',') !== '');
        CmsWriteController::propose($op, ['date' => $date, 'description' => $about, 'lines' => $lines],
            '/inventory/counts/new' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }

    public function show(string $id): void
    {
        $w  = $this->need(self::COUNT_OPS);
        $id = (int) $id;
        [$count, $error] = $this->read(static fn (LiveCmsReader $cms): ?array => InventoryState::count($cms, $id), null);
        if ($count === null && $error === null) {
            render_not_found();
        }
        layout('inventory::count', [
            'pageTitle' => 'Inventory count ' . $id,
            'count'     => $count,
            'error'     => $error,
            'canSave'   => $w->allowed('inv.count_edit'),
            'canPost'   => $w->allowed('inv.count_post'),
            'canDelete' => $w->allowed('inv.count_delete'),
            'mode'      => $w->mode(),
        ], 'inventory');
    }

    public function edit(string $id): void
    {
        CSRF::validateRequest();
        $this->need(['inv.count_edit']);
        $qty = [];
        foreach ((array) ($_POST['qty'] ?? []) as $detail => $text) {
            if (ctype_digit((string) $detail) && !is_array($text) && trim((string) $text) !== '') {
                $qty[(int) $detail] = trim((string) $text);
            }
        }
        CmsWriteController::propose('inv.count_edit', [
            'count'       => (int) $id,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'date'        => trim((string) ($_POST['date'] ?? '')),
            'qty'         => $qty,
        ], '/inventory/counts/' . (int) $id);
    }

    public function post(string $id): void
    {
        CSRF::validateRequest();
        $this->need(['inv.count_post']);
        CmsWriteController::propose('inv.count_post', ['count' => (int) $id], '/inventory/counts/' . (int) $id);
    }

    public function delete(string $id): void
    {
        CSRF::validateRequest();
        $this->need(['inv.count_delete']);
        CmsWriteController::propose('inv.count_delete', ['count' => (int) $id], '/inventory/counts');
    }

    /** Containers that hold stock and still ask for a label. */
    public function labels(): void
    {
        $w = $this->need(['inv.reset_label']);
        [$rows, $error] = $this->read(static fn (LiveCmsReader $cms): array => InventoryState::labelsWaiting($cms, 200), []);
        layout('inventory::labels', [
            'pageTitle' => 'Containers waiting for a label',
            'rows'      => $rows,
            'error'     => $error,
            'mode'      => $w->mode(),
        ], 'inventory');
    }

    public function resetLabel(string $location): void
    {
        CSRF::validateRequest();
        $this->need(['inv.reset_label']);
        CmsWriteController::propose('inv.reset_label', ['location' => (int) $location], '/inventory/labels');
    }

    public function recalculate(): void
    {
        CSRF::validateRequest();
        $this->need(['inv.recalc_plan']);
        CmsWriteController::propose('inv.recalc_plan', [], '/inventory/counts');
    }

    /** The writer, when the user may do at least one of these; else not found. */
    private function need(array $ops): CmsWriter
    {
        $w = CmsWriter::forCurrentUser();
        foreach ($ops as $op) {
            if ($w->allowed($op)) {
                return $w;
            }
        }
        render_not_found();
    }

    /**
     * @template T
     * @param callable(LiveCmsReader): T $fn
     * @param T $empty
     * @return array{0:T,1:?string}
     */
    private function read(callable $fn, mixed $empty): array
    {
        try {
            return [$fn(new LiveCmsReader()), null];
        } catch (CMSUnavailableException $e) {
            return [$empty, $e->friendlyMessage()];
        } catch (\Throwable $e) {
            return [$empty, 'CMS query failed: ' . $e->getMessage()];
        }
    }
}
