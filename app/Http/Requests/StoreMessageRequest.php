<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled by policies
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => 'required|string|max:5000',
            'type' => 'sometimes|in:text,image,file,system',
            'metadata' => 'sometimes|array',
            'metadata.file_url' => 'sometimes|string|url',
            'metadata.file_name' => 'sometimes|string|max:255',
            'metadata.file_size' => 'sometimes|integer|min:0',
            'metadata.mime_type' => 'sometimes|string|max:100',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Message content is required.',
            'content.max' => 'Message content cannot exceed 5000 characters.',
            'type.in' => 'Message type must be one of: text, image, file, system.',
            'metadata.file_url.url' => 'File URL must be a valid URL.',
        ];
    }
}
