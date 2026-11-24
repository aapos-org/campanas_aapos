<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Contacts\ContactWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tags.
 */
class TagController extends Controller
{
    public function __construct(private readonly ContactWriter $writer) {}

    /**
     * List tags with how many contacts have each one.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Tag::query()->withCount('contacts')->orderBy('name')->get()
                ->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'contacts' => $t->contacts_count]),
        ]);
    }

    /**
     * Add tags to a contact (created if they do not exist).
     */
    public function attach(Request $request, Contact $contact): ContactResource
    {
        $data = $request->validate([
            'tags' => ['required', 'array', 'min:1', 'max:50'],
            'tags.*' => ['string', 'max:100'],
        ], [], ['tags' => 'etiquetas']);

        $this->writer->attachTags([$contact->id], array_values($data['tags']));

        return new ContactResource($contact->load(['tags:id,name', 'subscriptions']));
    }

    /**
     * Remove a tag from a contact.
     */
    public function detach(Contact $contact, Tag $tag): ContactResource
    {
        $this->writer->detachTags([$contact->id], [$tag->id]);

        return new ContactResource($contact->load(['tags:id,name', 'subscriptions']));
    }
}
