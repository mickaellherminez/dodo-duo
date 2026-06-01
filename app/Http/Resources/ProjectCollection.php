<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProjectCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => ProjectResource::collection($this->collection)->resolve($request),
            'meta' => [
                'total' => method_exists($this->resource, 'total')
                    ? $this->resource->total()
                    : $this->collection->count(),
                'per_page' => method_exists($this->resource, 'perPage')
                    ? $this->resource->perPage()
                    : $this->collection->count(),
                'current_page' => method_exists($this->resource, 'currentPage')
                    ? $this->resource->currentPage()
                    : 1,
                'last_page' => method_exists($this->resource, 'lastPage')
                    ? $this->resource->lastPage()
                    : 1,
            ],
        ];
    }
}
