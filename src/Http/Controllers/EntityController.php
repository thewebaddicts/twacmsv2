<?php

namespace twa\cmsv2\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use twa\cmsv2\Models\CmsPermissions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Process;
use Illuminate\Database\Schema\Blueprint;
use twa\cmsv2\Jobs\EntityImportFileJob;
use twa\uikit\Classes\ColumnOperationTypes\BelongsTo;

use twa\uikit\Classes\ColumnOperationTypes\ManyToMany;

class EntityController extends Controller
{
    public function render($slug)
    {
        if (!cms_check_permission("show-" . $slug)) {
            abort(404);
        }


        $entity = get_entity($slug);



        $table =  (new \twa\uikit\Classes\Table\TableData($entity->entity, $entity->tableName));



        foreach ($entity->columns() as $column) {



            if (isset($column['label']) && isset($column['name']) && isset($column['type'])) {
                $label = $column['label'];
                $name = $column['name'];


                $column_type = $column['type'];
                $field = $column;
                $typeInstance = new $column_type($field);

                $type = $typeInstance->columnType();
                $operationType = $typeInstance->operationType();
                $instance = new $operationType(null, null, null);

                if ($instance instanceof ManyToMany) {
                    $table = $table->manyToMany($field['options']['table'], $field['name'], $field['options']['field'], $column['name'], []);
                }
                if ($instance instanceof BelongsTo) {
                    $table = $table->belongsTo($field['options']['table'], $field['name'],  true)
                        ->addColumn($label, $name,  $type, \twa\uikit\Classes\ColumnOperationTypes\DefaultOperationType::class, [$field['options']['table'] . '.' . $field['options']['field']]);
                    continue;
                }

                if ($column['filterable']) {

                    $filterType = $typeInstance->filterType();

                    $attributes = [];

                    if ($column['options']['table'] ?? null) {
                        $attributes['table'] =  $column['options']['table'];
                        $attributes['foreign_key'] = $column['name'];
                        $attributes['column'] = $column['options']['field'];
                    }

                    $table = $table->addFilter($label, $name, $name, $filterType, $attributes);
                }



                $table = $table->addColumn(
                    $label,
                    $name,
                    $type,
                    $operationType,
                    $name
                );
            }
        }


        foreach ($entity->row_operations as $row_operation) {

            $table->addRowOperation(
                ...$row_operation
            );
        }


        foreach ($entity->table_operations as $table_operation) {

            $table->addTableOperation(
                ...$table_operation
            );
        }


        if ($entity->enableSorting) {
            $table->addTableOperation(
                'Sorting',
                route('cms-entity.sorting', ['slug' => $entity->slug]),
                ''
            );
        }





        $conditions = update_conditions($entity->conditions);

        foreach ($conditions as $condition) {
            $table->addCondition($condition['type'], $condition['column'], $condition['value'], $condition['operand']);
        }


        if (!cms_check_permission("delete-" . $slug)) {
            $table->disableDelete();
        }

        // dd($entity->filters());
        // dd($conditions);

        // $table->addTableOperation(
        //     'Add New Record',
        //     route('entity.create', ['slug' => $slug]),
        //     '<i class="fa-solid fa-plus"></i>'
        // );

        // $edit_route = "/".Route::getRoutes()->getByName('entity.update')->uri();




        //    dd( route('entity.update', ['slug' => $slug , 'id' => '[id]']));

        $path = $entity->render ? $entity->render : 'CMSView::pages.entity.index';




        $permissions = $this->generatePermissions();

        // dd($permissions);


        return view($path, ['table' => $table->get()]);
    }

    public function create($slug)
    {
        $entity = get_entity($slug);

        $path = $entity->form ? $entity->form : 'CMSView::pages.form.index';

        return view($path, ['slug' => $slug, 'id' => null]);
    }

    public function update($slug, $id)
    {
        $entity = get_entity($slug);
        $path = $entity->form ? $entity->form : 'CMSView::pages.form.index';

        return view($path, ['slug' => $slug, 'id' => $id]);
    }

    public function migrate()
    {

        $databaseName = env('DB_DATABASE');

        Process::run('php artisan migrate');



        foreach (config('entity-mapping') as $className) {


            $entity = new $className;

            $entity_fields =  $entity->fields();

            if (!Schema::hasTable($entity->tableName)) {

                Schema::create($entity->tableName, function (Blueprint $table) use ($entity, $entity_fields) {
                    $table->id();
                    $table->timestamps();
                    $table->softDeletes();
                    $table->longText('attributes')->nullable();
                });
            }

            // if (Schema::hasTable($entity->tableName) && !Schema::hasColumn($entity->tableName, 'attributes')) {
            //     $table->longText('attributes')->nullable();
            // }
            if (Schema::hasTable($entity->tableName) && !Schema::hasColumn($entity->tableName, 'attributes')) {
                Schema::table($entity->tableName, function (Blueprint $table) {
                    $table->longText('attributes')->nullable();
                });
            }

            Schema::table($entity->tableName, function (Blueprint $table) use ($entity, $entity_fields) {

                foreach ($entity_fields as $entity_field) {
                    $field = [...$entity_field];

                    if (isset($field['translatable']) && $field['translatable']) {
                        foreach (config('languages') as $language) {

                            $field['name'] = $entity_field['name'] . '_' . $language['prefix'];
                            $field['name'] = trim($field['name']);

                            if (Schema::hasColumn($entity->tableName, $field['name'])) {
                                continue;
                            }

                            (new $field['type']($field))->db($table);
                        }
                    } else {

                        $entity_field['name'] = trim($entity_field['name']);

                        if (Schema::hasColumn($entity->tableName, $entity_field['name'])) {
                            continue;
                        }

                        (new $entity_field['type']($entity_field))->db($table);
                    }
                }

                if ($entity->enableSorting && !Schema::hasColumn($entity->tableName, 'orders')) {
                    $table->bigInteger('orders')->default(0);
                }
            });


            if (property_exists($entity, 'seeder')) {
                (new $entity->seeder)->run();
            }
        }
    }

    public function generatePermissions()
    {
        $menu = config('menu');
        $permissions = [];
        $existing = DB::table('cms_permissions')->pluck('key')->toArray();


        $processPermissions = function ($menuKey, $newPermissions, $type) use (&$permissions, &$existing) {
            foreach ($newPermissions as $permission) {
                $key = $permission['key'] ?? null;
                $label = $permission['label'] ?? null;

                if ($key && !in_array($key, $existing)) {
                    $permissions[] = [
                        'key' => $key,
                        'label' => $label,
                        'type' => $type,
                        'menu_key' => $menuKey,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $existing[] = $key;
                }
            }
        };


        $processMenu = function ($items) use (&$processMenu, &$processPermissions) {
            foreach ($items as $item) {
                $menuKey = $item['key'] ?? null;


                if (isset($item['children'])) {
                    $processMenu($item['children']);
                }


                if ($menuKey) {
                    if (!empty($item['permissions'])) {
                        $processPermissions($menuKey, $item['permissions'], 'static');
                    }


                    if (isset($item['link']['name']) && $item['link']['name'] === 'entity') {
                        $slug = $item['link']['params']['slug'] ?? $item['link']['slug'] ?? $item['entity'] ?? null;
                        if ($slug) {
                            $entity = get_entity($slug);
                            if ($entity) {
                                $processPermissions($menuKey, $entity->getPermissions(), 'entity');
                            }
                        }
                    }
                }
            }
        };


        $processMenu($menu);


        if (!empty($permissions)) {
            foreach ($permissions as $permission) {
                DB::table('cms_permissions')->updateOrInsert(
                    ['key' => $permission['key']],
                    $permission
                );
            }
        }

        return $permissions;
    }

    public function importForm($slug)
    {
        $entity = get_entity($slug);
 
        // Render a view with a file upload form
        return view('CMSView::pages.entity.import', ['slug' => $slug, 'entity' => $entity]);
    }

    // public function import(Request $request, $slug)
    // {
    //     $entity = get_entity($slug);

    //     $request->validate([
    //         'import_file' => 'required|file|mimes:csv',
    //     ]);

    //     $file = $request->file('import_file');

    //     $entityName = $entity->slug ?? $slug;
    //     $timestamp = now()->format('Ymd_His');
    //     $filename = "{$entityName}_{$timestamp}.csv";
    //     $folder = '/entity_imports/' . $entityName;

    //     // Store the file as CSV
    //     $path = $file->storeAs($folder, $filename);



    //     // Push a job


    //     // dd($path);
    //     // dispatch(new EntityImportFileJob($entity, $path));
    //     // dispatch(new \twa\cmsv2\Jobs\EntityImportFileJob($entity, $path));
    //     (new \twa\cmsv2\Jobs\EntityImportFileJob($entity, $path))->handle();

    //     return redirect(url("/cms/{$slug}"))
    //         ->with('success', "Import file uploaded as {$path}!");
    // }
}
