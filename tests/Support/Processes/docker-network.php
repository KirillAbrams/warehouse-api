#!/usr/bin/env php
<?php

$path = getenv('DOCKER_NETWORK_TEST_STATE');
$state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$arguments = array_slice($argv, 1);
$program = basename($argv[0]);
$state['calls'][] = [$program, ...$arguments];

// Record the requested Docker command before returning the configured response.
$finish = static function (int $code, string $message = '') use ($path, &$state): never {
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    fwrite($code === 0 ? STDOUT : STDERR, $message);
    exit($code);
};

if ($program === 'docker-compose' || ($arguments[0] ?? null) === 'compose') {
    if ($program === 'docker') {
        array_shift($arguments);
    }

    if (($arguments[0] ?? null) === 'version') {
        $finish($state['plugin'] ? 0 : 1);
    }

    if ($arguments === ['config', '--environment']) {
        if ($state['config_error']) {
            $finish(1, "invalid compose configuration\n");
        }

        $finish(0, 'COMPOSE_PROJECT_NAME=' . $state['project'] . "\nAPP_KEY=do-not-print-this-value\n");
    }

    // Reproduce the original failure before container startup needs its network.
    if (in_array($arguments[0] ?? null, ['run', 'up'], true) && $state['network'] === null) {
        if ($state['pool_exhausted']) {
            $finish(1, "all predefined address pools have been fully subnetted\n");
        }

        $state['network'] = $state['project'] . ':default';
    }

    if (in_array('key:generate', $arguments, true)) {
        $file = getcwd() . '/.env';
        file_put_contents($file, str_replace('APP_KEY=', 'APP_KEY=base64:example', file_get_contents($file)));
    }

    $finish(0);
}

if (array_slice($arguments, 0, 2) === ['network', 'inspect']) {
    $finish($state['network'] === null ? 1 : 0, $state['network'] === null ? "No such network\n" : $state['network'] . "\n");
}

if (array_slice($arguments, 0, 2) !== ['network', 'create']) {
    $finish(99, "Unexpected Docker operation\n");
}

$subnetIndex = array_search('--subnet', $arguments, true);
$explicit = $subnetIndex !== false;
$state['create_count']++;

if ($state['race'] === ($explicit ? 'explicit' : 'standard')) {
    $state['network'] = $state['race_owner'] ?? $state['project'] . ':default';
    $finish(1, "network already exists\n");
}

if ($explicit) {
    $state['subnets'][] = $arguments[$subnetIndex + 1];
    if ($state['explicit_error'] !== null) {
        $finish(1, $state['explicit_error'] . "\n");
    }
    if (count($state['subnets']) <= $state['overlaps']) {
        $finish(1, "Pool overlaps with other one on this address space\n");
    }
} elseif ($state['standard_error'] !== null) {
    $finish(1, $state['standard_error'] . "\n");
} elseif ($state['pool_exhausted']) {
    $finish(1, "all predefined address pools have been fully subnetted\n");
}

$state['network'] = $state['project'] . ':default';
$finish(0, "example-network-id\n");
