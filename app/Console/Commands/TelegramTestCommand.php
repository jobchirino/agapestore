<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramTestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agape:telegram-test';

    /**
     * @var string
     */
    protected $description = 'Prueba el bot de Telegram y ayuda a obtener el chat_id';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->hasToken()) {
            $this->error('Falta TELEGRAM_BOT_TOKEN en el .env. Créalo con @BotFather y vuelve a intentar.');

            return self::FAILURE;
        }

        if (! $telegram->hasChatId()) {
            $updates = $telegram->getUpdates();
            $rows = [];

            foreach ($updates as $update) {
                $chat = $this->extractChat($update);

                if ($chat === null) {
                    continue;
                }

                $id = (string) $chat['id'];
                $type = is_string($chat['type'] ?? null) ? $chat['type'] : '—';
                $rows[$id] = [$id, $type, $this->chatName($chat)];
            }

            if ($rows === []) {
                $this->warn('No hay mensajes recientes. Abre Telegram, busca tu bot y envíale /start. Luego repite el comando.');

                return self::FAILURE;
            }

            $this->table(['Chat ID', 'Tipo', 'Nombre'], array_values($rows));
            $this->line('Copia el Chat ID en TELEGRAM_CHAT_ID del .env y vuelve a ejecutar el comando.');

            return self::SUCCESS;
        }

        $sent = $telegram->sendMessage(
            "✅ <b>Ágape Store</b>\nLas notificaciones de Telegram están activas.",
            force: true,
        );

        if (! $sent) {
            $this->error('No se pudo enviar el mensaje. Revisa el token/chat y storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->info('Mensaje de prueba enviado. Revisa tu Telegram.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private function extractChat(array $update): ?array
    {
        foreach (['message', 'edited_message', 'channel_post'] as $key) {
            $message = $update[$key] ?? null;

            if (! is_array($message)) {
                continue;
            }

            $chat = $message['chat'] ?? null;

            if (is_array($chat) && isset($chat['id'])) {
                return $chat;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function chatName(array $chat): string
    {
        $title = $chat['title'] ?? $chat['username'] ?? null;

        if (is_string($title) && $title !== '') {
            return $title;
        }

        $first = is_string($chat['first_name'] ?? null) ? $chat['first_name'] : '';
        $last = is_string($chat['last_name'] ?? null) ? $chat['last_name'] : '';

        return trim($first.' '.$last) ?: '—';
    }
}
