<?php

namespace Trinavo\TrinaCrud\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Trinavo\TrinaCrud\Contracts\AuthorizationServiceInterface;
use Trinavo\TrinaCrud\Contracts\ModelServiceInterface;
use Trinavo\TrinaCrud\Contracts\OwnershipServiceInterface;
use Trinavo\TrinaCrud\Enums\CrudAction;
use Trinavo\TrinaCrud\Models\ModelSchema;
use Trinavo\TrinaCrud\Traits\HasCrud;

class ModelService implements ModelServiceInterface
{
    protected AuthorizationServiceInterface $authorizationService;
    protected OwnershipServiceInterface $ownershipService;

    public function __construct(
        AuthorizationServiceInterface $authorizationService,
        OwnershipServiceInterface $ownershipService
    ) {
        $this->authorizationService = $authorizationService;
        $this->ownershipService = $ownershipService;
    }

    /**
     * Get a paginated list of model records with filtering and authorization
     *
     * @param string $modelName The name of the model
     * @param array $attributes The attributes to select
     * @param array|null $with The relations to load
     * @param array $relationAttributes The attributes to select for each relation
     * @param array $filters The filters to apply
     * @param int $perPage The number of records per page
     * @return LengthAwarePaginator
     * @throws NotFoundHttpException
     */
    public function all(
        string $modelName,
        array $attributes = [],
        ?array $with = null,
        array $relationAttributes = [],
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {

        if (!$this->authorizationService->authHasModelPermission($modelName, CrudAction::READ)) {
            throw new NotFoundHttpException('You are not authorized to read this model');
        }

        // Find the model
        $model = $this->getModel($modelName);

        if (!$model) {
            throw new NotFoundHttpException('Model not found');
        }

        // Create a new query
        $query = $model->query();

        // Apply ownership filtering
        $query = $this->scopeAuthorizedRecords($query, $model, CrudAction::READ);

        // Filter attributes based on permissions

        $selectableAttributes = $model->getCrudFillable(CrudAction::READ);

        if (!empty($attributes)) {
            $authorizedAttributes  = array_intersect($selectableAttributes, $attributes);
        } else {
            $authorizedAttributes = $selectableAttributes;
        }

        if (empty($authorizedAttributes)) {
            $query->where('1=-1');
            return $query->paginate($perPage);
        }

        // Select only authorized attributes if specified
        $query->select($authorizedAttributes);

        // Apply filters
        $query = $this->applyAuthorizedFilters($query, $model, $filters, CrudAction::READ);

        // Load relations if specified
        if (!empty($with)) {
            $query = $this->loadAuthorizedRelations($query, $model, $with, $relationAttributes, CrudAction::READ);
        }

        // Paginate the results
        return $query->paginate($perPage);
    }

    /**
     * Get a single model record by ID with authorization
     *
     * @param string $modelName The name of the model
     * @param int $id The ID of the record
     * @param array $attributes The attributes to select
     * @param array|null $with The relations to load
     * @param array $relationAttributes The attributes to select for each relation
     * @return Model|null
     * @throws NotFoundHttpException
     */
    public function find(
        string $modelName,
        int $id,
        array $attributes = [],
        ?array $with = null,
        array $relationAttributes = []
    ): ?Model {

        if (!$this->authorizationService->authHasModelPermission($modelName, CrudAction::READ)) {
            throw new NotFoundHttpException('You are not authorized to read this model');
        }

        // Find the model
        $model = $this->getModel($modelName);

        if (!$model) {
            throw new NotFoundHttpException('Model not found');
        }

        // Create a new query
        $query = $model->query();

        // Apply ownership filtering
        $query = $this->scopeAuthorizedRecords($query, $model, CrudAction::READ);

        // Filter attributes based on permissions
        $selectableAttributes = $model->getCrudFillable(CrudAction::READ);

        if (!empty($attributes)) {
            $authorizedAttributes  = array_intersect($selectableAttributes, $attributes);
        } else {
            $authorizedAttributes = $selectableAttributes;
        }

        if (empty($authorizedAttributes)) {
            $query->where('1=-1');
            return $query->first();
        }

        // Load relations if specified
        if (!empty($with)) {
            $query = $this->loadAuthorizedRelations($query, $model, $with, $relationAttributes, CrudAction::READ);
        }

        // Find the record
        $record = $query->find($id);

        if (!$record) {
            throw new NotFoundHttpException('Record not found');
        }

        return $record;
    }

    /**
     * Create a new model record with authorization
     *
     * @param string $modelName The name of the model
     * @param array $data The data to create the record with
     * @return Model
     * @throws NotFoundHttpException
     */
    public function create(string $modelName, array $data): Model
    {

        if (!$this->authorizationService->authHasModelPermission($modelName, CrudAction::CREATE)) {
            throw new NotFoundHttpException('You are not authorized to create this model');
        }


        // Find the model
        $model = $this->getModel($modelName);

        if (!$model) {
            throw new NotFoundHttpException('Model not found');
        }

        // Validate the data using the model's validation rules
        $rules = $model->getCrudRules(CrudAction::CREATE);
        if (!empty($rules)) {
            $validator = \Illuminate\Support\Facades\Validator::make($data, $rules);
            if ($validator->fails()) {
                throw new \Illuminate\Validation\ValidationException($validator);
            }
        }

        $createableAttributes = $model->getCrudFillable(CrudAction::CREATE);

        $data = array_intersect_key($data, array_flip($createableAttributes));

        // Create a new record
        $record = $model->create($data);

        return $record;
    }

    /**
     * Update a model record with authorization
     *
     * @param string $modelName The name of the model
     * @param int $id The ID of the record
     * @param array $data The data to update the record with
     * @return Model
     * @throws NotFoundHttpException
     */
    public function update(string $modelName, int $id, array $data): Model
    {

        if (!$this->authorizationService->authHasModelPermission($modelName, CrudAction::UPDATE)) {
            throw new NotFoundHttpException('You are not authorized to update this model');
        }


        // Find the model
        $model = $this->getModel($modelName);

        if (!$model) {
            throw new NotFoundHttpException('Model not found');
        }

        // Create a new query
        $query = $model->query();

        // Apply ownership filtering
        $query = $this->scopeAuthorizedRecords($query, $model, CrudAction::UPDATE);

        // Find the record
        $record = $query->find($id);

        if (!$record) {
            throw new NotFoundHttpException('Record not found');
        }

        // Validate the data using the model's validation rules
        $rules = $model->getCrudRules(CrudAction::UPDATE);
        if (!empty($rules)) {
            $validator = \Illuminate\Support\Facades\Validator::make($data, $rules);
            if ($validator->fails()) {
                throw new \Illuminate\Validation\ValidationException($validator);
            }
        }

        // Update the record
        $record->update($data);

        return $record;
    }

    /**
     * Delete a model record with authorization
     *
     * @param string $modelName The name of the model
     * @param int $id The ID of the record
     * @return bool
     * @throws NotFoundHttpException
     */
    public function delete(string $modelName, int $id): bool
    {
        if (!$this->authorizationService->authHasModelPermission($modelName, CrudAction::DELETE)) {
            throw new NotFoundHttpException('You are not authorized to delete this model');
        }

        // Find the model
        $model = $this->getModel($modelName);

        if (!$model) {
            throw new NotFoundHttpException('Model not found');
        }

        // Create a new query
        $query = $model->query();

        // Apply ownership filtering
        $query = $this->scopeAuthorizedRecords($query, $model, CrudAction::DELETE);

        // Find the record
        $record = $query->find($id);

        if (!$record) {
            throw new NotFoundHttpException('Record not found');
        }

        // Delete the record
        return $record->delete();
    }

    /**
     * Get the authorized attributes for a model
     *
     * @param string|Model $model The model
     * @param CrudAction $action The action (view, update)
     * @return array
     */
    public function getAuthorizedAttributes(string|Model $model, CrudAction $action): array
    {
        if (is_string($model)) {
            $model = $this->getModel($model);
        }

        return $model->getCrudFillable($action);
    }

    /**
     * Filter a query to only include records the user has access to
     *
     * @param Builder $query The query builder
     * @param string $modelName The name of the model
     * @param CrudAction $action The action (view, update)
     * @return Builder
     */
    public function scopeAuthorizedRecords(Builder|Relation $query, string|Model $model, CrudAction $action): Builder|Relation
    {
        if (is_string($model)) {
            $model = $this->getModel($model);
        }

        return $this->ownershipService->addOwnershipQuery(
            $query,
            $model,
            $action->value
        );
    }


    /**
     * Process 'with' relationships and ensure proper authorization
     *
     * @param Builder $query The query builder
     * @param string $modelName The name of the model
     * @param array $relations The relations to load
     * @param array $attributesByRelation Optional attributes to select for each relation
     * @param CrudAction $action The action (view, update)
     * @return Builder|Relation
     */
    public function loadAuthorizedRelations(
        Builder|Relation $query,
        string|Model $model,
        array $relations,
        array $attributesByRelation = [],
        CrudAction $action = CrudAction::READ,
    ): Builder|Relation {
        if (is_string($model)) {
            $model = $this->getModel($model);
        }

        foreach ($relations as $relation) {

            // Get the related model name
            $relatedModel = $this->getRelatedModel($model, $relation);

            // Check if user has permission to view the related model
            if (!$this->authorizationService->authHasModelPermission($relatedModel, $action)) {
                continue;
            }

            // Get authorized attributes for the relation
            $attributes = $attributesByRelation[$relation] ?? [];
            $selectableAttributes = $relatedModel->getCrudFillable($action);
            $authorizedAttributes  = array_intersect($selectableAttributes, $attributes);

            // Load relation with ownership scope and column restrictions
            $query->with([$relation => function ($q) use ($relatedModel, $authorizedAttributes, $action) {
                // Apply ownership filter
                $this->scopeAuthorizedRecords($q, $relatedModel, $action);

                // Select only authorized attributes if specified
                if (!empty($authorizedAttributes)) {
                    // Always include the primary key
                    $authorizedAttributes[] = 'id';
                    $q->select(array_unique($authorizedAttributes));
                }
            }]);
        }

        return $query;
    }

    /**
     * Apply filters to a query with permission checks
     *
     * @param Builder|Relation $query The query builder
     * @param string|Model $model The model
     * @param array $filters The filters to apply
     * @param CrudAction $action The action (view, update)
     * @return Builder|Relation
     */
    public function applyAuthorizedFilters(
        Builder|Relation $query,
        string|Model $model,
        array $filters,
        CrudAction $action
    ): Builder|Relation {
        if (empty($filters)) {
            return $query;
        }

        $modelInstance = is_string($model) ? $this->getModel($model) : $model;
        if (!$modelInstance) {
            return $query;
        }

        $fillables = $modelInstance->getCrudFillable($action);

        foreach ($filters as $attribute => $value) {
            // Check if this is a relationship filter (contains a dot)
            if (str_contains($attribute, '.')) {
                list($relation, $relationAttribute) = explode('.', $attribute, 2);

                // Check if the relation exists on the model
                if (!method_exists($modelInstance, $relation)) {
                    continue;
                }

                // Handle relationship filtering
                if (is_array($value) && isset($value['operator'])) {
                    $operator = $value['operator'];
                    $filterValue = $value['value'] ?? null;

                    $query->whereHas($relation, function ($subQuery) use ($relationAttribute, $operator, $filterValue) {
                        if ($operator === 'like') {
                            $subQuery->where($relationAttribute, 'like', "%{$filterValue}%");
                        } else {
                            $this->applyOperatorFilter($subQuery, $relationAttribute, [
                                'operator' => $operator,
                                'value' => $filterValue
                            ]);
                        }
                    });
                } else {
                    // Simple equality filter on relationship
                    $query->whereHas($relation, function ($subQuery) use ($relationAttribute, $value) {
                        $subQuery->where($relationAttribute, $value);
                    });
                }

                continue;
            }

            // Regular attribute filtering (non-relationship)
            if (!in_array($attribute, $fillables)) {
                continue;
            }

            // Handle different filter types
            if (is_array($value)) {
                // Check for special operators
                if (isset($value['operator'])) {
                    $this->applyOperatorFilter($query, $attribute, $value);
                } else {
                    // Default to "in" operator for arrays
                    $query->whereIn($attribute, $value);
                }
            } else {
                // Simple equality filter
                $query->where($attribute, $value);
            }
        }

        return $query;
    }

    /**
     * Apply a filter with a specific operator
     *
     * @param Builder $query The query builder
     * @param string $attribute The column to filter
     * @param array $filter The filter configuration
     * @return Builder
     */
    protected function applyOperatorFilter(Builder $query, string $attribute, array $filter): Builder
    {
        $operator = $filter['operator'] ?? '=';
        $value = $filter['value'] ?? null;

        switch ($operator) {
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($attribute, $value);
                }
                break;
            case 'not_in':
                if (is_array($value)) {
                    $query->whereNotIn($attribute, $value);
                }
                break;
            case 'like':
                $query->where($attribute, 'like', "%{$value}%");
                break;
            case 'not':
            case '!=':
                $query->where($attribute, '!=', $value);
                break;
            case '>':
            case '<':
            case '>=':
            case '<=':
                $query->where($attribute, $operator, $value);
                break;
            default:
                $query->where($attribute, $operator, $value);
        }

        return $query;
    }

    public function verifyModel(string $modelClass): bool
    {
        // First, sanitize the model class name to prevent malicious input
        if (preg_match('/[^a-zA-Z0-9_\\\\.]/', $modelClass)) {
            return false;
        }

        $modelClass = str_replace('.', '\\', $modelClass);

        // Check against whitelist of allowed model namespaces
        $allowedNamespaces = config('trina-crud.allowed_model_namespaces', []);
        $isAllowed = false;

        foreach ($allowedNamespaces as $namespace) {
            if (strpos($modelClass, $namespace) === 0) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return false;
        }

        // Check if the class exists
        if (!class_exists($modelClass)) {
            return false;
        }

        // Check if the model has the HasCrud trait before creating it
        if (!in_array(HasCrud::class, class_uses_recursive($modelClass))) {
            return false;
        }

        // Check if modelClass is instance of Model without creating it
        if (!is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        return true;
    }

    public function getModel(string|Model $model): Model|HasCrud|null
    {
        $modelClass = is_string($model) ? $model : get_class($model);

        $modelClass = str_replace('.', '\\', $modelClass);


        if (!$this->verifyModel($modelClass)) {
            return null;
        }

        try {
            if (is_string($model)) {
                $model = app($modelClass);
            }
            return $model;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getRelatedModel(string|Model $model, string $relation): ?Model
    {
        $model = $this->getModel($model);
        if (!$model) {
            return null;
        }
        $relatedModelName = $model->$relation()->getQuery()->getModel()->getMorphClass();
        return $this->getModel($relatedModelName);
    }


    /**
     * Get the schema of all models
     *
     * @param string|null $modelName
     * @return ModelSchema[]
     */
    public function getSchema(?string $modelName = null, bool $authorizedOnly = false): array
    {
        // Sanitize the model name to prevent injection attacks
        if ($modelName !== null) {
            // Strip any path traversal patterns or special characters
            if (preg_match('/[\/\\\\\.]{2,}|[^a-zA-Z0-9_\.]/', $modelName)) {
                return [];
            }
        }

        //scan all model paths from config model_paths
        $models = [];
        foreach (config('trina-crud.model_paths') as $path) {
            $namespace = null;

            if ($modelName) {
                // Get only the class name portion to prevent directory traversal
                $modelName = explode('.', $modelName);
                $className = end($modelName);

                // Further sanitize the class name
                $className = preg_replace('/[^a-zA-Z0-9_]/', '', $className);

                // Only proceed if we have a valid class name
                if (empty($className)) {
                    continue;
                }

                // Use realpath to resolve any ../ or other potential path traversal
                $fullPath = realpath($path) . DIRECTORY_SEPARATOR . $className . '.php';

                // Check the resolved path is still within the allowed path
                if (!$fullPath || strpos($fullPath, realpath($path)) !== 0) {
                    continue;
                }

                $files = file_exists($fullPath) ? [$fullPath] : [];
            } else {
                // Ensure the path is safe
                $safePath = realpath($path);
                if (!$safePath) {
                    continue;
                }

                $files = glob($safePath . DIRECTORY_SEPARATOR . '*.php');
            }

            foreach ($files as $file) {
                try {
                    $modelSchema = $this->parseModelFile($file, $namespace);
                    if (!$modelSchema) {
                        continue;
                    }

                    if ($authorizedOnly) {
                        if (
                            !(
                                $this->authorizationService->authHasModelPermission($modelSchema->getModelName(), CrudAction::READ)
                                ||
                                $this->authorizationService->authHasModelPermission($modelSchema->getModelName(), CrudAction::CREATE)
                                ||
                                $this->authorizationService->authHasModelPermission($modelSchema->getModelName(), CrudAction::UPDATE)
                                ||
                                $this->authorizationService->authHasModelPermission($modelSchema->getModelName(), CrudAction::DELETE)
                            )
                        ) {
                            continue;
                        }
                    }

                    $models[] = $modelSchema;
                } catch (\Throwable $e) {
                    // Skip files that cause errors
                    continue;
                }
            }
        }
        return $models;
    }


    /**
     * Parse a model file to extract model information
     *
     * @param string $file The path to the model file
     * @param string|null $namespace The namespace of the model
     * @return ModelSchema|null
     */
    public function parseModelFile(string $file, ?string $namespace = null): ?ModelSchema
    {
        // Validate the file path
        if (!file_exists($file) || !is_file($file)) {
            return null;
        }

        // Ensure the file is within allowed directory paths
        $isAllowedPath = false;
        foreach (config('trina-crud.model_paths') as $path) {
            $safePath = realpath($path);
            if ($safePath && strpos(realpath($file), $safePath) === 0) {
                $isAllowedPath = true;
                break;
            }
        }

        if (!$isAllowedPath) {
            return null;
        }

        //get the class name from the file name
        $className = basename($file, '.php');

        if (!$namespace) {
            try {
                // Limit reading only first few lines to find namespace
                $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES, null);
                if ($lines === false) {
                    return null;
                }

                // Only read up to the first 20 lines to find namespace
                $lines = array_slice($lines, 0, 20);

                foreach ($lines as $line) {
                    if (preg_match('/namespace\s+([\\a-zA-Z0-9_]+);/', $line, $matches)) {
                        $namespace = $matches[1];
                        break;
                    }
                }

                if (!$namespace) {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        $fullClassName = $namespace . '\\' . $className;

        if (!$this->verifyModel($fullClassName)) {
            return null;
        }

        $model = $this->getModel($fullClassName);
        if (!$model) {
            return null;
        }

        try {
            $fields = Schema::getColumnListing($model->getTable());
            $fullClassName = str_replace('\\', '.', $fullClassName);

            return new ModelSchema($fullClassName, $fields);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
