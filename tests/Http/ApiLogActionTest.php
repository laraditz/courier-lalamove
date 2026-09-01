<?php

namespace Laraditz\Courier\Lalamove\Tests\Http;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Laraditz\Courier\Lalamove\Http\LalamoveClient;
use Laraditz\Courier\Lalamove\Tests\TestCase;
use Laraditz\Courier\Models\CourierApiLog;

// Every named API method must log under a stable, greppable action. The column is
// only indexed via driver, so these strings are the primary way anyone finds a call
// in courier_api_logs — they are effectively public API and must not drift.
class ApiLogActionTest extends TestCase
{
    private const ORDER = 'ORD-001';

    private function client(): LalamoveClient
    {
        return new LalamoveClient([
            'key'     => 'pk_test_key',
            'secret'  => 'sk_test_secret',
            'sandbox' => true,
            'market'  => 'MY',
        ]);
    }

    public static function namedMethodProvider(): array
    {
        return [
            // method call                                          action                 waybill_number
            'createQuotation'   => [fn ($c) => $c->createQuotation(['serviceType' => 'MOTORCYCLE']), 'create_quotation', null],
            'getQuotation'      => [fn ($c) => $c->getQuotation('QUO-1'),                'get_quotation',       null],
            'createOrder'       => [fn ($c) => $c->createOrder(['quotationId' => 'Q1']), 'create_order',        null],
            'getOrder'          => [fn ($c) => $c->getOrder(self::ORDER),                'get_order',           self::ORDER],
            'cancelOrder'       => [fn ($c) => $c->cancelOrder(self::ORDER),             'cancel_order',        self::ORDER],
            'getCities'         => [fn ($c) => $c->getCities(),                          'get_cities',          null],
            'removeDriver'      => [fn ($c) => $c->removeDriver(self::ORDER, 'DRV-9'),   'remove_driver',       self::ORDER],
            'addPriorityFee'    => [fn ($c) => $c->addPriorityFee(self::ORDER, ['amount' => '10']), 'add_priority_fee', self::ORDER],
            'getDriverLocation' => [fn ($c) => $c->getDriverLocation(self::ORDER, 'DRV-9'), 'get_driver_location', self::ORDER],
            'editOrder'         => [fn ($c) => $c->editOrder(self::ORDER, [['address' => 'A']]), 'edit_order',   self::ORDER],
            'setWebhookUrl'     => [fn ($c) => $c->setWebhookUrl('https://example.test/hook'), 'set_webhook_url', null],
        ];
    }

    #[DataProvider('namedMethodProvider')]
    public function test_named_method_logs_its_action_and_waybill(callable $call, string $action, ?string $waybill): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $call($this->client());

        $log = CourierApiLog::sole();

        $this->assertSame('lalamove', $log->driver);
        $this->assertSame($action, $log->action);
        $this->assertSame($waybill, $log->waybill_number);
    }
}
