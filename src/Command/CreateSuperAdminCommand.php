<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-superadmin',
    description: 'Creates the initial platform admin (admin_user table) if none exists.',
)]
class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $admins,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Admin email (default: admin@signal.local)')
            ->addArgument('password', InputArgument::OPTIONAL, 'Admin password (default: admin1234)')
            ->addArgument('name', InputArgument::OPTIONAL, 'Admin name (default: Super Admin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');

        // The container entrypoint calls this on every boot to bootstrap a fresh
        // install. Once the platform has an admin of its own, stop seeding —
        // otherwise a restart resurrects the well-known default account.
        if (null === $email && $this->admins->hasAny()) {
            $io->info('An admin already exists. Nothing to do.');

            return Command::SUCCESS;
        }

        $email = (string) ($email ?? 'admin@signal.local');
        $password = (string) ($input->getArgument('password') ?? 'admin1234');
        $name = (string) ($input->getArgument('name') ?? 'Super Admin');

        if ($this->admins->findOneBy(['email' => $email])) {
            $io->info(sprintf('Admin "%s" already exists. Nothing to do.', $email));

            return Command::SUCCESS;
        }

        $admin = new AdminUser();
        $admin->setEmail($email);
        $admin->setName($name);
        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $this->admins->save($admin);

        $io->success(sprintf('Admin created: %s / %s', $email, $password));

        return Command::SUCCESS;
    }
}
