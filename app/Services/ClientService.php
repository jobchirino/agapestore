<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Validator;

class ClientService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createClient(array $data): Client
    {
        $validated = Validator::make($data, $this->rules(), $this->messages())->validate();

        return Client::create($validated);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateClient(Client $client, array $data): Client
    {
        $validated = Validator::make($data, $this->rules(sometimes: true), $this->messages())->validate();

        $client->update($validated);

        return $client;
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
            'phone' => [...$prefix, 'nullable', 'string', 'max:30'],
            'address' => [...$prefix, 'nullable', 'string'],
            'email' => [...$prefix, 'nullable', 'email', 'max:255'],
        ];
    }
}
