<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-superadmin',
    description: 'Creates the initial super admin user if it does not already exist.',
)]
class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Super admin email', 'admin@signal.local')
            ->addArgument('password', InputArgument::OPTIONAL, 'Super admin password', 'admin1234')
            ->addArgument('name', InputArgument::OPTIONAL, 'Super admin name', 'Super Admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');
        $name = (string) $input->getArgument('name');

        if ($this->users->findOneBy(['email' => $email])) {
            $io->info(sprintf('Super admin "%s" already exists. Nothing to do.', $email));

            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->users->save($user);

        $io->success(sprintf('Super admin created: %s / %s', $email, $password));

        return Command::SUCCESS;
    }
}
