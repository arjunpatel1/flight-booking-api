<?php

namespace Modules\ActivityLog\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

trait HasActivityLog
{
    use LogsActivity;

    /**
     * Get the description for an activity log event.
     *
     * @param string $eventName
     * @return string
     */
    public function getDescriptionForEvent(string $eventName): string
    {
        return "admin::messages.resource_$eventName";
    }

    /**
     * Get the options for the model's activity log.
     *
     * @return LogOptions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    /**
     * Manipulate the activity log before it is saved.
     *
     * @param Activity $activity
     * @param string $eventName
     * @return void
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (auth()->check()) {
            $activity->causer_id = auth()->id();
            $activity->causer_type = get_class(auth()->user());
        }

        $activity->batch_uuid = Str::uuid();
        $activity->event = $eventName;
        $activity->log_name = $this->getLogName($eventName);
        $this->storeProperties($activity, request());
    }

    /**
     *  Get the log name for the model.
     *
     * @param string $eventName
     * @return string
     */
    public function getLogName(string $eventName): string
    {
        return str(class_basename(static::class))->plural()->snake()->lower() . "." . $eventName;
    }

    /**
     * Manipulate the property log before it is saved.
     *
     * @param Activity $activity
     * @param Request $request
     * @return void
     */
    private function storeProperties(Activity $activity, Request $request): void
    {
        $classNamespace = static::class;
        $moduleName = str(explode("\\", $classNamespace)[1]);
        $modelNameLowerCase = str(class_basename($classNamespace))->snake()->lower();

        $oldAttributes = $this->getOriginal();
        $newAttributes = $this->getAttributes();

        $oldAttributes = array_diff_key($oldAttributes, [
            'created_at' => 0,
            'updated_at' => 0,
            'deleted_at' => 0,
        ]);

        $newAttributes = array_diff_key($newAttributes, [
            'created_at' => 0,
            'updated_at' => 0,
            'deleted_at' => 0,
        ]);

        $activity->properties = [
            'old' => $oldAttributes,
            'attributes' => $newAttributes,
            "info" => [
                "ip" => $request->ip(),
                'http_method' => $request->method(),
                "user_agent" => $request->header('User-Agent'),
            ],
            "trans_params" => [
                "resource" => "{$moduleName->lower()}::{$modelNameLowerCase->plural()}.$modelNameLowerCase"
            ]
        ];
    }
}
