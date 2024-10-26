<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Vitor Mattos <vitor@php.rio>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace SpdxConvertor\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'convert',
    description: 'Convert to SPDX'
)]
class Convert extends Command
{
    private OutputInterface $output;
    private string $defaultFileCoyprightText;
    private array $preserveSpdx = [];
    protected function configure()
    {
        $this
            ->setHelp(
                <<<HELP
                <info>Run the script with --dry-run until all files can be converted.
                Otherwise the author list can not be generated correctly.</info>
                HELP
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
            )
            ->addOption(
                'ignore-dir',
                'i',
                InputOption::VALUE_REQUIRED |   InputOption::VALUE_IS_ARRAY,
                'Directories to ignore',
            )
            ->addOption(
                'preserve-spdx',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'The list of SPDX to preserve. Example: "OldCompanyName"'
            )
            ->addArgument(
                'default-file-coypright',
                InputArgument::REQUIRED,
                'The default file copyright text. Example: "Free Software Foundation Europe e.V. <https://fsfe.org>"'
            )
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'The path to process',
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $isDryRun = $input->getOption('dry-run');
        $ignoreDirs = $input->getOption('ignore-dir');
        $this->preserveSpdx = $input->getOption('preserve-spdx');
        $this->defaultFileCoyprightText = $input->getArgument('default-file-coypright');

        $path = realpath($input->getArgument('path'));
        if (!$isDryRun && !count($ignoreDirs) && !$path) {
            $output->writeln($this->getHelp());
            return Command::INVALID;
        }

        $finder = new \Symfony\Component\Finder\Finder();
        $finder->ignoreVCSIgnored(true)
            ->exclude($ignoreDirs)
            ->sortByName();
        foreach ($ignoreDirs as $exclude) {
            $output->writeln(' ├─ ◽ ' . $exclude . ' skipped');
        }

        if (file_exists($path . '/.reuse/dep5')) {
            $dep5 = file_get_contents($path . '/.reuse/dep5');
            $lines = explode("\n", $dep5);
            $lines = array_filter($lines, static fn (string $line) => str_starts_with($line, 'Files: '));

            foreach ($lines as $line) {
                $line = preg_replace('/\s+/', ' ', trim($line));
                $files = explode(' ', $line);
                array_shift($files);

                foreach ($files as $file) {
                    $pathFilter = $file;
                    if (str_contains($file, '*')) {
                        $pathFilter = '/'. str_replace(['/', '.', '*'], ['\/', '\.', '(.*)'], $file) . '$/i';
                    }
                    $finder->notPath($pathFilter);
                }
            }
        }
        $finder->in($path);

        $notHandled = '';
        $authors = [];
        foreach ($finder->getIterator() as $file) {

            if ($file->getExtension() === 'php' || $file->getExtension() === 'css' || $file->getExtension() === 'scss' || $file->getExtension() === 'm' || $file->getExtension() === 'h') {
                if (!str_contains($file->getRealPath(), '/lib/Vendor/')
                    && !str_contains($file->getRealPath(), '/vendor/')
                    && !str_contains($file->getRealPath(), '/tests/stubs/')) {
                    $authors[] = $this->replacePhpOrCSSOrMOrHCopyright($file->getRealPath(), $isDryRun);
                } else {
                    $output->writeln(" ├─ 🔶 \033[0;33m" . $file->getRealPath() . ' skipped' . "\033[0m");
                }
            } elseif (preg_match('/^[mc]?[tj]s$/', $file->getExtension())) {
                if (
                    !str_contains($file->getRealPath(), '/vendor/')
                ) {
                    $authors[] = $this->replaceJavaScriptCopyright($file->getRealPath(), $isDryRun);
                } else {
                    $output->writeln(" ├─ 🔶 \033[0;33m" . $file->getRealPath() . ' skipped' . "\033[0m");
                }
            } elseif ($file->getExtension() === 'vue' || $file->getExtension() === 'html') {
                if (
                    !str_contains($file->getRealPath(), '/vendor/')
                ) {
                    $authors[] = $this->replaceVueCopyright($file->getRealPath(), $isDryRun);
                } else {
                    $output->writeln(" ├─ 🔶 \033[0;33m" . $file->getRealPath() . ' skipped' . "\033[0m");
                }
            } elseif ($file->getExtension() === 'swift') {
                $authors[] = $this->replaceSwiftCopyright($file->getRealPath(), $isDryRun);
            } elseif (!$file->isDir()) {
                if (
                    str_ends_with($file->getRealPath(), 'composer.json')
                    || str_ends_with($file->getRealPath(), 'composer.lock')
                    || str_ends_with($file->getRealPath(), '.md')
                    || str_ends_with($file->getRealPath(), '.png')
                    || str_ends_with($file->getRealPath(), '.svg')
                    || str_ends_with($file->getRealPath(), '.xml')
                    || str_ends_with($file->getRealPath(), '.json')
                ) {
                    $output->writeln(' ├─ ◽ ' . $file->getRealPath() . ' skipped');
                } elseif (
                    !str_contains($file->getRealPath(), '/tests/integration/vendor/')
                    && !(str_starts_with($file->getRealPath(), $path . 'l10n/') && str_ends_with($file->getRealPath(), '.json'))
                    && !str_contains($file->getRealPath(), '/tests/integration/phpserver.log')
                    && !str_contains($file->getRealPath(), '/tests/integration/phpserver_fed.log')
                ) {
                    $notHandled .= " ├─ ❌ \033[0;31m" . $file . ' Not handled' . "\033[0m\n";
                }
            }
        }

        $output->writeln($notHandled);

        $authorList = array_merge(...$authors);
        sort($authorList);
        $authorList = array_unique($authorList);

        $authorsContent = "# Authors\n\n- " . implode("\n- ", $authorList) . "\n";
        if ($isDryRun) {
            $output->writeln(" └─ ✅ \033[0;32mCan generate AUTHORS.md" . "\033[0m\n");
            $output->writeln($authorsContent);
        } else {
            file_put_contents($path . 'AUTHORS.md', $authorsContent, FILE_APPEND);
            $output->writeln(" └─ ✅ \033[0;32mAppended AUTHORS.md" . "\033[0m");
        }
        return Command::SUCCESS;
    }

    private function abortFurtherAnalysing(bool $isDryRun): void
    {
        $this->output->writeln(
            "<error>                                                                      </error>\n" .
            "<error>                            ❌ ABORTING ❌                            </error>\n" .
            "<error> Please manually fix the error pointed out above and rerun the script.</error>\n" .
            "<error>                                                                      </error>\n"
        );

        if (!$isDryRun) {
            $this->output->writeln($this->getHelp());
        }

        exit(1);
    }

    private function generateSpdxContent(string $originalHeader, string $file, bool $isDryRun): array
    {
        $copyrightYear = 3000;
        $newHeaderLines = [];
        $authors = [];
        $license = null;
        $preserveSpdx = implode(',', $this->preserveSpdx);

        foreach (explode("\n", $originalHeader) as $line) {
            // @copyright Copyright (c) 2023 John Doe <john@doe.coop>
            if (preg_match('/@copyright Copyright \(c\) (\d{4}),? ([^<]+) <([^>]+)>/', $line, $m)) {
                if (str_contains(strtolower($m[2]), $preserveSpdx)) {
                    $newHeaderLines[] = "SPDX-FileCopyrightText: {$m[1]} {$m[2]} <{$m[3]}>";
                } elseif ($copyrightYear > $m[1]) {
                    $copyrightYear = (int) $m[1];
                }
                $authors[] = "{$m[2]} <{$m[3]}>";

                // @copyright 2023 John Doe <john@doe.coop>
            } elseif (preg_match('/@copyright (\d{4}),? ([^<]+) <([^>]+)>/', $line, $m)) {
                if (str_contains(strtolower($m[2]), $preserveSpdx)) {
                    $newHeaderLines[] = "SPDX-FileCopyrightText: {$m[1]} {$m[2]} <{$m[3]}>";
                } elseif ($copyrightYear > $m[1]) {
                    $copyrightYear = (int) $m[1];
                }
                $authors[] = "{$m[2]} <{$m[3]}>";

                // @copyright Copyright (c) 2023 John Doe (john@doe.coop)
            } elseif (preg_match('/@copyright Copyright \(c\) (\d{4}),? ([^<]+) \(([^>]+)\)/', $line, $m)) {
                if (str_contains(strtolower($m[2]), $preserveSpdx)) {
                    $newHeaderLines[] = "SPDX-FileCopyrightText: {$m[1]} {$m[2]} <{$m[3]}>";
                } elseif ($copyrightYear > $m[1]) {
                    $copyrightYear = (int) $m[1];
                }
                $authors[] = "{$m[2]} <{$m[3]}>";

                // @copyright Copyright (c) 2023 John Doe Compay Name, https://company.tld
            } elseif (preg_match('/@copyright Copyright \(c\) (\d{4}),? ([^\n]+)/', $line, $m)) {
                if (str_contains(strtolower($m[2]), $preserveSpdx)) {
                    $newHeaderLines[] = "SPDX-FileCopyrightText: {$m[1]} {$m[2]}";
                } elseif ($copyrightYear > $m[1]) {
                    $copyrightYear = (int) $m[1];
                }
                $authors[] = $m[2];

                // Copyright (c) 2024 John Doe <john@doe.coop>
            } elseif (preg_match('/Copyright \(c\) (\d{4}),? ([^<]+) <([^>]+)>/', $line, $m)) {
                if (str_contains(strtolower($m[2]), $preserveSpdx)) {
                    $newHeaderLines[] = "SPDX-FileCopyrightText: {$m[1]} {$m[2]} <{$m[3]}>";
                } elseif ($copyrightYear > $m[1]) {
                    $copyrightYear = (int) $m[1];
                }
                $authors[] = "{$m[2]} <{$m[3]}>";

                // @copyright 2023
            } elseif (preg_match('/@copyright (\d{4})/', $line, $m)) {
            } elseif (preg_match('/@author ([^\n]+)/', $line, $m)) {
                $authors[] = $m[1];
            } elseif (str_contains($line, '@license AGPL-3.0-or-later')) {
                $license = 'SPDX-License-Identifier: AGPL-3.0-or-later';
            } elseif (str_contains($line, '@license AGPL-3.0-or-later')) {
                $license = 'SPDX-License-Identifier: AGPL-3.0-or-later';
            } elseif (str_contains($line, '@license GNU AGPL version 3 or any later version')) {
                $license = 'SPDX-License-Identifier: AGPL-3.0-or-later';
            } elseif (str_contains($line, '@license AGPL-3.0')) {
                $license = 'SPDX-License-Identifier: AGPL-3.0-only';
            } elseif (str_contains($line, '@license GNU GPL version 3 or any later version')) {
                $license = 'SPDX-License-Identifier: GPL-3.0-or-later';
            } elseif (str_contains($line, 'This file is licensed under the Affero General Public License version 3 or')) {
                $license = 'SPDX-License-Identifier: AGPL-3.0-or-later';
            } elseif (str_contains($line, '// GNU GPL version 3 or any later version')) {
                $license = 'SPDX-License-Identifier: GPL-3.0-or-later';
            } elseif (str_contains($line, 'it under the terms of the GNU General Public License as published by')) {
            } elseif (str_contains($line, 'it under the terms of the GNU Affero General Public License as')) {
            } elseif (str_contains($line, 'it under the terms of the GNU Afferoq General Public License as')) {
            } elseif (str_contains($line, 'it under the terms of the GNU Affero General Public License, version 3,')) {
            } elseif (str_contains($line, 'License, or (at your option) any later version.')) {
            } elseif (str_contains($line, 'GNU General Public License for more details.')) {
            } elseif (str_contains($line, 'GNU Affero General Public License for more details.')) {
            } elseif (str_contains($line, 'You should have received a copy of the GNU General Public License')) {
            } elseif (str_contains($line, 'You should have received a copy of the GNU Affero General Public License')) {
            } elseif (str_contains($line, 'the Free Software Foundation, either version 3 of the License, or')) {
            } elseif (str_contains($line, 'along with this program.  If not, see <http://www.gnu.org/licenses/>')) {
            } elseif (str_contains($line, 'along with this program. If not, see <http://www.gnu.org/licenses/>')) {
            } elseif (str_contains(strtolower($line), 'license')) {
                $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' Unrecognized license:' . "\033[0m");
                $this->output->writeln('    └─ ' . $line);
                $this->abortFurtherAnalysing($isDryRun);
            } elseif (str_contains(strtolower($line), 'copyright')) {
                $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' Unrecognized copyright:' . "\033[0m");
                $this->output->writeln('    └─ ' . $line);
                $this->abortFurtherAnalysing($isDryRun);
            }
        }

        if ($copyrightYear !== 3000) {
            array_unshift($newHeaderLines, "SPDX-FileCopyrightText: $copyrightYear " . $this->defaultFileCoyprightText);
        }

        if ($license === null) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No license found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $newHeaderLines = array_unique($newHeaderLines);
        $newHeaderLines[] = $license;

        return [$authors, $newHeaderLines];
    }

    private function replacePhpOrCSSOrMOrHCopyright(string $file, bool $isDryRun): array
    {
        $content = file_get_contents($file);

        $headerStart = str_starts_with($content, '/*') ? 0 : strpos($content, "\n/*");
        if ($headerStart === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $headerEnd = strpos($content, '*/', $headerStart);
        if ($headerEnd === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment END found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $originalHeader = substr($content, $headerStart, $headerEnd - $headerStart + strlen('*/'));
        if (str_contains($originalHeader, 'SPDX')) {
            $this->output->writeln(" ├─ ✅ \033[0;32m" . $file . ' SPDX' . "\033[0m");
            return [];
        }

        [$authors, $newHeaderLines] = $this->generateSPDXcontent($originalHeader, $file, $isDryRun);
        $newHeader = (($headerStart === 0) ? '' : "\n") . "/**\n * " . implode("\n * ", $newHeaderLines) . "\n */";

        if ($isDryRun) {
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' would replace' . "\033[0m");
        } else {
            file_put_contents(
                $file,
                str_replace($originalHeader, $newHeader, $content)
            );
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' replaced' . "\033[0m");
        }
        return $authors;
    }

    private function replaceJavaScriptCopyright(string $file, bool $isDryRun): array
    {
        $content = file_get_contents($file);

        $headerStart = str_starts_with($content, '/*') ? 0 : strpos($content, "\n/*");
        if ($headerStart === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $headerEnd = strpos($content, '*/', $headerStart);
        if ($headerEnd === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment END found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $originalHeader = substr($content, $headerStart, $headerEnd - $headerStart + strlen('*/'));
        if (str_contains($originalHeader, 'SPDX')) {
            $this->output->writeln(" ├─ ✅ \033[0;32m" . $file . ' SPDX' . "\033[0m");
            return [];
        }

        [$authors, $newHeaderLines] = $this->generateSpdxContent($originalHeader, $file, $isDryRun);
        $newHeader = (($headerStart === 0) ? '' : "\n") . "/**\n * " . implode("\n * ", $newHeaderLines) . "\n */";

        if ($isDryRun) {
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' would replace' . "\033[0m");
        } else {
            file_put_contents(
                $file,
                str_replace($originalHeader, $newHeader, $content)
            );
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' replaced' . "\033[0m");
        }
        return $authors;
    }

    private function replaceVueCopyright(string $file, bool $isDryRun): array
    {
        $content = file_get_contents($file);

        $headerStart = str_starts_with($content, '<!--') ? 0 : strpos($content, "\n<!--");
        if ($headerStart === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $headerEnd = strpos($content, '-->', $headerStart);
        if ($headerEnd === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment END found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $originalHeader = substr($content, $headerStart, $headerEnd - $headerStart + strlen('-->'));
        if (str_contains($originalHeader, 'SPDX')) {
            $this->output->writeln(" ├─ ✅ \033[0;32m" . $file . ' SPDX' . "\033[0m");
            return [];
        }

        [$authors, $newHeaderLines] = $this->generateSpdxContent($originalHeader, $file, $isDryRun);
        $newHeader = (($headerStart === 0) ? '' : "\n") . "<!--\n  - " . implode("\n  - ", $newHeaderLines) . "\n-->";

        if ($isDryRun) {
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' would replace' . "\033[0m");
        } else {
            file_put_contents(
                $file,
                str_replace($originalHeader, $newHeader, $content)
            );
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' replaced' . "\033[0m");
        }
        return $authors;
    }

    private function replaceSwiftCopyright(string $file, bool $isDryRun): array
    {
        $content = file_get_contents($file);

        $headerStart = str_starts_with($content, '//') ? 0 : strpos($content, "\n//");
        if ($headerStart === false) {
            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment found' . "\033[0m");
            $this->abortFurtherAnalysing($isDryRun);
        }

        $headerEndToken = "import";
        $headerEnd = strpos($content, $headerEndToken, $headerStart);
        if ($headerEnd === false) {
            $headerEndToken = "extension";
            $headerEnd = strpos($content, $headerEndToken, $headerStart);
            if ($headerEnd === false) {
                $headerEndToken = "protocol";
                $headerEnd = strpos($content, $headerEndToken, $headerStart);
                if ($headerEnd === false) {
                    $headerEndToken = "@objcMembers";
                    $headerEnd = strpos($content, $headerEndToken, $headerStart);
                    if ($headerEnd === false) {
                        $headerEndToken = "class";
                        $headerEnd = strpos($content, $headerEndToken, $headerStart);
                        if ($headerEnd === false) {
                            $this->output->writeln(" ├─ ❌ \033[0;31m" . $file . ' No header comment END found' . "\033[0m");
                            $this->abortFurtherAnalysing($isDryRun);
                        }
                    }
                }
            }
        }

        $originalHeader = substr($content, $headerStart, $headerEnd - $headerStart + strlen($headerEndToken));
        if (str_contains($originalHeader, 'SPDX')) {
            $this->output->writeln(" ├─ ✅ \033[0;32m" . $file . ' SPDX' . "\033[0m");
            return [];
        }

        [$authors, $newHeaderLines] = $this->generateSpdxContent($originalHeader, $file, $isDryRun);
        $newHeader = (($headerStart === 0) ? '' : "\n") . "//\n// " . implode("\n// ", $newHeaderLines) . "\n//\n\n" . $headerEndToken;

        if ($isDryRun) {
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' would replace' . "\033[0m");
        } else {
            file_put_contents(
                $file,
                str_replace($originalHeader, $newHeader, $content)
            );
            $this->output->writeln(" ├─ ☑️  \033[0;36m" . $file . ' replaced' . "\033[0m");
        }
        return $authors;
    }
}
