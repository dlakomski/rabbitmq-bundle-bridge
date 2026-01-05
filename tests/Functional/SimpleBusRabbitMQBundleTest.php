<?php

namespace SimpleBus\RabbitMQBundleBridge\Tests\Functional;

use Asynchronicity\PHPUnit\Eventually;
use Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SimpleBus\Asynchronous\Properties\DelegatingAdditionalPropertiesResolver;
use SimpleBus\Message\Bus\MessageBus;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Process\Process;

class SimpleBusRabbitMQBundleTest extends KernelTestCase
{
    private FileLogger $logger;

    /**
     * @var null|Process<Generator>
     */
    private ?Process $process = null;

    private static ?Application $application = null;

    /**
     * Timeout for asynchronous tests.
     */
    private int $timeoutMs = 10000;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $logger = static::getContainer()->get('logger');

        $this->assertInstanceof(FileLogger::class, $logger);

        $this->logger = $logger;
        $this->logger->clearFile();

        $application = self::getApplication();
        $code = $application->run(new StringInput('rabbitmq:setup-fabric --quiet'));

        $this->assertSame(Command::SUCCESS, $code, 'Incorrect setup-fabric process exit code');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        static::$class = null;

        if ($this->process instanceof Process) {
            $this->process->stop(2, SIGKILL);
        }
    }

    #[Test]
    #[Group('functional')]
    public function itHandlesCommandsAsynchronously(): void
    {
        $this->consumeMessagesFromQueue('asynchronous_commands');

        $this->commandBus()->handle(new AsynchronousCommand());

        $this->waitUntilLogFileContains('debug No message handler found, trying to handle it asynchronously');

        $this->waitUntilLogFileContains('Handling message');
    }

    #[Test]
    #[Group('functional')]
    public function itHandlesEventsAsynchronously(): void
    {
        $this->consumeMessagesFromQueue('asynchronous_events');

        $this->eventBus()->handle(new Event());

        $this->waitUntilLogFileContains('Notified of message');
    }

    #[Test]
    #[Group('functional')]
    public function itLogsErrors(): void
    {
        $this->consumeMessagesFromQueue('asynchronous_commands');

        $this->commandBus()->handle(new AlwaysFailingCommand());

        $this->waitUntilLogFileContains('Failed to handle a message');
    }

    #[Test]
    #[Group('functional')]
    public function itResolveProperties(): void
    {
        $data = $this->additionalPropertiesResolver()->resolveAdditionalPropertiesFor($this->messageDummy());

        $this->assertSame(['debug' => 'string'], $data);
    }

    #[Test]
    #[Group('functional')]
    public function itSendsPropertiesToProducer(): void
    {
        $container = static::getContainer();
        $container->set('old_sound_rabbit_mq.asynchronous_commands_producer', $container->get('simple_bus.rabbit_mq_bundle_bridge.delegating_additional_properties_resolver.producer_mock'));

        $this->commandBus()->handle(new AsynchronousCommand());

        $producer = $container->get('simple_bus.rabbit_mq_bundle_bridge.delegating_additional_properties_resolver.producer_mock');

        $this->assertInstanceOf(AdditionalPropertiesResolverProducerMock::class, $producer);

        $data = $producer->getAdditionalProperties();
        $this->assertSame(['debug' => 'string'], $data);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function waitUntilLogFileContains(string $message): void
    {
        self::assertThat(
            function () use ($message) {
                $this->logger->fileContains($message);
            },
            new Eventually($this->timeoutMs, 100),
            sprintf('The log file does not contain "%s"', $message),
        );
    }

    private function commandBus(): MessageBus
    {
        $commandBus = static::getContainer()->get('command_bus');

        $this->assertInstanceOf(MessageBus::class, $commandBus);

        return $commandBus;
    }

    private function eventBus(): MessageBus
    {
        $eventBus = static::getContainer()->get('event_bus');

        $this->assertInstanceOf(MessageBus::class, $eventBus);

        return $eventBus;
    }

    private function additionalPropertiesResolver(): DelegatingAdditionalPropertiesResolver
    {
        $resolver = static::getContainer()->get('simple_bus.rabbit_mq_bundle_bridge.delegating_additional_properties_resolver.public');

        $this->assertInstanceOf(DelegatingAdditionalPropertiesResolver::class, $resolver);

        return $resolver;
    }

    private function messageDummy(): stdClass
    {
        return new stdClass();
    }

    private function consumeMessagesFromQueue(string $queue): void
    {
        $this->process = new Process(
            ['php', 'console.php', 'rabbitmq:consumer', $queue],
            __DIR__,
        );

        $this->process->start();
    }

    private static function getApplication(): Application
    {
        if (null === self::$application) {
            self::$application = new Application(self::createKernel());
            self::$application->setAutoExit(false);
        }

        return self::$application;
    }
}
