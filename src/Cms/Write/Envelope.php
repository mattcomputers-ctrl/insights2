<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Who is making a request, from where and when — the <request> attributes
 * that are not specific to one operation.
 *
 * Insights identifies itself honestly: Application is "Precision Ink
 * Insights" and Program names the Insights module. $cmsPrograms switches
 * Program to the name the CMS client uses for the same action: the golden
 * tests need it (they replay real client requests) and it backs the
 * compatibility setting cms_write.program_mode = "cms".
 */
final class Envelope
{
    public const APPLICATION = 'Precision Ink Insights';

    public function __construct(
        public readonly string $user,
        public readonly string $workstation,
        public readonly \DateTimeImmutable $transDate,
        public readonly string $owner = '4',
        public readonly string $application = self::APPLICATION,
        public readonly string $appVersion = '2.0.0',
        public readonly ?string $programOverride = null,
        public readonly bool $cmsPrograms = false,
    ) {
        if ($user === '') {
            throw new \InvalidArgumentException('A CMS user is required for a write request.');
        }
    }

    public function withProgram(?string $program): self
    {
        return new self($this->user, $this->workstation, $this->transDate, $this->owner,
            $this->application, $this->appVersion, $program, $this->cmsPrograms);
    }

    public function withTransDate(\DateTimeImmutable $at): self
    {
        return new self($this->user, $this->workstation, $at, $this->owner,
            $this->application, $this->appVersion, $this->programOverride, $this->cmsPrograms);
    }
}
