<?php

namespace Trinavo\TrinaCrud\Http\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\App;
use PhpParser\Node\Expr\Cast\String_;
use Trinavo\TrinaCrud\Enums\CrudAction;
use Trinavo\TrinaCrud\Contracts\AuthorizationServiceInterface;
use Trinavo\TrinaCrud\Contracts\ModelServiceInterface;
use Trinavo\TrinaCrud\Models\ModelSchema;

class PermissionsTab extends Component
{
    public $models = [];
    public $rules = [];
    public $users = [];
    public $roles = [];

    public $selectedModel = '';
    public $selectedActions = [];
    public $isRole = 1; // Default to role
    public $selectedUserId = '';
    public $filterByRoleId = ''; // New property for filtering
    public $filterByUserId = ''; // New property for filtering by user

    protected $validationRules = [
        'selectedModel' => 'required|string',
        'selectedActions' => 'required|array|min:1',
        'selectedUserId' => 'required',
    ];

    protected $messages = [
        'selectedModel.required' => 'Please select a model',
        'selectedActions.required' => 'Please select at least one action',
        'selectedActions.min' => 'Please select at least one action',
        'selectedUserId.required' => 'Please select a user or role',
    ];

    protected $listeners = ['refreshPermissions' => 'loadData'];

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->loadModels();
        $this->loadRules();
        $this->loadUsers();
        $this->loadRoles();
    }

    public function render()
    {
        $filteredRules = $this->rules;

        // Filter rules by role if a role is selected
        if (!empty($this->filterByRoleId)) {
            $authService = App::make(AuthorizationServiceInterface::class);
            $role = $authService->findRole($this->filterByRoleId);
            $roleName = $role ? $role->name : '';

            // Filter the rules to only show those for the selected role
            $filteredRules = collect($this->rules)->map(function ($actions, $model) use ($roleName) {
                $filteredActions = collect($actions)->filter(function ($details) use ($roleName) {
                    return in_array($roleName, $details['roles']);
                });

                return $filteredActions->count() > 0 ? $filteredActions->toArray() : null;
            })->filter()->toArray();
        }

        // Filter rules by user if a user is selected
        if (!empty($this->filterByUserId)) {
            $userModel = app(config('auth.providers.users.model'));
            $user = $userModel::find($this->filterByUserId);
            $userName = $user ? $user->name : '';

            // Filter the rules to only show those for the selected user
            $filteredRules = collect($this->rules)->map(function ($actions, $model) use ($userName) {
                $filteredActions = collect($actions)->filter(function ($details) use ($userName) {
                    return in_array($userName, $details['users']);
                });

                return $filteredActions->count() > 0 ? $filteredActions->toArray() : null;
            })->filter()->toArray();
        }

        return view('trina-crud::livewire.permissions-tab', [
            'filteredRules' => $filteredRules
        ]);
    }

    public function loadModels()
    {
        $modelService = App::make(ModelServiceInterface::class);
        $this->models = collect($modelService->getSchema())->map(
            fn(ModelSchema $model) =>
            $model->getModelName()
        );
    }

    /**
     * Load users from the authorization service
     */
    protected function loadUsers()
    {
        /**
         * @var AuthorizationServiceInterface $authService
         */
        $authService = App::make(AuthorizationServiceInterface::class);
        $this->users = $authService->getAllUsers();
    }

    public function loadRoles()
    {
        $authService = App::make(AuthorizationServiceInterface::class);
        $roles = $authService->getAllRoles();

        // Add permissions count to each role
        foreach ($roles as &$role) {
            $roleModel = $authService->findRole($role['id']);
            $role['permissionsCount'] = $roleModel ? count($roleModel->permissions) : 0;
        }

        $this->roles = $roles;
    }

    public function loadRules()
    {
        $authService = App::make(AuthorizationServiceInterface::class);
        $this->rules = $authService->getRules();
    }

    public function addPermission()
    {
        $this->validate($this->validationRules);

        /**
         * @var AuthorizationServiceInterface $authService
         */
        $authService = App::make(AuthorizationServiceInterface::class);

        foreach ($this->selectedActions as $actionName) {
            $action = CrudAction::from($actionName);

            if ($this->isRole) {
                $authService->addRule($this->selectedModel, $action, $this->selectedUserId, true);
            } else {
                $authService->addRule($this->selectedModel, $action, $this->selectedUserId, false);
            }
        }

        $this->reset(['selectedActions', 'selectedUserId']);
        $this->loadRules();
        $this->dispatch('permissionsChanged');
        session()->flash('message', 'Permissions added successfully!');
    }

    public function deletePermission($permissionName)
    {
        $authService = App::make(AuthorizationServiceInterface::class);
        $authService->deleteRule($permissionName);

        $this->loadRules();
        $this->dispatch('permissionsChanged');
        session()->flash('message', 'Permission deleted successfully!');
    }

    /**
     * Sync permissions for all models with the selected role
     */
    public function syncPermissions()
    {
        if (empty($this->selectedUserId) || $this->isRole != 1) {
            session()->flash('error', 'Please select a role to sync permissions');
            return;
        }

        $authService = App::make(AuthorizationServiceInterface::class);
        $crudActions = [
            'create',
            'read',
            'update',
            'delete'
        ];

        // For each model, create permissions for all actions
        foreach ($this->models as $model) {
            foreach ($crudActions as $actionName) {
                $action = CrudAction::from($actionName);
                // Only add if the permission doesn't already exist
                $authService->addRule($model, $action, $this->selectedUserId, true);
            }
        }

        $this->loadRules();
        $this->dispatch('permissionsChanged');
        session()->flash('message', 'Permissions synced successfully for all models!');
    }

    /**
     * Reset filters
     */
    public function resetFilters()
    {
        $this->reset(['filterByRoleId', 'filterByUserId']);
    }

    /**
     * Toggle permission for a model and action
     */
    public function togglePermission($model, string $action, $entityId, $isRole)
    {
        /** @var AuthorizationServiceInterface $authService */
        $authService = App::make(AuthorizationServiceInterface::class);
        $crudAction = CrudAction::from($action);

        if ($isRole) {
            // Check if the role exists
            $role = $authService->findRole($entityId);
            if (!$role) {
                session()->flash('error', 'Role not found');
                return;
            }

            // Check if permission exists and toggle it
            if ($role->hasPermissionTo($crudAction->toModelPermissionString($model))) {
                $authService->setModelRolePermission($model, $crudAction, $entityId, false);
                session()->flash('message', "Permission '$action' removed from role for " . class_basename($model));
            } else {
                $authService->setModelRolePermission($model, $crudAction, $entityId, true);
                session()->flash('message', "Permission '$action' added to role for " . class_basename($model));
            }
        } else {
            // Get user through the auth service
            $user = $authService->getUser($entityId);
            if (!$user) {
                session()->flash('error', 'User not found');
                return;
            }

            // Check if permission exists and toggle it
            if ($user->hasPermissionTo($crudAction->toModelPermissionString($model))) {
                $authService->setModelUserPermission($model, $crudAction, $entityId, false);
                session()->flash('message', "Permission '$action' removed from user for " . class_basename($model));
            } else {
                $authService->setModelUserPermission($model, $crudAction, $entityId, true);
                session()->flash('message', "Permission '$action' added to user for " . class_basename($model));
            }
        }

        $this->loadRules();
        $this->dispatch('permissionsChanged');
    }
}
