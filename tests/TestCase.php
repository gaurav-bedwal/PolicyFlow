<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        error_reporting(E_ALL & ~E_DEPRECATED);
        parent::setUp();
    }
}
