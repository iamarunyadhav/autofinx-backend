<?php

namespace App\Domain\AuditResearch;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * No existing version/commit-capture mechanism was found anywhere in this
 * app before this task (checked config/app.php, .env.example, and grepped
 * for APP_VERSION/GIT_COMMIT — none exist). This is a best-effort, never-
 * throwing helper: reads the repo's current commit if `.git/HEAD` is
 * reachable, otherwise falls back to null. Never shells out to `git` itself
 * (no guarantee the binary exists in every deploy environment) — reads the
 * plumbing files directly instead.
 */
final class BuildVersionResolver
{
    public static function resolve(): ?string
    {
        try {
            $gitDir = base_path('.git');
            $headPath = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
            if (! File::exists($headPath)) {
                return null;
            }

            $head = trim(File::get($headPath));
            if (str_starts_with($head, 'ref:')) {
                $ref = trim(substr($head, 4));
                $refPath = $gitDir . DIRECTORY_SEPARATOR . $ref;
                if (File::exists($refPath)) {
                    return substr(trim(File::get($refPath)), 0, 12);
                }

                return null;
            }

            return substr($head, 0, 12);
        } catch (Throwable) {
            return null;
        }
    }
}
