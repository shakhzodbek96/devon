<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Console\Command;

/**
 * Daily certificate-expiry sweep (PLAN_PHASE_E.md §4): any ACTIVE
 * certificate whose `valid_to` has passed flips to EXPIRED. Runs unattended
 * (scheduled in routes/console.php), off any HTTP request, so audit entries
 * are attributed to the system actor (admin@devon.uz).
 *
 * The audit action reuses `CERTIFICATE_REVOKED` (context.reason = 'EXPIRED',
 * context.auto = true) rather than inventing a new action string — the
 * frontend's audit vocabulary (src/types/domain.ts `AuditAction`,
 * src/lib/audit-icons.ts, src/i18n/locales/uz.json) only defines
 * CERTIFICATE_UPLOADED/APPROVED/REJECTED/REVOKED; PLAN_PHASE_E.md §4
 * explicitly calls for the `CERTIFICATE_REVOKED`/status-audit shape here.
 *
 * TODO(phase-F): expiry notifications (TZ §6.6's 30/14/7/1-day multi-channel
 * alerts) — notification infra doesn't exist yet; this command only
 * transitions status + audits it.
 */
class ExpireCertificatesCommand extends Command
{
    /** @var string */
    protected $signature = 'certificates:expire';

    /** @var string */
    protected $description = 'Flip ACTIVE certificates past their valid_to date to EXPIRED.';

    public function handle(): int
    {
        $systemActor = User::where('email', 'admin@devon.uz')->first();
        if (! $systemActor) {
            $this->error('System actor (admin@devon.uz) not found — aborting.');

            return self::FAILURE;
        }

        $expired = Certificate::where('status', 'ACTIVE')
            ->where('valid_to', '<', now())
            ->get();

        foreach ($expired as $cert) {
            $cert->status = 'EXPIRED';
            $cert->save();

            AuditLogger::log(
                $systemActor,
                'CERTIFICATE_REVOKED',
                'certificate',
                $cert->uuid,
                "ERI · {$cert->subject_common_name}",
                null,
                ['reason' => 'EXPIRED', 'auto' => true],
            );
        }

        $this->info("Expired {$expired->count()} certificate(s).");

        return self::SUCCESS;
    }
}
