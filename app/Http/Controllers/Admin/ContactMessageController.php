<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(Request $request)
    {
        $query = ContactMessage::latest();

        if ($request->filter === 'unread') {
            $query->where('is_read', false);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")
                  ->orWhere('subject', 'like', "%$s%")
                  ->orWhere('message', 'like', "%$s%")
            );
        }

        $messages    = $query->paginate(20)->withQueryString();
        $unreadCount = ContactMessage::where('is_read', false)->count();
        $totalCount  = ContactMessage::count();

        $msgDataForJs = $messages->keyBy('id')->map(function ($m) {
            return [
                'id'      => $m->id,
                'name'    => $m->name,
                'email'   => $m->email,
                'phone'   => $m->phone,
                'subject' => $m->subject,
                'message' => $m->message,
                'date'    => $m->created_at->format('M d, Y \a\t H:i'),
                'is_read' => (bool) $m->is_read,
            ];
        });

        return view('admin.contact-messages.index', compact('messages', 'unreadCount', 'totalCount', 'msgDataForJs'));
    }

    public function toggleRead(Request $request, ContactMessage $message)
    {
        $newState = !$message->is_read;
        $message->update(['is_read' => $newState]);

        if ($request->expectsJson()) {
            return response()->json(['is_read' => $newState]);
        }

        return back()->with('success', 'Message marked as ' . ($newState ? 'read' : 'unread') . '.');
    }

    public function destroy(ContactMessage $message)
    {
        $message->delete();
        return redirect()->route('admin.contact-messages.index')->with('success', 'Message deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = ContactMessage::count();
            ContactMessage::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('message_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.contact-messages.index')->with('error', 'No messages selected.');
            }
            $count = ContactMessage::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.contact-messages.index')
            ->with('success', $count . ' message' . ($count === 1 ? '' : 's') . ' deleted.');
    }
}
