<?php

namespace App\Repositories;

use App\Models\Blog;
use App\Models\Api\v1\BreakingNews;
use App\Models\Api\v1\CategoryIsTop;
use App\Models\Api\v1\CategoryIsFeatured;
use Illuminate\Support\Facades\DB;

class NewsRepository
{

    // Simple find
    public function find($id)
    {
        return Blog::query()->find($id);
    }

    // Simple get
    public function get($limit): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $limit = $limit ?: 7;
        return Blog::query()->where('status', '1')->orderBy('type')->paginate($limit);
    }

    // Method for internal news
    public function getByFilterInternalNews($service_id, $location_id, $limit = null)
    {
        $limit = $limit ?: 7;

        switch ($location_id) {
                // Top news by service id
            case 1:
                return Blog::query()->whereRaw("FIND_IN_SET($service_id, category_id)")->where([
                    'status' => '1',
                    'is_top' => '1'
                ])->orderBy('top_number', 'asc')->paginate($limit);

                // Last news by service id
            case 2:
                return Blog::query()->whereRaw("FIND_IN_SET($service_id, category_id)")->where([
                    'status' => '1'
                ])->orderBy('id', 'desc')->paginate($limit);

                // Report and news
            case 3:
                return Blog::query()->where('status', '1')->whereRaw("FIND_IN_SET(11, category_id)")->orderBy('id', 'desc')->paginate($limit);

                // Last videos
            case 4:
                return Blog::query()->whereRaw("FIND_IN_SET($service_id, category_id)")->where([
                    'type' => '3',
                    'status' => '1'
                ])->orderBy('id', 'desc')->paginate($limit);
        }
    }

    // method for breaking news
    public static function getBreakingNews($limit = null)
    {
        $limit = $limit ?: 7;

        $breaking_news_ids = BreakingNews::query()
            ->pluck('news_id')
            ->toArray();

        return Blog::query()->whereIn('id', $breaking_news_ids)->limit($limit)->get();

    }

    // Methods for ServiceController
    public function getByFilters($serviceId, $locationId, $categoryId = null, $limit = null, $newsId = null)
    {
        $limit = $limit ?: 7;

        switch ($locationId) {
            case Blog::TOP:
                return $this->getTopNews($serviceId, $limit);

            case Blog::FEATURED:
                return $this->getFeaturedNews($serviceId, $limit);

            case Blog::FEATURED_VIDEO:
                return $this->getVideFeatured($serviceId, 3, $limit);

            case Blog::REPORTS_ANALYSES:
                return $this->getReportsAndAnalyses($limit);

            case Blog::LAST_NEWS:
                return $this->getLastNews($serviceId, $limit);

            case Blog::LATEST_VIDEO:
                return $this->getLatestVideo($serviceId, $limit);

            case Blog::LAST_CONTENTS:
                return $this->getLastContents($categoryId, $limit);

            case Blog::LATEST_NEWS_OF_CATEGORIES:
                return $this->getLatestNewsOfCategories($serviceId, $limit);

            case Blog::TOP_VIDEO_NEWS:
                return $this->getTopVideoNews($serviceId, $limit, $newsId);

            case Blog::MOST_VISITED_NEWS: // (new)
                return $this->getMostVisitedNews($limit, $newsId);

                // Extra requests
                //
            case Blog::LAST_VISUAL_NEWS:
                return $this->getLastVisualNews($limit);

            case Blog::TOP_VISUAL_NEWS:
                return $this->getTopVisualNews($serviceId, $limit);

            default:
                return $this->getDefaultNews($serviceId, $limit);
        }
    }

    protected function getTopNews($serviceId, $limit)
    {
        if ($serviceId == 1) {
            return Blog::where('status', '1')
                ->where('is_top', '1')
                ->orderBy('top_number')
                ->limit($limit)
                ->get();
        }else {

            $topIds = CategoryIsTop::getCategoryTopNews($serviceId, $limit);
            if (!empty($topIds)) {
                return Blog::query()
                    ->whereIn('id', $topIds)
                    ->orderByRaw(DB::raw("FIELD(id, " . implode(',', $topIds) . ")"))
                    ->get();
            } else {
                // Handle empty $topIds scenario
                return [];
            }
        }
    }

    protected function getFeaturedNews($serviceId, $limit)
    {
        if ($serviceId == 1) {
            return Blog::where('status', '1')
                ->where('is_featured', '1')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } else {

            $featuredIds = CategoryIsFeatured::getCategoryFeatured($serviceId, $limit);

            return Blog::whereIn('id', $featuredIds)->get();
        }
    }

    protected function getVideFeatured($serviceId, $type, $limit)
    {

        if ($serviceId == 1) {
            return Blog::where('status', '1')
                ->where('is_featured', '1')
                ->where('type', $type)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } else {

            $featuredIds = CategoryIsFeatured::getCategoryFeatured($serviceId, $limit);

            $blogs = Blog::whereIn('id', $featuredIds)->get();

            return $blogs->filter(function ($blog) {
                return $blog->type == 3;
            });
        }
    }

    protected function getReportsAndAnalyses($limit)
    {
        return Blog::where('status', '1')
            ->whereRaw("FIND_IN_SET(?, category_id)", [11])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    protected function getLastNews($serviceId, $limit)
    {
        if ($serviceId == 1) {
            return Blog::query()->where('status', '1')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } else {
            return Blog::query()->where('status', '1')
                ->whereRaw("FIND_IN_SET(?, category_id)", [$serviceId])
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        }
    }

    protected function getLatestVideo($serviceId, $limit)
    {
        if ($serviceId == 1) {
            return Blog::query()->where('type', '3')
                ->where('status', '1')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } else {
            return Blog::query()->whereRaw("FIND_IN_SET(?, category_id)", [$serviceId])
                ->where('type', '3')
                ->where('status', '1')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        }
    }

    protected function getLastContents($categoryId, $limit)
    {
        return Blog::whereRaw("FIND_IN_SET(?, category_id)", [$categoryId])
            ->where('status', '1')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    protected function getMostVisitedNews($limit, $newsID)
    {
        return Blog::query()->find($newsID);
    }

    protected function getLastVisualNews($limit)
    {
        return Blog::where('type', 3)
            ->where('status', '1')
            ->orderByDesc('id')
            ->paginate($limit);
    }

    protected function getTopVisualNews($serviceId, $limit)
    {
        return Blog::whereRaw("FIND_IN_SET(?, category_id)", [$serviceId])
            ->where('type', '2')
            ->where('status', '1')
            ->where('is_top', '1')
            ->orderBy('top_number')
            ->limit($limit)
            ->get();
    }

    protected function getTopVideoNews($serviceId, $limit, $newsId)
    {
        return Blog::query()->where('id', $newsId)
            ->whereRaw("FIND_IN_SET(?, category_id)", [$serviceId])
            ->where('type', '3')
            ->where('status', '1')
            ->limit($limit)
            ->get();
    }

    protected function getLatestNewsOfCategories($serviceId, $limit)
    {
        return Blog::query()->whereRaw("FIND_IN_SET(?, category_id)", [$serviceId])
            ->where('is_featured', '1')
            ->where('status', '1')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    protected function getDefaultNews($serviceId, $limit)
    {
        return Blog::query()->whereRaw("FIND_IN_SET(?, category_id)", [$serviceId])
            ->where('status', '1')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // Methods for podcast controller ****************
    public function getTopPodcasts($serviceId, $limit)
    {
        $topIds = CategoryIsTop::query()
            ->where('category_id', $serviceId)
            ->pluck('news_id')
            ->toArray();

        return Blog::query()
            ->whereIn('id', $topIds)
            ->limit($limit)
            ->paginate($limit);
    }

    public function getFeaturedPodcasts($serviceId, $limit)
    {
        $featuredIds = CategoryIsFeatured::query()
            ->where('category_id', $serviceId)
            ->pluck('news_id')
            ->toArray();

        return Blog::query()
            ->whereIn('id', $featuredIds)
            ->limit($limit)
            ->paginate($limit);
    }

    public function getLastPodcasts($serviceId, $locationId, $limit)
    {
        $podcasts = $this->getPodcastFilters($serviceId, $locationId, $limit);

        if ($podcasts->items() == []) {
            return [];
        } else
            return $podcasts; // Assuming getPodcastFilters returns a collection
    }

    public function getPodcastFilters($serviceId, $locationId, $limit = null)
    {
        $limit = $limit ?: 7;
        switch ($locationId) {
                // Top podcasts
            case 1:
                return Blog::query()
                    ->whereRaw("FIND_IN_SET($serviceId, category_id)")
                    ->where([
                        'type' => '4',
                        'status' => '1',
                        'is_top' => '1',
                    ])
                    ->orderBy('top_number', 'asc')
                    ->limit($limit)
                    ->paginate($limit);

                // Featured podcasts
            case 3:
                return Blog::query()
                    ->whereRaw("FIND_IN_SET($serviceId, category_id)")
                    ->where([
                        'type' => '4',
                        'is_featured' => $serviceId,
                    ])
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->paginate($limit);

                // Last podcasts
            case 8:
                return Blog::query()
                    ->where([
                        'type' => '4',
                        'status' => '1',
                    ])
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->paginate($limit);

            default:
                // Handle default case if needed
                return [];
        }
    }

    // Detail section
    public function getShowPodcastFilters($serviceId, $locationId, $limit)
    {
        switch ($locationId) {
            case 8:
                return Blog::query()->get();
//                    ->whereRaw("FIND_IN_SET($serviceId, category_id)")
//                    ->where('type', '4')
//                    ->orderBy('created_at', 'desc')
//                    ->limit($limit)
//                    ->paginate($limit);
            default:
                // Handle default case if needed
                return collect([]);
        }
    }

    // Methods for video controller ***********
    public function getTopVideos($serviceId, $limit)
    {
        $topIds = CategoryIsTop::getCategoryTopNews($serviceId);

        return Blog::query()
            ->whereIn('id', $topIds)
            ->orderByRaw(DB::raw("FIELD(id, " . implode(',', $topIds) . ")"))
            ->limit($limit)
            ->paginate($limit);
    }

    public function getFeaturedVideos($serviceId, $limit)
    {
        $featuredIds = CategoryIsFeatured::getCategoryFeatured($serviceId);

        return Blog::query()->whereIn('id', $featuredIds)->paginate($limit);
    }

    public function getLastVideos($serviceId, $locationId, $limit)
    {
        // Adjust the logic based on your requirements
        return $this->getVideoFilters($serviceId, $locationId, $limit);
    }

    public static function getVideoFilters($service_id, $location_id, $limit)
    {
        $limit = $limit ?: 7;
        $query = Blog::query();

        switch ($location_id) {
            case 1:
                $query->where([
                    'type' => '3',
                    'status' => '1',
                    'is_top' => '1',
                ])->orderBy('top_number', 'asc')->limit($limit);
                break;

            case 3:
                $query->orderBy('created_at', 'desc')->limit($limit);
                break;

            case 8:
                $query->where([
                    'type' => '3',
                    'status' => '1',
                ])->orderBy('created_at', 'desc')->limit($limit);
                break;

            default:
                // Handle default case if needed
                return collect([]);
        }

        return $query->paginate($limit);
    }

    // Detail section
    public function getShowVideoFilters($serviceId, $locationId, $limit)
    {
        switch ($locationId) {
            case 3:
                return Blog::query()
                    ->whereRaw("FIND_IN_SET($serviceId, category_id)")
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->paginate($limit);

            case 8:
                return Blog::query()
                    ->whereRaw("FIND_IN_SET($serviceId, category_id)")
                    ->where('type', '3')
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->paginate($limit);
        }
    }
}
