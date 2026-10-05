<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vibe App</title>

    <!-- Tailwind CSS via CDN (no npm or Vite needed) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Our own CSS file from the public folder -->
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="max-w-2xl mx-auto px-4 py-16">

        <!-- System title -->
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Vibe App</h1>

        <!-- Short description -->
        <p class="text-gray-600 mb-8">
            Describe your business and we'll generate a system for it.
        </p>

        <!-- Form for future AI generation -->
        <form method="POST" action="{{ url('/generate') }}" class="app-card bg-white rounded-lg p-6">
            @csrf

            <label for="business_type" class="block text-sm font-medium text-gray-700 mb-2">
                Business Type
            </label>

            <input
                type="text"
                id="business_type"
                name="business_type"
                value="{{ old('business_type') }}"
                placeholder="Example: perfume store, procurement system, accounting system"
                class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >

            <!-- Validation error message -->
            @error('business_type')
                <p class="text-red-600 text-sm mt-2">{{ $message }}</p>
            @enderror

            <button
                type="submit"
                class="mt-4 bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-md"
            >
                Generate
            </button>
        </form>

    </div>

</body>
</html>
