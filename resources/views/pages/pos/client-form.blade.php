<?php

use App\Services\ClientService;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Componente HIJO aislado.
 *
 * ¿Por qué separado y no un formulario dentro del POS? Porque el POS tiene buscadores con
 * `wire:model.live`: si este formulario viviera ahí, cada petición del buscador re-renderizaría
 * el POS y podría borrar lo que el usuario está escribiendo aquí (el mismo bug que ya
 * arreglamos en Inventario). Al ser un componente aparte, su estado es independiente y el
 * carrito del POS nunca se toca.
 *
 * Analogía con React: es como extraer un componente hijo para que su estado no provoque
 * re-renderizados del padre.
 */
new class extends Component {
    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $email = '';

    public function save(ClientService $clients): void
    {
        try {
            // Las claves del servicio (name, phone, address, email) coinciden con las propiedades
            // de este componente, así que los errores se muestran solos bajo cada campo.
            $client = $clients->createClient([
                'name' => $this->name,
                'phone' => $this->phone,
                'address' => $this->address,
                'email' => $this->email,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        Flux::modal('client-form')->close();

        // En Livewire 4 un hijo comunica al padre emitiendo un evento; el padre lo escucha con
        // #[On('client-created')]. El evento viaja por el navegador y Livewire lo intercepta.
        $this->dispatch('client-created', clientId: $client->id);

        $this->reset('name', 'phone', 'address', 'email');
    }
}; ?>

<div class="flex flex-col gap-5">
    <div>
        <flux:heading size="xl" class="font-display">Nuevo cliente</flux:heading>
        <flux:subheading>Solo el nombre es obligatorio. El carrito no se pierde.</flux:subheading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-4">
        <flux:input wire:model="name" label="Nombre" placeholder="Ej. María Pérez" />
        <flux:input wire:model="phone" label="Teléfono (opcional)" placeholder="0414-0000000" />
        <flux:input wire:model="email" label="Correo (opcional)" type="email" placeholder="cliente@correo.com" />
        <flux:textarea wire:model="address" label="Dirección (opcional)" rows="2" />

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button type="button" variant="ghost">Cerrar</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">Guardar cliente</flux:button>
        </div>
    </form>
</div>
