<?php declare (strict_types=1);

namespace SomeNamespace;

use Symfony\Component\Process\ProcessBuilder;

$process = (new ProcessBuilder('something'))->getProcess();
