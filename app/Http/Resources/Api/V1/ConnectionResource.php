<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connection as the REST API lists it: enough to pick one for a request,
 * which is what the web query studio shows a developer (name and driver).
 * Host, port, database, user names and every secret stay out.
 *
 * @mixin Connection
 */
class ConnectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'driver' => $this->driver->value,
            'team_id' => $this->team_id,
        ];
    }
}
