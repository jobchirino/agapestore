<?php

use Flux\Flux;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Gestor de llaves de acceso (passkeys).
 *
 * Se monta una vez en el layout de la aplicación y se abre por el evento `open-passkeys`
 * desde el sidebar o desde la pantalla de Seguridad. Solo consulta la base al abrirse.
 */
new class extends Component {
    /**
     * @var list<array{id:int,name:string,authenticator:?string,created_at:string,last_used_at:?string}>
     */
    public array $passkeys = [];

    public ?int $deletingPasskeyId = null;

    public ?string $deletingPasskeyName = null;

    #[On('open-passkeys')]
    public function open(): void
    {
        $this->loadPasskeys();

        Flux::modal('passkeys')->show();
    }

    /**
     * También la llama el componente de registro tras vincular una llave nueva.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn (Passkey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at' => $passkey->created_at->format('d/m/Y g:i A'),
                'last_used_at' => $passkey->last_used_at?->format('d/m/Y g:i A'),
            ])
            ->all();
    }

    public function askDelete(int $id): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($id);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;

        Flux::modal('delete-passkey')->show();
    }

    public function confirmDelete(DeletePasskey $deletePasskey): void
    {
        if ($this->deletingPasskeyId === null) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = null;
        $this->loadPasskeys();

        Flux::modal('delete-passkey')->close();
        Flux::toast(variant: 'success', text: 'Llave de acceso eliminada.');
    }
}; ?>

<div>
    <flux:modal name="passkeys" class="w-full max-w-lg">
        <div class="flex flex-col gap-5">
            <div class="pe-10">
                <flux:heading size="xl" class="font-display">Llaves de acceso</flux:heading>
                <flux:subheading>
                    Entra con huella, Face ID o PIN desde tus dispositivos, sin escribir la contraseña.
                </flux:subheading>
            </div>

            <div class="flex flex-col divide-y divide-gray-100 rounded-xl border border-gray-200/60 dark:divide-white/10 dark:border-white/10">
                @forelse ($passkeys as $passkey)
                    <div wire:key="passkey-{{ $passkey['id'] }}" class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-agape-soft text-agape dark:bg-agape-dark/15 dark:text-agape-dark">
                                <flux:icon name="finger-print" class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $passkey['name'] }}</p>
                                    @if ($passkey['authenticator'])
                                        <span class="rounded-md bg-zinc-900/5 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                                            {{ $passkey['authenticator'] }}
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Creada {{ $passkey['created_at'] }}
                                    @if ($passkey['last_used_at'])
                                        · Último uso {{ $passkey['last_used_at'] }}
                                    @else
                                        · Sin uso todavía
                                    @endif
                                </p>
                            </div>
                        </div>

                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            wire:click="askDelete({{ $passkey['id'] }})"
                            aria-label="Eliminar llave"
                        />
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Todavía no tienes llaves de acceso vinculadas.
                    </p>
                @endforelse
            </div>

            <x-passkey-registration />

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Cerrar</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Confirmación de borrado (acción destructiva) --}}
    <flux:modal name="delete-passkey" class="w-full max-w-md">
        <form wire:submit="confirmDelete" class="flex flex-col gap-5">
            <div class="pe-10">
                <flux:heading size="xl" class="font-display">Eliminar llave de acceso</flux:heading>
                <flux:subheading>
                    ¿Seguro que quieres eliminar
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $deletingPasskeyName }}</span>?
                    Ese dispositivo ya no podrá entrar con huella.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">Eliminar llave</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
