<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\User\DTOs\EmployeeData;
use Modules\User\Http\Requests\ListEmployeesRequest;
use Modules\User\Http\Requests\StoreEmployeeRequest;
use Modules\User\Http\Requests\UpdateEmployeeRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Services\EmployeeService;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
    ) {}

    // Returned directly (not wrapped in response()->json()) so Laravel's
    // Responsable handling adds the cursor "links"/"meta" pagination envelope.
    public function index(ListEmployeesRequest $request): AnonymousResourceCollection
    {
        $employees = $this->employees->listFor($request->user(), $request->validated());

        return UserResource::collection($employees);
    }

    public function show(Request $request, User $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return response()->json(new UserResource($employee->load('department')));
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->create(EmployeeData::fromArray($request->validated()));

        return response()->json(new UserResource($employee), 201);
    }

    public function update(UpdateEmployeeRequest $request, User $employee): JsonResponse
    {
        $employee = $this->employees->update($request->user(), $employee, $request->validated());

        return response()->json(new UserResource($employee));
    }

    public function destroy(Request $request, User $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $this->employees->delete($employee);

        return response()->json(['message' => 'Employee removed.']);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load('department')));
    }
}
