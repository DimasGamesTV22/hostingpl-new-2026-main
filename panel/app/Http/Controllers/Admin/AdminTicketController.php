<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Auditor;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminTicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $tickets = Ticket::with(['user:id,name,email', 'server:id,name', 'department', 'assignee:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->query('department'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_by_staff', '>', 0))
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderBy('last_reply_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'departments' => $this->tickets->departments(),
            'staff' => User::staff()->orderBy('name')->get(['id', 'name', 'role']),
            'filters' => $request->only(['status', 'priority', 'department', 'unread']),
            'counts' => [
                'open' => Ticket::where('status', Ticket::STATUS_OPEN)->count(),
                'unread' => Ticket::where('unread_by_staff', '>', 0)->count(),
                'mine' => Ticket::where('assigned_to', $request->user()->id)->open()->count(),
            ],
        ]);
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $ticket->load(['messages.user:id,name,email,role', 'user', 'server', 'department', 'assignee']);

        $this->tickets->markRead($ticket, $request->user());

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'staff' => User::staff()->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'is_internal_note' => ['nullable', 'boolean'],
        ]);

        $this->tickets->reply($ticket, $request->user(), $data['body'], $request->boolean('is_internal_note'));

        Auditor::log('admin.ticket_reply', 'Ответ в тикете #'.$ticket->id, $ticket);

        return back()->with('success', __('support.messages.replied'));
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->tickets->assign($ticket, $data['assigned_to'] ? User::find($data['assigned_to']) : null);

        return back()->with('success', __('admin.messages.ticket_assigned'));
    }

    public function setStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('close', $ticket);

        $data = $request->validate([
            'status' => ['required', Rule::in([Ticket::STATUS_OPEN, Ticket::STATUS_PENDING, Ticket::STATUS_ANSWERED, Ticket::STATUS_CLOSED])],
        ]);

        if ($data['status'] === Ticket::STATUS_CLOSED) {
            $this->tickets->close($ticket, $request->user());
        } else {
            $ticket->forceFill(['status' => $data['status'], 'closed_at' => null])->save();
        }

        return back()->with('success', __('admin.messages.ticket_status'));
    }
}
