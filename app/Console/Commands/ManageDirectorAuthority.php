<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ApprovalAuthorityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('app:director-authority {action : grant or revoke} {--email=} {--reason=}')]
#[Description('Trusted provisioning: appoint or remove a Director from the server console')]
class ManageDirectorAuthority extends Command
{
    public function handle(ApprovalAuthorityService $authority): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['grant', 'revoke'], true)) {
            $this->error('Action must be "grant" or "revoke".');

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: $this->ask('Email of the user'));
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->error('No user exists with that email.');

            return self::FAILURE;
        }
        $reason = (string) ($this->option('reason') ?: $this->ask('Reason (owner approval reference)'));

        // The confirmation defaults to "no", so an unattended run changes nothing.
        if (! $this->confirm(ucfirst($action)." Director approval authority for {$user->name} <{$user->email}>?", false)) {
            $this->info('No change was made.');

            return self::FAILURE;
        }

        try {
            $authority->provision($user, $action === 'grant', $reason, 'console:'.get_current_user());
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info($action === 'grant' ? 'Director authority granted and audited.' : 'Director authority removed and audited.');

        return self::SUCCESS;
    }
}
