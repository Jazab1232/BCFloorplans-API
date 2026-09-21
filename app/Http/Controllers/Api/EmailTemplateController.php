<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $templates = EmailTemplate::orderBy('sort_order')->orderBy('title')->get();
        return response()->json([
            'success' => true,
            'data' => $templates
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'nullable|string|max:100',
            'event_type' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $template = EmailTemplate::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Email template created successfully',
            'data' => $template
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();
        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'type' => 'nullable|string|max:100',
            'event_type' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $template->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully',
            'data' => $template
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();
        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email template deleted successfully'
        ]);
    }

    /**
     * Preview a template with dummy data.
     */
    public function preview(Request $request, $uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();
        $data = $request->input('data', []);

        $parsedContent = $template->parseContent($data);

        return response()->json([
            'success' => true,
            'data' => [
                'title' => $template->title,
                'parsed_content' => $parsedContent,
                'original_content' => $template->content
            ]
        ]);
    }
}
