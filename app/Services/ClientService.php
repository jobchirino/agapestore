<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClientService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createClient(array $data): Client
    {
        $data = $this->normalizePhone($data);

        $validated = Validator::make($data, $this->rules(), $this->messages())->validate();

        return Client::create($validated);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateClient(Client $client, array $data): Client
    {
        $data = $this->normalizePhone($data);

        $validated = Validator::make($data, $this->rules(sometimes: true), $this->messages())->validate();

        $client->update($validated);

        return $client;
    }

    /**
     * No se elimina un cliente con compras: se perdería el rastro contable (y su posible deuda).
     */
    public function deleteClient(Client $client): void
    {
        if ($client->sales()->exists()) {
            throw ValidationException::withMessages([
                'client' => "No se puede eliminar a {$client->name}: tiene compras registradas.",
            ]);
        }

        $client->delete();
    }

    /**
     * Deja solo dígitos y el "+" inicial: "0412-473 71 16" y "+58 412 473 7116"
     * se guardan como 04124737116 y +5841247377116.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePhone(array $data): array
    {
        if (! array_key_exists('phone', $data) || $data['phone'] === null) {
            return $data;
        }

        $phone = preg_replace('/[\s\-().]/', '', (string) $data['phone']);
        $data['phone'] = $phone === '' ? null : $phone;

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'El nombre del cliente es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',
            'phone.max' => 'El teléfono no puede tener más de 30 caracteres.',
            'phone.regex' => 'El teléfono debe tener el formato 04124737116 o +5841247377116.',
            'email.email' => 'El correo electrónico no es válido.',
            'email.max' => 'El correo no puede tener más de 255 caracteres.',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        return [
            'name' => [...$prefix, 'required', 'string', 'max:255'],
            // Nacional: 0 + 10 dígitos (04124737116). Internacional: + y 8 a 15 dígitos (+5841247377116).
            'phone' => [...$prefix, 'nullable', 'string', 'max:30', 'regex:/^(0\d{10}|\+\d{8,15})$/'],
            'address' => [...$prefix, 'nullable', 'string'],
            'email' => [...$prefix, 'nullable', 'email', 'max:255'],
        ];
    }
}
