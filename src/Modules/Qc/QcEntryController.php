<?php

declare(strict_types=1);

namespace PII\Modules\Qc;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Qc\QcState;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * QC entry: the queue of sample sets waiting on results or a decision,
 * and the form to enter results and approve/reject the sublot.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact request
 * and only sends it after the user confirms.
 */
class QcEntryController
{
    private const OPS = ['qc.save_results', 'qc.approve_sublot', 'qc.reject_sublot'];

    public function queue(): void
    {
        $can = $this->requireAny();
        $rows = [];
        $error = null;
        try {
            $rows = QcState::queue(new LiveCmsReader());
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        layout('qc::queue', [
            'pageTitle' => 'QC queue',
            'rows'      => $rows,
            'error'     => $error,
            'can'       => $can,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'qc');
    }

    public function sample(string $id): void
    {
        $can = $this->requireAny();
        $state = null;
        $error = null;
        try {
            $state = QcState::load(new LiveCmsReader(), (int) $id);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        if ($state === null && $error === null) {
            $error = "Sample set {$id} was not found in CMS.";
        }
        layout('qc::sample', [
            'pageTitle' => $state !== null ? 'QC: lot ' . $state['Lot'] : 'QC sample',
            'state'     => $state,
            'error'     => $error,
            'can'       => $can,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'qc');
    }

    public function saveResults(string $id): void
    {
        CSRF::validateRequest();
        $results = [];
        foreach ((array) ($_POST['results'] ?? []) as $lst => $row) {
            if (!is_array($row) || !ctype_digit((string) $lst)) {
                continue;
            }
            $results[(int) $lst] = [
                'result' => trim((string) ($row['result'] ?? '')),
                'passed' => (string) ($row['passed'] ?? ''),
            ];
        }
        CmsWriteController::propose('qc.save_results', ['sample_set' => (int) $id, 'results' => $results], "/qc/sample/" . (int) $id);
    }

    public function approve(string $id): void
    {
        CSRF::validateRequest();
        CmsWriteController::propose('qc.approve_sublot', ['sample_set' => (int) $id], "/qc/sample/" . (int) $id);
    }

    public function reject(string $id): void
    {
        CSRF::validateRequest();
        CmsWriteController::propose('qc.reject_sublot', ['sample_set' => (int) $id], "/qc/sample/" . (int) $id);
    }

    /**
     * The entry screens are for anyone holding at least one QC write
     * permission; everyone else gets the usual hidden-page response.
     *
     * @return array<string,bool> operation key => allowed
     */
    private function requireAny(): array
    {
        $writer = CmsWriter::forCurrentUser();
        $can = [];
        foreach (self::OPS as $op) {
            $can[$op] = $writer->allowed($op);
        }
        if (!in_array(true, $can, true)) {
            render_not_found();
        }
        return $can;
    }
}
