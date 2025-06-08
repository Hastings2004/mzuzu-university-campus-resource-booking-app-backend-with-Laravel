<?php

namespace App\Observers;

use App\Services\BinarySearchService;
use Illuminate\Database\Eloquent\Model;

class SearchCacheObserver
{
    //
    protected $searchService;

    public function __construct(BinarySearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function created(Model $model)
    {
        $this->clearCacheForModel($model);
    }

    public function updated(Model $model)
    {
        $this->clearCacheForModel($model);
    }

    public function deleted(Model $model)
    {
        $this->clearCacheForModel($model);
    }

    protected function clearCacheForModel(Model $model)
    {
        $type = $this->getTypeFromModel($model);
        if ($type) {
            $this->searchService->clearCache($type);
        }
    }

    protected function getTypeFromModel(Model $model)
    {
        $className = class_basename($model);
        
        switch ($className) {
            case 'Resource':
                return 'resources';
            case 'Booking':
                return 'bookings';
            case 'User':
                return 'users';
            default:
                return null;
        }
    }
}
