<?php

$finder = Symfony\CS\Finder\DefaultFinder::create()
    ->in(__DIR__.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR);

return Symfony\CS\Config\Config::create()
    ->fixers(array('-braces')) 
    ->finder($finder);