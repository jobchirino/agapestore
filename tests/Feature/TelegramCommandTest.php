<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramCommandTest extends TestCase
{
    public function test_it_lists_chat_ids_when_the_chat_id_is_missing(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => null,
            'services.telegram.enabled' => false,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    ['message' => ['chat' => ['id' => 123456789, 'type' => 'private', 'first_name' => 'Ana']]],
                    ['message' => ['chat' => ['id' => -987654321, 'type' => 'group', 'title' => 'Ágape']]],
                ],
            ]),
        ]);

        $this->artisan('agape:telegram-test')
            ->expectsOutputToContain('123456789')
            ->expectsOutputToContain('-987654321')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'getUpdates'));
    }

    public function test_it_sends_a_test_message_even_when_notifications_are_disabled(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '123456',
            'services.telegram.enabled' => false,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('agape:telegram-test')
            ->expectsOutputToContain('Mensaje de prueba enviado')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains((string) ($request->data()['text'] ?? ''), 'notificaciones de Telegram'));
    }

    public function test_it_fails_without_a_bot_token(): void
    {
        config(['services.telegram.bot_token' => null]);

        $this->artisan('agape:telegram-test')
            ->expectsOutputToContain('Falta TELEGRAM_BOT_TOKEN')
            ->assertExitCode(1);
    }
}
