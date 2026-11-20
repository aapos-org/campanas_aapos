<?php

namespace App\Mail\Transport;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the MIME message used by the SMTP and Gmail API drivers.
 */
class MimeMessageBuilder
{
    public function build(OutgoingEmail $email): Email
    {
        $message = (new Email)
            ->from(new Address($email->fromEmail, $email->fromName ?? ''))
            ->to(new Address($email->to, $email->toName ?? ''))
            ->subject($email->subject)
            ->html($email->html)
            ->text($email->text ?? $this->htmlToText($email->html));

        if ($email->replyTo) {
            $message->replyTo($email->replyTo);
        }

        foreach ($email->headers as $name => $value) {
            $message->getHeaders()->addTextHeader($name, $value);
        }

        return $message;
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace(['#<(br|/p|/div|/h[1-6]|/li|/tr)[^>]*>#i', '#<li[^>]*>#i'], ["\n", '- '], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
