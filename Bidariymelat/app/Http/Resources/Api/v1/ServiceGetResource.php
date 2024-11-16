<?php

namespace App\Http\Resources\Api\v1;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceGetResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category_id' => $this->explodeIfContains($this->category_id),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'summary' => $this->summary ?? '',
            'content' => $this->content,
            'cover_images' => $this->explodeIfContains(
                str_replace(
                    ['https://devapi.bidariymelat.ir', 'https://api.bidariymelat.ir'],
                    config('app.url'),
                    $this->cover_image_file
                )
            ),
            'images' => $this->getFiles($this, 1),
            'videos' => $this->getFiles($this, 2),
            'podcasts' => $this->getFiles($this, 3),
            'slug' => $this->slug ?: '',
            'total_page' => 1,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];

    }

    protected function explodeIfContains($value)
    {
        return $value ? (str_contains($value, ',') ? explode(',', $value) : [$value]) : [];
    }

    protected function getFiles($model, $type)
    {
        $result = [];

        switch ($type) {
            case 1: // images
                $models = $model->getMedia('images');
                break;
            case 2: // videos
                $models = $model->getMedia('videos');
                break;
            case 3: // audios
                $models = $model->getMedia('audios');
                break;
            default:
                // Create an empty Eloquent collection
                $models = new Collection();
                break;
        }

        if (!$models->isEmpty()) {
            foreach ($models as $model) {
                $result[] = $model->getFullUrl();
            }
        }

        return $result;

    }

}
