<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El respaldo de la tasa BCV vive en el disco local; los tests no deben leer el real.
        Storage::fake('local');

        // Nunca enviar mensajes reales de Telegram durante los tests. Los tests de
        // notificaciones habilitan el servicio explícitamente y usan Http::fake().
        config([
            'services.telegram.enabled' => false,
            'services.telegram.bot_token' => null,
            'services.telegram.chat_id' => null,
        ]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
