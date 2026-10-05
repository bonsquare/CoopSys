<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Show the homepage.
     */
    public function index()
    {
        return view('home');
    }

    /**
     * Handle the form submission from the homepage.
     */
    public function generate(Request $request)
    {
        // If business_type is empty, Laravel sends the user back
        // to the form with an error message automatically.
        $validated = $request->validate([
            'business_type' => 'required|string|max:255',
        ]);

        // Dummy response for now (AI generation will be added later).
        return 'Generated successfully for: '.e($validated['business_type']);
    }
}
