<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Infrastructure\Cli;

use InvalidArgumentException;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAsset;
use RelenzWorks\InfraRegister\Application\Asset\RegisterAssetHandler;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\AssetStoreUnavailable;
use RelenzWorks\InfraRegister\Infrastructure\Persistence\JsonAssetRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

#[AsCommand(name: 'asset:register', description: 'Register an infrastructure asset.')]
final class RegisterAssetCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Human-readable asset name.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Path to the JSON asset store.', 'var/assets.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $store = $input->getOption('store');

        if (!is_string($name) || !is_string($store)) {
            $output->writeln('<error>Invalid command input.</error>');

            return Command::INVALID;
        }

        $basePath = $this->basePath();
        $storePath = $basePath === null ? null : $this->storePath($store, $basePath);

        if ($storePath === null) {
            $output->writeln('<error>Store path must stay inside the current working directory.</error>');

            return Command::FAILURE;
        }

        try {
            $handler = new RegisterAssetHandler(new JsonAssetRepository($storePath, $basePath));
            $asset = $handler(new RegisterAsset($name));
        } catch (InvalidArgumentException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $this->consoleText($exception->getMessage())));

            return Command::INVALID;
        } catch (AssetStoreUnavailable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $this->consoleText($exception->getMessage())));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Registered asset %s (%s).',
            $this->consoleText($asset->name->value),
            $this->consoleText($asset->id->value),
        ));

        return Command::SUCCESS;
    }

    private function consoleText(string $value): string
    {
        return OutputFormatter::escape(str_replace(['<', '>'], ['&lt;', '&gt;'], $value));
    }

    private function basePath(): ?string
    {
        $cwd = getcwd();

        // @codeCoverageIgnoreStart
        if ($cwd === false) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        $basePath = realpath($cwd);

        return $basePath === false ? null : $basePath;
    }

    private function storePath(string $store, string $basePath): ?string
    {
        if ($store === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $store) === 1) {
            return null;
        }

        $path = Path::canonicalize(Path::makeAbsolute($store, $basePath));
        $resolvedAncestor = $this->existingAncestor($path);

        // @codeCoverageIgnoreStart
        if ($resolvedAncestor === null) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        $realAncestor = realpath($resolvedAncestor);

        // @codeCoverageIgnoreStart
        if ($realAncestor === false || !Path::isBasePath($basePath, $realAncestor)) {
            return null;
        }

        if (!Path::isBasePath($basePath, $path)) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        return $path;
    }

    private function existingAncestor(string $path): ?string
    {
        $candidate = $path;

        while (!file_exists($candidate)) {
            $parent = dirname($candidate);

            // @codeCoverageIgnoreStart
            if ($parent === $candidate) {
                return null;
            }
            // @codeCoverageIgnoreEnd

            $candidate = $parent;
        }

        return $candidate;
    }
}
