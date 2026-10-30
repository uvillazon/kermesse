<?php

namespace App\Tests\Shared\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsApiV1Payload(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame([
            'status' => 'ok',
            'service' => 'kermesse-backend',
            'version' => 'v1',
        ], json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
