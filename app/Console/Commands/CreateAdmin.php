<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'queryproxy:create-admin
        {--name= : Display name (asked interactively when omitted)}
        {--email= : Login e-mail}
        {--password= : Password, at least 8 characters}
        {--if-none : Do nothing when the instance already has users}';

    protected $description = 'Create the first administrator account';

    public function handle(): int
    {
        if ($this->option('if-none') && User::query()->exists()) {
            $this->line('Users already exist; skipping admin creation.');

            return self::SUCCESS;
        }

        $attributes = [
            'name' => $this->option('name') ?: ($this->input->isInteractive() ? $this->ask('Name', 'Admin') : 'Admin'),
            'email' => $this->option('email') ?: ($this->input->isInteractive() ? $this->ask('E-mail') : null),
            'password' => $this->option('password') ?: ($this->input->isInteractive() ? $this->secret('Password') : null),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        User::create($validator->validated() + ['is_admin' => true]);

        $this->components->info("Administrator {$attributes['email']} created.");

        return self::SUCCESS;
    }
}
