<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests\Fixtures;

use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/** An SMTP server that greets, takes HELO, then answers each command with the next scripted reply. */
final class ScriptedSmtpStream extends AbstractStream
{
    /** @param  list<string>  $replies */
    public function __construct(private readonly array $replies) {}

    public function initialize(): void
    {
        $this->in = fopen('php://memory', 'w');
        $this->out = fopen('php://memory', 'r+');
        fwrite($this->out, implode("\r\n", ['220 ready', '250 hello', ...$this->replies, '221 bye']) . "\r\n");
        rewind($this->out);
    }

    protected function getReadConnectionDescription(): string
    {
        return 'scripted';
    }
}
