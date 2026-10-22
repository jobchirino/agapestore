<?php

use App\Models\Client;
use App\Services\ClientService;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Componente HIJO aislado: crear y editar cliente.
 *
 * La pantalla de Clientes tiene un buscador con wire:model.live; si este formulario viviera
 * ahí, una petición del buscador podría borrar lo que se está escribiendo. Su estado es
 * independiente y se abre solo por evento, nunca en mount().
 */
new class extends Component {
    public ?int $clientId = null;

    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    #[On('open-client-form')]
    public function openForm(?int $clientId = null): void
    {
        $this->resetErrorBag();
        $this->clientId = $clientId;

        if ($clientId === null) {
            $this->reset(['name', 'phone', 'email', 'address']);
        } else {
            $client = Client::query()->findOrFail($clientId);

            $this->name = $client->name;
            $this->phone = $client->phone ?? '';
            $this->email = $client->email ?? '';
            $this->address = $client->address ?? '';
        }

        Flux::modal('client-form')->show();
    }

    public function save(ClientService $clients): void
    {
        // Los nombres de las propiedades coinciden con las claves del servicio:
        // los errores aparecen solos bajo cada campo.
        $data = [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
        ];

        try {
            if ($this->clientId === null) {
                $clients->createClient($data);
            } else {
                $clients->updateClient(Client::query()->findOrFail($this->clientId), $data);
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        Flux::modal('client-form')->close();
        $this->dispatch('client-saved');

        Flux::toast(variant: 'success', text: $this->clientId === null ? 'Cliente creado.' : 'Cliente actualizado.');
    }
}; ?>

<flux:modal name="client-form" class="w-full max-w-md">
    <form wire:submit="save" class="flex flex-col gap-4">
        <div class="pe-10">
            <flux:heading size="xl" class="font-display">{{ $clientId ? 'Editar cliente' : 'Nuevo cliente' }}</flux:heading>
            <flux:subheading>Solo el nombre es obligatorio.</flux:subheading>
        </div>

        <flux:input wire:model="name" label="Nombre" placeholder="Ej. María Pérez" />
        <flux:input wire:model="phone" label="Teléfono (opcional)" placeholder="04124737116 o +5841247377116" />
        <flux:input wire:model="email" label="Correo (opcional)" type="email" placeholder="cliente@correo.com" />
        <flux:textarea wire:model="address" label="Dirección (opcional)" rows="2" />

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button type="button" variant="ghost">Cancelar</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">{{ $clientId ? 'Guardar cambios' : 'Crear cliente' }}</flux:button>
        </div>
    </form>
</flux:modal>
