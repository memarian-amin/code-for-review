<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\v1\ServiceGetResource;
use App\Models\Blog;
use App\Models\Api\v1\Categories;
use App\Models\Api\v1\MongoVisit;
use App\Repositories\NewsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;


class ServiceController extends Controller
{

    protected $newsRepository;

    public function __construct(NewsRepository $newsRepository)
    {
        $this->newsRepository = $newsRepository;
    }

    public function index(Request $request)
    {
        $list['list'] = [];
        $service_id = null;
        $location_id = null;

        if ($request->has('service_id') && $request->has('location_id')) {
            $service_id = $request->service_id;
            $location_id = $request->location_id;
        }


        switch ($location_id) {

            case Blog::LAST_CONTENTS: // 9

                $categories = Categories::getID();

                if ($categories) {
                    foreach ($categories as $category) {

                        if ($category->id == 1)
                            continue;

                        $list['list'][] = [
                            'category_id' => $category->id,
                            'category_name' => $category?->fa_name,
                            'category_en_name' => $category?->en_name,
                            'category_slug' => $category?->slug,
                            'list' => ServiceGetResource::collection($this->newsRepository->getByFilters($service_id, $location_id, $category->id, @$request->limit))
                        ];
                    }
                }
                break;

            case Blog::TOP_VIDEO_NEWS: // 10

                $visits = MongoVisit::getByVisit('3');
                if ($visits) {
                    foreach ($visits as $visit) {
                        $service = $this->newsRepository->getByFilters($service_id, $location_id, null, null, $visit->news_id);
                        if ($service)
                            $list['list'] = ServiceGetResource::collection($service);
                    }
                }
                break;

            case Blog::MOST_VISITED_NEWS: // 11 (new, need to add in front)

                $visits = MongoVisit::getByVisit();
                if ($visits) {
                    foreach ($visits as $visit) {
                        $service = $this->newsRepository->getByFilters($service_id, $location_id, null, null, $visit->news_id);
                        if ($service)
                            $list['list'][] = new ServiceGetResource($service);
                    }
                }
                break;

            case Blog::LAST_VISUAL_NEWS: // 13

                $visits = MongoVisit::getByVisit();
                if ($visits) {
                    foreach ($visits as $visit) {
                        $service = $this->newsRepository->getByFilters($service_id, $location_id, null, null, $visit->news_id);

                        $list['list'] = ServiceGetResource::collection($service);
                    }
                }
                break;

            case Blog::TOP_VISUAL_NEWS: // 14

                $visits = MongoVisit::getByVisit(2);
                if ($visits) {
                    foreach ($visits as $visit) {
                        $service = $this->newsRepository->getByFilters($service_id, $location_id, null, null, $visit->news_id);
                        $list['list'] = ServiceGetResource::collection($service);
                    }
                }
                break;

            default:

                $services = $this->newsRepository->getByFilters($service_id, $location_id, null, @$request->limit);

                if ($services)
                    $list['list'] = ServiceGetResource::collection($services);
        }



        return Response::success('لیست سرویس ها', $list, 200);
    }
}
