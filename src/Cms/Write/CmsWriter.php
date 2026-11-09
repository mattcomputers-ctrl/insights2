<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Core\Database;
use PII\Services\PermissionService;

/**
 * The one door every CMS write goes through.
 *
 *   preview()  allowlist → permission → CMS user → Flow::plan → rendered XML
 *   execute()  the same again with fresh CMS state, refuse if it no longer
 *              matches what was previewed, then run and audit
 *
 * Nothing here knows whether the transport is dry-run, fake or live.
 */
final class CmsWriter
{
    /**
     * @param \Closure(string):bool $can   does the acting user hold this permission?
     * @param null|\Closure(array):void $audit one row per request sent (see WriteAudit)
     * @param null|\Closure():\DateTimeImmutable $clock
     */
    public function __construct(
        private readonly CmsReader $cms,
        private readonly Transport $transport,
        private readonly \Closure $can,
        private readonly string $cmsUser,
        private readonly string $workstation = 'INSIGHTS',
        private readonly bool $cmsPrograms = false,
        private readonly string $appVersion = '2.0.0',
        private readonly ?\Closure $audit = null,
        private readonly ?\Closure $clock = null,
    ) {}

    /** Writer for the logged-in Insights user, wired to settings, permissions and the audit table. */
    public static function forCurrentUser(): self
    {
        $userId = (int) (current_user_id() ?? 0);
        $row    = $userId > 0
            ? Database::getInstance()->fetch('SELECT cms_user FROM users WHERE id = ?', [$userId])
            : null;

        return new self(
            new LiveCmsReader(),
            WriteConfig::transport(),
            static fn (string $perm): bool => $userId > 0 && PermissionService::can($userId, $perm),
            trim((string) ($row['cms_user'] ?? '')),
            WriteConfig::workstation(),
            WriteConfig::cmsPrograms(),
            WriteConfig::appVersion(),
            WriteAudit::sink($userId),
        );
    }

    public function mode(): string
    {
        return $this->transport->mode();
    }

    /** Whether the acting user may run this operation at all (for showing/hiding buttons). */
    public function allowed(string $opKey): bool
    {
        $def = OperationRegistry::get($opKey);
        return $def !== null && ($this->can)($def->permission);
    }

    /** @throws WriteRefused */
    public function preview(string $opKey, array $input, \DateTimeImmutable $now): Preview
    {
        [$def, $plan] = $this->plan($opKey, $input, $now);
        $rendered = (new SequenceRunner($this->transport))->preview($plan->sequence, $this->envelope($now));
        return new Preview($def, $plan, $rendered, Preview::hashOf($rendered), $this->mode());
    }

    /**
     * @param null|string $expectedHash Preview::$hash the user confirmed; null skips the comparison
     * @throws WriteRefused
     * @throws StalePreview
     */
    public function execute(string $opKey, array $input, \DateTimeImmutable $now, ?string $expectedHash = null): RunResult
    {
        $preview = $this->preview($opKey, $input, $now);
        if ($expectedHash !== null && !hash_equals($expectedHash, $preview->hash)) {
            throw new StalePreview($preview);
        }

        $def   = $preview->operation;
        $plan  = $preview->plan;
        $audit = $this->audit;
        $sink  = $audit === null ? null : static function (array $row) use ($audit, $def, $plan): void {
            $audit($row + [
                'operation'  => $def->key,
                'entityType' => $plan->entityType,
                'entityId'   => $plan->entityId,
            ]);
        };

        return (new SequenceRunner($this->transport, $sink, $this->clock))
            ->run($plan->sequence, $this->envelope($now));
    }

    /** @return array{0:OperationDef,1:Plan} */
    private function plan(string $opKey, array $input, \DateTimeImmutable $now): array
    {
        $def = OperationRegistry::get($opKey);
        if ($def === null) {
            throw new WriteRefused("\"{$opKey}\" is not an allowed CMS write operation.");
        }
        if (!($this->can)($def->permission)) {
            throw new WriteRefused("You don't have permission to {$def->label}.");
        }
        if ($this->cmsUser === '') {
            throw new WriteRefused(
                'Your Insights account is not linked to a CMS user yet. '
                . 'An administrator can set it under Admin → Users (CMS user name).'
            );
        }
        /** @var Flow $flow */
        $flow = new ($def->flow)();
        return [$def, $flow->plan($input, new FlowContext($this->cms, $this->cmsUser, $now))];
    }

    private function envelope(\DateTimeImmutable $now): Envelope
    {
        return new Envelope(
            $this->cmsUser,
            $this->workstation,
            $now,
            '4',
            Envelope::APPLICATION,
            $this->appVersion,
            null,
            $this->cmsPrograms,
        );
    }
}
