<?php

namespace ScoutElastic\Tests\Payloads;

use ScoutElastic\Payloads\IndexPayload;
use ScoutElastic\Tests\AbstractTestCase;
use ScoutElastic\Tests\Dependencies\IndexConfigurator;

class IndexPayloadTest extends AbstractTestCase
{
    use IndexConfigurator;

    public function testDefault(): void
    {
        $indexConfigurator = $this->mockIndexConfigurator();
        $payload = new IndexPayload($indexConfigurator);

        $this->assertSame(
            ['index' => 'test'],
            $payload->get()
        );
    }

    public function testSet(): void
    {
        $indexConfigurator = $this->mockIndexConfigurator([
            'name' => 'foo',
        ]);

        $payload = (new IndexPayload($indexConfigurator))
            ->set('index', 'bar')
            ->set('settings', ['key' => 'value']);

        $this->assertSame(
            [
                'index' => 'foo',
                'settings' => ['key' => 'value'],
            ],
            $payload->get()
        );
    }

    public function testUseAlias(): void
    {
        $indexConfigurator = $this->mockIndexConfigurator([
            'name' => 'foo',
        ]);

        $payload = (new IndexPayload($indexConfigurator))
            ->useAlias('write');

        $this->assertSame(
            ['index' => 'foo_write'],
            $payload->get()
        );
    }
}
