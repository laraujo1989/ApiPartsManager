<?php

namespace App\Command;

use App\Message\FollowUpReminderHandler;
use App\Message\FollowUpReminderMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:follow-up-reminders', description: 'Envía ahora los recordatorios de PO abiertos (el Scheduler lo hace a diario).')]
class FollowUpRemindersCommand extends Command
{
    public function __construct(private FollowUpReminderHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('%d reminder(s) sent.', ($this->handler)(new FollowUpReminderMessage())));

        return Command::SUCCESS;
    }
}
