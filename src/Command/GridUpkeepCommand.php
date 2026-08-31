<?php

namespace ApManBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The slow parts of the client views, moved out of the request path.
 *
 * The neighbour table costs dhcp-lease-list plus two rpc round trips to the
 * firewall, and each reverse lookup costs up to a second. Both used to run
 * inside every grid poll and stall it; here they run on a timer instead, and
 * the pages only read what this leaves behind.
 */
#[AsCommand(name: 'apman:grid-upkeep')]
class GridUpkeepCommand extends Command
{
    public function __construct(
        private readonly \ApManBundle\Service\StatusService $status,
        private readonly \ApManBundle\Service\wrtJsonRpc $rpc,
        $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Rebuild the neighbour table and resolve pending reverse names, off the request path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->status->refreshNeighbors($this->rpc);
        $this->status->resolvePtrs();

        return 0;
    }
}
