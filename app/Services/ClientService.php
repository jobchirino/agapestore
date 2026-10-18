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
        $validated = Validator::make($data, $this->rules())->validate();

        return Client::create($validated);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateClient(Client $client, array $data): Client
    {
        $validated = Validator::make($data, $this->rules(sometimes: true))->validate();

        $client->update($validated);

        return $client;
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
