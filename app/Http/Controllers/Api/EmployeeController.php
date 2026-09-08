<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query()->orderBy('display_name');

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'like', $search)
                    ->orWhere('full_name', 'like', $search)
                    ->orWhere('employee_code', 'like', $search)
                    ->orWhere('department', 'like', $search);
            });
        }

        return response()->json($query->paginate(20));
    }

    public function show(Employee $employee)
    {
        return response()->json($employee);
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50', 'unique:employees,employee_code,'.$employee->id],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $employee->update($data);

        return response()->json($employee);
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return response()->json(['message' => 'Employee deleted.']);
    }
}
