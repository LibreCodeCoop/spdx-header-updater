#!/usr/bin/env php
<?php
/**
 * SPDX-FileCopyrightText: 2024 Vitor Mattos <vitor@php.rio>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use SpdxConvertor\Command\Convert;
use Symfony\Component\Console\Application;

if (PHP_SAPI !== 'cli') {
    echo 'Warning: Should be invoked via the CLI version of PHP, not the ' . PHP_SAPI . ' SAPI' . PHP_EOL;
}

require __DIR__ . '/../src/bootstrap.php';

$application = new Application();
$application->addCommands([
    new Convert(),
]);
$application->run();
