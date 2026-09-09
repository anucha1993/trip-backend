<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = LeaveRequest::with('employee')->orderByDesc('date');

        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->string('month'));
            $query->whereYear('date', $year)->whereMonth('date', $month);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', 'in:sick,personal,other'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $leave = LeaveRequest::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            ['type' => $data['type'], 'note' => $data['note'] ?? null]
        );

        return response()->json($leave->load('employee'), 201);
    }

    public function destroy(LeaveRequest $leaveRequest)
    {
        $leaveRequest->delete();

        return response()->json(['message' => 'Leave record deleted.']);
    }
}
