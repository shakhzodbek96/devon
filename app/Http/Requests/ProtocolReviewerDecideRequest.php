<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /protocols/{uuid}/reviewer-decide` — structural validation only.
 * No ERI here (PLAN §5 — reviewers just confirm "no objection", drawio has
 * no ERI at this station). `not-reviewer`/`already-decided`/`wrong-status`
 * live in `ProtocolService::reviewerDecide`.
 */
class ProtocolReviewerDecideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'approve' => ['required', 'boolean'],
            'note' => ['required_if:approve,false', 'nullable', 'string', 'max:1000'],
        ];
    }
}
