<?php
/**
 * SPDX-FileCopyrightText: 2024 Vitor Mattos <vitor@php.rio>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

$includeIfExists = function (string $file): bool {
    if (file_exists($file)) {
        include $file;
        return true;
    }
    return false;
};

if ((!$includeIfExists(__DIR__ . '/../vendor/autoload.php')) && (!$includeIfExists(__DIR__ . '/../../../autoload.php'))) {
    echo 'You must set up the project dependencies using `composer install`' . PHP_EOL .
        'See https://getcomposer.org/download/ for instructions on installing Composer' . PHP_EOL;
    exit(1);
}
