<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\PendingWrites;
use PII\Cms\Write\StalePreview;
use PII\Cms\Write\WriteRefused;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__) . '/Helpers/layout.php';

/**
 * The shared "check it, then confirm" step for every CMS write.
 *
 * A module validates a form and calls CmsWriteController::propose(); the
 * user lands on /cms-write/{token}, sees in plain words what will change
 * plus the exact request(s), and confirms. Confirm re-reads CMS, refuses
 * if anything moved since the preview, then runs the sequence.
 *
 * The page itself needs no permission of its own: CmsWriter checks the
 * operation's permission on every preview and every execute.
 */
class CmsWriteController
{
    /**
     * Validate a proposed write and send the user to its confirmation page.
     * On refusal, flashes the reason and returns to $returnUrl.
     */
    public static function propose(string $opKey, array $input, string $returnUrl): never
    {
        $now = new \DateTimeImmutable(date('Y-m-d\TH:i:s'));
        try {
            $preview = CmsWriter::forCurrentUser()->preview($opKey, $input, $now);
        } catch (WriteRefused $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect($returnUrl);
        } catch (CMSUnavailableException $e) {
            $_SESSION['_flash']['error'] = $e->friendlyMessage();
            redirect($returnUrl);
        }
        $token = PendingWrites::put($opKey, $input, $now, $preview->hash, $returnUrl);
        redirect('/cms-write/' . $token);
    }

    /** GET /cms-write/{token} */
    public function show(string $token): void
    {
        $pending = PendingWrites::get($token);
        if ($pending === null) {
            $_SESSION['_flash']['warning'] = 'That confirmation page has expired. Start the change again.';
            redirect(landing_path());
        }
        try {
            $preview = CmsWriter::forCurrentUser()->preview($pending['op'], $pending['input'], $pending['now']);
        } catch (WriteRefused $e) {
            PendingWrites::forget($token);
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect($pending['return']);
        } catch (CMSUnavailableException $e) {
            $_SESSION['_flash']['error'] = $e->friendlyMessage();
            redirect($pending['return']);
        }
        // What is on screen is what confirm will compare against.
        PendingWrites::setHash($token, $preview->hash);

        layout('cms_write/confirm', [
            'pageTitle' => 'Confirm: ' . $preview->plan->sequence->title,
            'token'     => $token,
            'preview'   => $preview,
            'returnUrl' => $pending['return'],
        ], $preview->operation->module);
    }

    /** POST /cms-write/{token}/confirm */
    public function confirm(string $token): void
    {
        CSRF::validateRequest();
        // The token is retired here, before anything can be sent, and the
        // session is saved. If this request dies part way through a run
        // (time limit, memory, a killed worker) the same confirmation
        // cannot be pressed again and send the first steps twice.
        $pending = PendingWrites::take($token);
        if ($pending === null) {
            $_SESSION['_flash']['warning'] = 'That confirmation page has expired or was already used. '
                . 'Check the record before starting the change again.';
            redirect(landing_path());
        }
        // A run of several requests must not be cut off by the page time
        // limit or by the user closing the tab.
        ignore_user_abort(true);
        set_time_limit(600);

        // execute() throws only before anything is sent (refusal, stale
        // preview, CMS not readable). Once the run starts it always
        // answers with a RunResult that says what was sent.
        try {
            $result = CmsWriter::forCurrentUser()->execute(
                $pending['op'], $pending['input'], $pending['now'], $pending['hash']
            );
        } catch (StalePreview $e) {
            // Nothing was sent: the same page may be confirmed again.
            PendingWrites::putBack($token, $pending, $e->fresh->hash);
            $_SESSION['_flash']['warning'] = $e->getMessage();
            redirect('/cms-write/' . $token);
        } catch (WriteRefused $e) {
            $_SESSION['_flash']['error'] = $e->getMessage() . ' Nothing was sent.';
            redirect($pending['return']);
        } catch (CMSUnavailableException $e) {
            // Nothing was sent: the same page may be confirmed again.
            PendingWrites::putBack($token, $pending);
            $_SESSION['_flash']['error'] = $e->friendlyMessage() . ' Nothing was sent.';
            redirect('/cms-write/' . $token);
        } catch (\Throwable $e) {
            // Still before the first send. The token stays retired.
            error_log('CMS write could not be started: ' . $e::class . ': ' . $e->getMessage());
            $_SESSION['_flash']['error'] = 'The change could not be prepared (' . self::brief($e) . '). Nothing was sent. Start the change again.';
            redirect($pending['return']);
        }

        if ($result->dryRun()) {
            $type = $result->failure() === null && $result->auditGap() === null ? 'info' : 'error';
        } elseif ($result->ok()) {
            $type = 'success';
        } elseif ($result->failure() === null && $result->auditGap() !== null) {
            $type = 'warning';   // saved in CMS, but the write log is incomplete
        } else {
            $type = 'error';
        }
        // describe() names the saved requests and the step that stopped the
        // run. It only says "nothing was saved" when that is known.
        $_SESSION['_flash'][$type] = $result->describe();
        redirect($pending['return']);
    }

    /** One line of an exception's message, for a flash. */
    private static function brief(\Throwable $e): string
    {
        $msg = rtrim(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), '.');
        return $msg !== '' ? mb_substr($msg, 0, 200) : 'unexpected error';
    }

    /** POST /cms-write/{token}/cancel */
    public function cancel(string $token): void
    {
        CSRF::validateRequest();
        $pending = PendingWrites::get($token);
        PendingWrites::forget($token);
        redirect($pending['return'] ?? landing_path());
    }
}
