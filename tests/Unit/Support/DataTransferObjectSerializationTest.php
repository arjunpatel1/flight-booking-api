<?php

namespace Tests\Unit\Support;

use Modules\Support\Contracts\DataTransferObject;
use Modules\Support\Traits\SerializesDataTransferObject;
use Tests\TestCase;

class DataTransferObjectSerializationTest extends TestCase
{
    public function test_data_transfer_object_serializes_to_array_and_json(): void
    {
        $dto = new class implements DataTransferObject {
            use SerializesDataTransferObject;

            public function toArray(): array
            {
                return [
                    'id' => 10,
                    'name' => 'POS Menu',
                ];
            }
        };

        $this->assertSame([
            'id' => 10,
            'name' => 'POS Menu',
        ], $dto->jsonSerialize());

        $this->assertJsonStringEqualsJsonString(
            '{"id":10,"name":"POS Menu"}',
            $dto->toJson()
        );
    }
}
