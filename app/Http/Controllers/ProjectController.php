<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use App\Models\Project;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sortField = request("sort_field", 'created_at');
        $sortDirection = request("sort_direction", "desc");
        $name = request("name");
        $status = request("status");
    
        $query = "SELECT projects.id, 
                         projects.name, 
                         projects.description, 
                         projects.created_at, 
                         projects.due_date, 
                         projects.status, 
                         projects.image_path, 
                         created_users.name as created_by_name, 
                         updated_users.name as updated_by_name 
                  FROM projects 
                  LEFT JOIN users as created_users ON projects.created_by = created_users.id 
                  LEFT JOIN users as updated_users ON projects.updated_by = updated_users.id 
                  WHERE projects.deleted_at IS NULL";
    
        if ($name) {
            $query .= " AND projects.name LIKE '%" . $name . "%'";
        }
        if ($status) {
            $query .= " AND projects.status = '" . $status . "'";
        }
    
        $query .= " ORDER BY " . $sortField . " " . $sortDirection;
    
        $projects = DB::select($query);
    
        // Paginate the results
        $currentPage = Paginator::resolveCurrentPage('page');
        $perPage = 10;
        $currentItems = array_slice($projects, ($currentPage - 1) * $perPage, $perPage);
        $paginatedProjects = new LengthAwarePaginator($currentItems, count($projects), $perPage, $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    
        return inertia("Project/Index", [
            "projects" => ProjectResource::collection($paginatedProjects),
            'queryParams' => request()->query() ?: null,
            'success' => session('success'),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return inertia("Project/Create");
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request)
    {
        $data = $request->validated();
        
        // Remove the 'image' key if it exists
        unset($data['image']);
        
        /** @var $image \Illuminate\Http\UploadedFile */
        $image = $request->file('image');
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();
        
        // Set created_at and updated_at
        $data['created_at'] = now();
        $data['updated_at'] = now();
        
        if ($image) {
            $imagePath = $image->store('project/' . Str::random(), 'public');
            $data['image_path'] = $imagePath;
        }
        
        DB::table('projects')->insert($data);
        
        return redirect()->route('project.index')
            ->with('success', 'Project was created');
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        $sortField = request("sort_field", 'created_at');
        $sortDirection = request("sort_direction", "desc");
        $name = request("name");
        $status = request("status");

        // Ensure due_date is included in the select statement
        $query = "SELECT projects.id, 
                        projects.name, 
                        projects.description, 
                        projects.created_at, 
                        projects.due_date, 
                        projects.status, 
                        projects.image_path, 
                        created_users.name as created_by_name, 
                        updated_users.name as updated_by_name 
                FROM projects 
                LEFT JOIN users as created_users ON projects.created_by = created_users.id 
                LEFT JOIN users as updated_users ON projects.updated_by = updated_users.id 
                WHERE projects.deleted_at IS NULL
                    AND projects.id = ?";
        
        $projectDetails = DB::selectOne($query, [$project->id]);
        
        if (!$projectDetails) {
            abort(404, 'Project not found');
        }

        // Fetch tasks related to this project
        $queryTasks = "SELECT tasks.id,
                      tasks.name,
                      tasks.description,
                      tasks.created_at,
                      tasks.updated_at,
                      tasks.status,
                      tasks.project_id,
                      tasks.created_by,
                      tasks.updated_by,
                      tasks.due_date,
                      tasks.priority,
                      tasks.image_path,
                      created_users.name as created_by_name,
                      updated_users.name as updated_by_name,
                      projects.name as project_name  
               FROM tasks
               LEFT JOIN users as created_users ON tasks.created_by = created_users.id
               LEFT JOIN users as updated_users ON tasks.updated_by = updated_users.id
               LEFT JOIN projects ON tasks.project_id = projects.id
               WHERE tasks.project_id = ?";
        
        if ($name) {
            $queryTasks .= " AND tasks.name LIKE '%" . $name . "%'";
        }
        if ($status) {
            $queryTasks .= " AND tasks.status = '" . $status . "'";
        }
        
        $queryTasks .= " ORDER BY " . $sortField . " " . $sortDirection;

        $tasks = DB::select($queryTasks, [$project->id]);

        // Paginate the results
        $currentPage = Paginator::resolveCurrentPage('page');
        $perPage = 10;
        $currentItems = array_slice($tasks, ($currentPage - 1) * $perPage, $perPage);
        $paginatedTasks = new LengthAwarePaginator($currentItems, count($tasks), $perPage, $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
        ]);

        return inertia('Project/Show', [
            'project' => new ProjectResource((object) $projectDetails), // Cast to object to ensure compatibility
            'tasks' => TaskResource::collection($paginatedTasks),
            'queryParams' => request()->query() ?: null,
            'success' => session('success'),
        ]);
    }
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        return inertia('Project/Edit', [
            'project' => new ProjectResource($project),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project)
    {
        $validatedData = $request->validated();
        $image = $request->file('image');
        
        // Remove 'image' from $validatedData because it's not a database column
        unset($validatedData['image']);
    
        // Handle image upload if a new image is provided
        if ($image) {
            // Delete old image if it exists
            if ($project->image_path) {
                Storage::disk('public')->delete($project->image_path);
            }
    
            // Store the new image
            $imagePath = $image->store('project/' . Str::random(), 'public');
            $validatedData['image_path'] = $imagePath;
        }
    
        // Add updated_by field
        $validatedData['updated_by'] = Auth::id();
    
        // Update the project record in the database
        $project->update($validatedData);
    
        return redirect()->route('project.index')
            ->with('success', "Project \"$project->name\" was updated");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        $name = $project->name;
        
        try {
            DB::table('projects')->where('id', $project->id)->update(['deleted_at' => now()]);
            if ($project->image_path) {
                Storage::disk('public')->deleteDirectory(dirname($project->image_path));
            }
            return to_route('project.index')
                ->with('success', "Project \"$name\" was deleted");
        } catch (QueryException $e) {
            if($e->getCode() == '23000') { 
                return to_route('project.index')
                    ->with('error', "Project \"$name\" cannot be deleted because it has associated tasks.");
            }
            
            throw $e;
        }
    }
}
