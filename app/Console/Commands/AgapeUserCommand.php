<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class AgapeUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agape:user {email} {password} {--name=Ágape Admin}';

    /**
     * @var string
     */
    protected $description = 'Crea o actualiza el usuario administrador de la tienda con la contraseña hasheada';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $name = $this->option('name');

        if (! is_string($email) || ! is_string($password) || ! is_string($name)) {
            $this->error('Parámetros inválidos.');

            return self::FAILURE;
        }

        if ($name === '') {
            $name = 'Ágape Admin';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = Hash::make($password);
        // El login exige correo verificado: el usuario gestionado por consola queda listo.
        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Usuario creado: ' : 'Usuario actualizado: ').$email);

        return self::SUCCESS;
    }
}
