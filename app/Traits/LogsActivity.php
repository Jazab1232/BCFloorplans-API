<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

trait LogsActivity
{
    protected static array $oldDataCache = [];

    protected static function bootLogsActivity()
    {
        // CREATION - Log and notify
        static::created(function ($model) {
            // Log to activity log
            static::logActivity('create', $model);

            // Send creation notification
            DB::afterCommit(function () use ($model) {
                $freshModel = static::with(static::relatedModels())->find($model->getKey());
                if ($freshModel) {
                    \App\Services\NotificationService::notifyModelChange(
                        $freshModel,
                        'create',
                        [] // No diff for creation
                    );
                }
            });
        });

        // UPDATING - Capture OLD state BEFORE changes
        static::updating(function ($model) {
            // Get ORIGINAL state from database
            $original = static::with(static::relatedModels())->find($model->getKey());

            if ($original) {
                static::$oldDataCache[$model->getKey()] = static::serializeModelData($original);

                Log::info('Captured OLD state', [
                    'model' => class_basename($model),
                    'model_id' => $model->getKey(),
                    'services_count' => count($original->services ?? []),
                    'slots_count' => count($original->slots ?? []),
                ]);
            }
        });

        // UPDATED - Compare and notify AFTER update completes
        static::updated(function ($model) {
            $callback = function () use ($model) {
                $oldData = static::$oldDataCache[$model->getKey()] ?? [];

                if (empty($oldData)) {
                    Log::warning('No old data found for model', [
                        'model' => class_basename($model),
                        'model_id' => $model->getKey()
                    ]);
                    return;
                }

                // Get FRESH state with all relationships
                $freshModel = static::with(static::relatedModels())->find($model->getKey());

                if (!$freshModel) {
                    Log::warning('Could not find fresh model', [
                        'model' => class_basename($model),
                        'model_id' => $model->getKey()
                    ]);
                    return;
                }

                $newData = static::serializeModelData($freshModel);

                Log::info('Comparing states for UPDATE', [
                    'model' => class_basename($model),
                    'model_id' => $model->getKey(),
                    'old_services_count' => count($oldData['services'] ?? []),
                    'new_services_count' => count($newData['services'] ?? []),
                    'old_slots_count' => count($oldData['slots'] ?? []),
                    'new_slots_count' => count($newData['slots'] ?? []),
                    'old_amount' => $oldData['amount'] ?? null,
                    'new_amount' => $newData['amount'] ?? null,
                ]);

                // Calculate diff
                $diff = static::calculateDiff($oldData, $newData);

                if (!empty($diff)) {
                    Log::info('Changes detected in UPDATE', [
                        'model' => class_basename($model),
                        'diff_keys' => array_keys($diff)
                    ]);

                    // Log activity
                    static::logActivity('update', $freshModel, [
                        'before' => $oldData,
                        'after' => $newData,
                        'diff' => $diff,
                    ]);

                    // Send UPDATE notifications
                    // \App\Services\NotificationService::notifyModelChange(
                    //     $freshModel,
                    //     'update',
                    //     $diff
                    // );
                } else {
                    Log::info('No changes detected in UPDATE', [
                        'model' => class_basename($model),
                        'model_id' => $model->getKey()
                    ]);
                }

                // Cleanup
                unset(static::$oldDataCache[$model->getKey()]);
            };

            // Wait for transaction to commit
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($callback);
            } else {
                $callback();
            }
        });

        // DELETION - Log and notify
        static::deleted(function ($model) {
            $model->load(static::relatedModels());

            // Log activity
            static::logActivity('delete', $model, [
                'before' => static::serializeModelData($model),
            ]);

            // Send deletion notification
            // \App\Services\NotificationService::notifyModelChange(
            //     $model,
            //     'delete',
            //     []
            // );
        });
    }

    /**
     * Serialize model data properly for comparison
     * Indexes collections by UUID for accurate diff detection
     */
    protected static function serializeModelData($model): array
    {
        $data = $model->toArray();

        // Index relationship collections by UUID for accurate comparison
        foreach (static::relatedModels() as $relation) {
            if (isset($data[$relation]) && is_array($data[$relation])) {
                if (static::isSequentialArray($data[$relation])) {
                    $indexed = [];
                    foreach ($data[$relation] as $item) {
                        // Use UUID as key for reliable tracking
                        $key = $item['uuid'] ?? $item['id'] ?? null;
                        if ($key) {
                            $indexed[$key] = $item;
                        }
                    }
                    $data[$relation] = $indexed;
                }
            }
        }

        return $data;
    }

    /**
     * Check if array is sequential (not associative)
     */
    protected static function isSequentialArray(array $arr): bool
    {
        if (empty($arr))
            return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Log activity to database
     */
    protected static function logActivity($action, $model, array $data = [])
    {
        $user = Auth::user();

        ActivityLog::create([
            'action' => $action,
            'model_type' => get_class($model),
            'model_id' => $model->uuid,
            'data' => $data ?: ['snapshot' => static::serializeModelData($model)],
            'ip_address' => Request::ip(),
            'user_agent' => Request::header('User-Agent'),
            'causer_type' => $user ? get_class($user) : null,
            'causer_uuid' => $user ? $user->uuid : null,
        ]);
    }

    /**
     * Get relationships to track
     */
    protected static function relatedModels(): array
    {
        return property_exists(static::class, 'logRelations') ? static::$logRelations : [];
    }

    /**
     * Calculate diff between old and new data
     * Properly handles nested arrays and collections
     */
    protected static function calculateDiff(array $old, array $new): array
    {
        $diff = [];

        // Get all keys from both arrays
        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            // Skip if identical
            if ($oldValue === $newValue) {
                continue;
            }

            // Handle collections (services, slots, etc.)
            if (is_array($oldValue) && is_array($newValue)) {
                // Check if these are indexed collections (keyed by UUID)
                if (static::isAssociativeArray($oldValue) && static::isAssociativeArray($newValue)) {
                    $collectionDiff = static::compareCollections($oldValue, $newValue);

                    if (!empty($collectionDiff)) {
                        $diff[$key] = $collectionDiff;
                    }
                } else {
                    // Regular nested arrays - recursive diff
                    $nestedDiff = static::calculateDiff($oldValue, $newValue);
                    if (!empty($nestedDiff)) {
                        $diff[$key] = $nestedDiff;
                    }
                }
            } else {
                // Simple value change
                $diff[$key] = [
                    'before' => $oldValue,
                    'after' => $newValue,
                ];
            }
        }

        return $diff;
    }

    /**
     * Compare collections indexed by UUID
     * Returns changes with before/after for each item
     */
    protected static function compareCollections(array $oldCollection, array $newCollection): array
    {
        $changes = [];

        // Get all unique UUIDs
        $allUuids = array_unique(array_merge(
            array_keys($oldCollection),
            array_keys($newCollection)
        ));

        foreach ($allUuids as $uuid) {
            $oldItem = $oldCollection[$uuid] ?? null;
            $newItem = $newCollection[$uuid] ?? null;

            // Item was ADDED
            if ($oldItem === null && $newItem !== null) {
                $changes[$uuid] = [
                    'before' => null,
                    'after' => $newItem,
                ];
            }
            // Item was REMOVED
            elseif ($oldItem !== null && $newItem === null) {
                $changes[$uuid] = [
                    'before' => $oldItem,
                    'after' => null,
                ];
            }
            // Item was MODIFIED
            elseif ($oldItem !== $newItem) {
                if (is_array($oldItem) && is_array($newItem)) {
                    $itemDiff = static::calculateDiff($oldItem, $newItem);
                    if (!empty($itemDiff)) {
                        $changes[$uuid] = [
                            'before' => $oldItem,
                            'after' => $newItem,
                            'changed_fields' => array_keys($itemDiff),
                        ];
                    }
                } else {
                    $changes[$uuid] = [
                        'before' => $oldItem,
                        'after' => $newItem,
                    ];
                }
            }
        }

        return $changes;
    }

    /**
     * Check if array is associative
     */
    protected static function isAssociativeArray(array $arr): bool
    {
        if (empty($arr))
            return false;

        $keys = array_keys($arr);

        // String keys = associative
        if (is_string($keys[0])) {
            return true;
        }

        // Non-sequential numeric keys = associative
        return $keys !== range(0, count($arr) - 1);
    }
}