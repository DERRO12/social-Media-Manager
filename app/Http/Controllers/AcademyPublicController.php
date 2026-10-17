<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public (no login) Academy pages: the catalog, course outlines with free previews, and certificate verification. */
class AcademyPublicController extends Controller
{
    public function index(): View
    {
        return view('academy.public.index', [
            'courses' => Course::published()->withCount('lessons')->with('lessons:id,course_id,duration_minutes')->get(),
        ]);
    }

    public function show(Request $request, Course $course): View
    {
        // Drafts are visible to the admin only, so they can check the page before publishing.
        abort_unless($course->is_published || $request->user()?->isAdmin(), 404);

        return view('academy.public.show', [
            'course' => $course->loadCount('questions'),
            'lessons' => $course->orderedLessons(),
            'modules' => $course->modules()->get(),
            'previews' => $course->orderedLessons()->where('is_preview', true)->values(),
            'certifiedCount' => $course->certificates()->whereNull('revoked_at')->count(),
        ]);
    }

    public function certificate(Certificate $certificate): View
    {
        return view('academy.certificate', ['certificate' => $certificate->load('course')]);
    }
}
