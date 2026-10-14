<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Infrastructure\Async\RedisStreamEventDispatcher;
use Closure;
use Override;
use Redis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'worker:run', description: 'Runs background daemon processing Redis Stream domain events and sending heartbeats.')]
final class WorkerRunCommand extends Command
{
    /**
     * @param Closure(): ?Redis $redisFactory Resolved lazily in execute(),
     *   not the constructor: bin/console constructs every command up front
     *   so Symfony can list/route them, and an eager Redis connection there
     *   would make every single invocation -- including ones that have
     *   nothing to do with the worker -- pay a real network round trip.
     */
    public function __construct(
        private readonly Closure $redisFactory,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'iterations',
            'i',
            InputOption::VALUE_OPTIONAL,
            'Maximum number of loop iterations before stopping (0 for indefinite)',
            0
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sovereign Engine — Background Worker Daemon');

        $redis = ($this->redisFactory)();

        /** @var int|string $iterationsOpt */
        $iterationsOpt = $input->getOption('iterations');
        $maxIterations = match (true) {
            is_int($iterationsOpt) => $iterationsOpt,
            is_numeric($iterationsOpt) => (int) $iterationsOpt,
            default => 0,
        };
        $iteration = 0;

        $io->note('Listening for stream:domain_events and updating worker:heartbeat...');

        while (true) {
            $iteration++;

            // 1. Maintain worker heartbeat
            if ($redis !== null) {
                try {
                    $redis->setex(
                        RedisStreamEventDispatcher::HEARTBEAT_KEY,
                        RedisStreamEventDispatcher::HEARTBEAT_THRESHOLD_SECONDS + 30,
                        (string) time()
                    );
                } catch (Throwable $e) {
                    $io->warning('Failed to update heartbeat: ' . $e->getMessage());
                }
            }

            // 2. Poll stream
            if ($redis !== null) {
                try {
                    /** @var array<string, array<string, array<string, string>>>|false $messages */
                    $messages = $redis->xRead([RedisStreamEventDispatcher::STREAM_NAME => '0'], 10, 500);
                    if (is_array($messages) && isset($messages[RedisStreamEventDispatcher::STREAM_NAME])) {
                        foreach ($messages[RedisStreamEventDispatcher::STREAM_NAME] as $messageId => $fields) {
                            $io->writeln(sprintf(
                                '<info>[%s]</info> Processed event %s (ID: %s)',
                                date('Y-m-d H:i:s'),
                                $fields['event_class'] ?? 'unknown',
                                $messageId
                            ));
                            $redis->xDel(RedisStreamEventDispatcher::STREAM_NAME, [$messageId]);
                        }
                    }
                } catch (Throwable $e) {
                    $io->warning('Stream read error: ' . $e->getMessage());
                }
            }

            if ($maxIterations > 0 && $iteration >= $maxIterations) {
                $io->success(sprintf('Reached max iterations (%d). Stopping cleanly.', $maxIterations));
                break;
            }

            usleep(100000); // 100ms
        }

        return Command::SUCCESS;
    }
}
