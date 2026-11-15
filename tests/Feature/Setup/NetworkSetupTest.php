<?php

namespace Tests\Feature\Setup;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NetworkSetupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/warehouse-network-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bin', 0777, true);
        mkdir($this->directory.'/commands');
        copy(base_path('bin/setup'), $this->directory.'/bin/setup');
        if (is_file(base_path('bin/prepare-network'))) {
            copy(base_path('bin/prepare-network'), $this->directory.'/bin/prepare-network');
            chmod($this->directory.'/bin/prepare-network', 0755);
        }
        copy(base_path('.env.example'), $this->directory.'/.env.example');
        foreach (['docker', 'docker-compose'] as $name) {
            copy(base_path('tests/Support/Processes/docker-network.php'), $this->directory.'/commands/'.$name);
            chmod($this->directory.'/commands/'.$name, 0755);
        }
        $this->writeState([]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_setup_recovers_from_exhausted_pools_without_a_manual_network_command(): void
    {
        $process = $this->runCommand('setup');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $state = $this->readState();
        $this->assertSame('warehouse-network-test:default', $state['network']);
        $this->assertSame(['10.240.0.0/24'], $state['subnets']);
        $this->assertTrue(is_file($this->directory.'/.env'));
        $this->assertCallsAreScoped($state);
    }

    #[DataProvider('networkCases')]
    public function test_network_preparation_obeys_ownership_and_failure_rules(
        array $configuration,
        bool $success,
        int $creates,
        int $explicit,
    ): void {
        $this->writeState($configuration);
        $process = $this->runCommand('prepare-network');
        $this->assertSame($success, $process->isSuccessful(), $process->getErrorOutput());
        $state = $this->readState();
        $this->assertSame($creates, $state['create_count']);
        $this->assertCount($explicit, $state['subnets']);
        $this->assertSame(count($state['subnets']), count(array_unique($state['subnets'])));
        $this->assertCallsAreScoped($state);
        if ($success) {
            $this->assertSame($state['project'].':default', $state['network']);
        }
    }

    public static function networkCases(): array
    {
        return [
            'existing owned network' => [['network' => 'warehouse-network-test:default'], true, 0, 0],
            'foreign project' => [['network' => 'someone-else:default'], false, 0, 0],
            'foreign logical network' => [['network' => 'warehouse-network-test:other'], false, 0, 0],
            'standard allocation' => [['pool_exhausted' => false], true, 1, 0],
            'explicit allocation' => [[], true, 2, 1],
            'two overlaps' => [['overlaps' => 2], true, 4, 3],
            'all candidates overlap' => [['overlaps' => 64], false, 65, 64],
            'unrelated standard failure' => [['standard_error' => 'daemon unavailable'], false, 1, 0],
            'unrelated explicit failure' => [['explicit_error' => 'daemon unavailable'], false, 2, 1],
            'standard create race' => [['race' => 'standard'], true, 1, 0],
            'explicit create race' => [['race' => 'explicit'], true, 2, 0],
            'foreign create race' => [['race' => 'standard', 'race_owner' => 'someone-else:default'], false, 1, 0],
            'missing project' => [['project' => ''], false, 0, 0],
            'invalid project' => [['project' => 'unsafe/name'], false, 0, 0],
            'invalid first character' => [['project' => '_unsafe'], false, 0, 0],
            'configuration error' => [['config_error' => true], false, 0, 0],
            'standalone compose' => [['plugin' => false], true, 2, 1],
        ];
    }

    public function test_configuration_values_are_not_printed(): void
    {
        $process = $this->runCommand('prepare-network', false);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringNotContainsString('do-not-print-this-value', $process->getOutput().$process->getErrorOutput());
    }

    public function test_generated_overlap_sequences_remain_bounded_and_idempotent(): void
    {
        for ($case = 0; $case < 16; $case++) {
            $overlaps = $case % 5;
            $this->writeState(['project' => 'warehouse-case-'.$case, 'overlaps' => $overlaps]);
            $first = $this->runCommand('prepare-network');
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $before = $this->readState();
            $this->assertSame($overlaps + 2, $before['create_count']);
            $this->assertSame($overlaps + 1, count($before['subnets']));
            $second = $this->runCommand('prepare-network');
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $after = $this->readState();
            $this->assertSame($before['network'], $after['network']);
            $this->assertSame($before['create_count'], $after['create_count']);
            $this->assertSame($before['subnets'], $after['subnets']);
            $this->assertCallsAreScoped($after);
        }
    }

    private function writeState(array $overrides): void
    {
        file_put_contents($this->directory.'/state.json', json_encode(array_replace([
            'project' => 'warehouse-network-test', 'plugin' => true, 'config_error' => false,
            'network' => null, 'pool_exhausted' => true, 'standard_error' => null,
            'explicit_error' => null, 'overlaps' => 0, 'race' => null,
            'calls' => [], 'create_count' => 0, 'subnets' => [],
        ], $overrides), JSON_THROW_ON_ERROR));
    }

    private function readState(): array
    {
        return json_decode(file_get_contents($this->directory.'/state.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function runCommand(string $name, bool $allowTrace = true): Process
    {
        $trace = $allowTrace && getenv('NETWORK_SHELL_COVERAGE') === '1';
        $arguments = $trace ? ['sh', '-x'] : ['sh'];
        $process = new Process([...$arguments, $this->directory.'/bin/'.$name], $this->directory, [
            'PATH' => $this->directory.'/commands:'.getenv('PATH'),
            'DOCKER_NETWORK_TEST_STATE' => $this->directory.'/state.json',
            'PS4' => '+${LINENO}: ',
        ]);
        $process->setTimeout(30);
        $process->run();
        if ($trace) {
            file_put_contents(
                storage_path('framework/testing/network-shell-trace.jsonl'),
                json_encode(['script' => $name, 'trace' => $process->getErrorOutput()], JSON_THROW_ON_ERROR)."\n",
                FILE_APPEND,
            );
        }

        return $process;
    }

    private function assertCallsAreScoped(array $state): void
    {
        foreach ($state['calls'] as $call) {
            if (array_slice($call, 1, 1) !== ['network']) {
                continue;
            }
            $this->assertContains($call[2], ['inspect', 'create']);
            $this->assertSame($state['project'].'_default', $call[array_key_last($call)]);
            if ($call[2] === 'create') {
                $this->assertContains('com.docker.compose.project='.$state['project'], $call);
                $this->assertContains('com.docker.compose.network=default', $call);
            }
        }
    }
}
