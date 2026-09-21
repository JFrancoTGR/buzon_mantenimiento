<?php

declare(strict_types=1);

namespace EUTools\Shared\Mail;

use RuntimeException;

final class TemplateRegistry
{
    private string $templatesRoot;

    public function __construct(?string $templatesRoot = null)
    {
        $this->templatesRoot = rtrim(
            $templatesRoot ?? (__DIR__ . '/templates'),
            '/\\'
        );
    }

    /** @return array{subject:string,html:string,text:string} */
    public function render(MailMessage $message): array
    {
        $path = $this->templatesRoot
            . '/' . $message->applicationCode
            . '/' . $message->template
            . '.php';

        if (!is_file($path)) {
            throw new RuntimeException(
                "No existe la plantilla de correo "
                . "{$message->applicationCode}/{$message->template}."
            );
        }

        $renderer = require $path;

        if (!is_callable($renderer)) {
            throw new RuntimeException(
                "La plantilla {$message->applicationCode}/{$message->template} "
                . 'debe devolver un callable.'
            );
        }

        $context = array_merge(
            $message->context,
            ['recipient_name' => $message->recipientName]
        );

        $rendered = $renderer($context);

        if (!is_array($rendered)) {
            throw new RuntimeException(
                'La plantilla de correo devolvió un resultado inválido.'
            );
        }

        $subject = $rendered['subject'] ?? null;
        $html = $rendered['html'] ?? null;
        $text = $rendered['text'] ?? null;

        if (!is_string($subject) || trim($subject) === '') {
            throw new RuntimeException(
                'La plantilla de correo no definió un asunto válido.'
            );
        }

        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException(
                'La plantilla de correo no definió contenido HTML válido.'
            );
        }

        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException(
                'La plantilla de correo no definió contenido de texto válido.'
            );
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }
}