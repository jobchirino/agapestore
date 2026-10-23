<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La app se guardó originalmente en UTC. Al pasar a America/Caracas (UTC-4 fijo,
 * sin horario de verano) hay que mover los datos existentes para que las fechas
 * visibles sigan representando el mismo instante local. Es una migración de datos
 * de una sola vez; en una base nueva las tablas están vacías y no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(-4);
    }

    public function down(): void
    {
        $this->shift(4);
    }

    private function shift(int $hours): void
    {
        foreach ($this->columns() as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            $columns = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($columns === []) {
                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns, $hours): void {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    $update = [];

                    foreach ($columns as $column) {
                        $value = $data[$column] ?? null;

                        if ($value === null) {
                            continue;
                        }

                        $update[$column] = CarbonImmutable::parse((string) $value, 'UTC')
                            ->addHours($hours)
                            ->format('Y-m-d H:i:s');
                    }

                    if ($update !== []) {
                        DB::table($table)->where('id', $data['id'])->update($update);
                    }
                }
            });
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function columns(): array
    {
        return [
            'users' => ['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at'],
            'passkeys' => ['last_used_at', 'created_at', 'updated_at'],
            'categories' => ['created_at', 'updated_at'],
            'products' => ['deleted_at', 'created_at', 'updated_at'],
            'clients' => ['created_at', 'updated_at'],
            'sales' => ['date', 'created_at', 'updated_at'],
            'sales_products' => ['created_at', 'updated_at'],
            'sale_payments' => ['date', 'created_at', 'updated_at'],
        ];
    }
};
