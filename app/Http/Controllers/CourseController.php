<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * GET /courses — return all courses as JSON
     */
    public function index()
    {
        $courses = Course::orderBy('code')->get(['id', 'code', 'name']);

        return response()->json([
            'success' => true,
            'courses' => $courses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:courses,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $validated['code'] = strtoupper($validated['code']);

        $course = Course::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Course added successfully.',
            'course'  => $course,
        ]);
    }

    public function update(Request $request, Course $course)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', "unique:courses,code,{$course->id}"],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $validated['code'] = strtoupper($validated['code']);

        $course->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Course updated successfully.',
            'course'  => $course->fresh(),
        ]);
    }

    public function destroy(Course $course)
    {
        if ($course->alumni()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete: alumni records are linked to this course.',
            ], 422);
        }

        $course->delete();

        return response()->json([
            'success' => true,
            'message' => 'Course deleted successfully.',
        ]);
    }
}