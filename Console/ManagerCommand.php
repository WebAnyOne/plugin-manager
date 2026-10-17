<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Console;
use MauticPlugin\WebAnyOnePluginManagerBundle\Service;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
final class ManagerCommand extends Command
{
    private $manager;private $operation;
    public function __construct(Service\Manager $manager,string $operation) { $this->manager=$manager;$this->operation=$operation;parent::__construct(); }
    protected function configure(): void
    {
        $name=['queue'=>'process-queue','verify'=>'verify','sync'=>'catalogue:sync','recover'=>'recover'][$this->operation];
        $this->setName('wao:plugins:'.$name)->setDescription('WebAnyOne Plugin Manager: '.$name);
        if ($this->operation==='verify') { $this->addArgument('plugin',InputArgument::REQUIRED)->addArgument('version',InputArgument::REQUIRED); }
        if ($this->operation==='recover') { $this->addArgument('job',InputArgument::REQUIRED); }
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            if ($this->operation==='queue') { $output->writeln(json_encode($this->manager->worker(),JSON_THROW_ON_ERROR)); }
            if ($this->operation==='sync') { $this->manager->catalogue->refresh();$output->writeln('catalogue_validated'); }
            if ($this->operation==='verify') { $this->manager->verify($input->getArgument('plugin'),$input->getArgument('version'));$output->writeln('healthy'); }
            if ($this->operation==='recover') { $this->manager->recover($input->getArgument('job'));$output->writeln('restored'); }
            return 0;
        } catch (\Throwable $e) { $code=preg_match('/^[a-z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'operation_failed';$output->writeln('<error>'.$code.'</error>');return 1; }
    }
}
