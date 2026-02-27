<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CourseController extends Controller
{
    /**
     * Get all courses as JSON
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'courses' => Course::orderBy('code')->get(),
        ]);
    }

    /**
     * Store a new course
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'code' => ['required', 'string', 'max:50', 'unique:courses,code'],
                'name' => ['required', 'string', 'max:255'],
            ]);

            $validated['code'] = strtoupper($validated['code']);

            $course = Course::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Course added successfully!',
                'course' => $course,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Course store failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to add course: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a course
     */
    public function update(Request $request, Course $course)
    {
        try {
            $validated = $request->validate([
                'code' => ['required', 'string', 'max:50', 'unique:courses,code,' . $course->id],
                'name' => ['required', 'string', 'max:255'],
            ]);

            $validated['code'] = strtoupper($validated['code']);

            $course->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Course updated successfully!',
                'course' => $course,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Course update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update course: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a course
     */
    public function destroy(Course $course)
    {
        try {
            $course->delete();

            return response()->json([
                'success' => true,
                'message' => 'Course deleted successfully!',
            ]);
        } catch (\Exception $e) {
            Log::error('Course delete failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete course: ' . $e->getMessage(),
            ], 500);
        }
    }
}