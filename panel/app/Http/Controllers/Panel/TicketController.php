<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Ticket;
use App\Services\Support\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $tickets = Ticket::where('user_id', $user->id)
            ->with(['server', 'department', 'assignee'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('panel.tickets.index', [
            'tickets' => $tickets,
            'unread' => Ticket::where('user_id', $user->id)->open()->sum('unread_by_user'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Ticket::class);

        return view('panel.tickets.create', [
            'departments' => $this->tickets->departments(),
            'servers' => $request->user()->servers()->orderBy('name')->get(),
            'priorities' => setting_array('hosting.support.tickets.priority_levels', ['low', 'normal', 'high', 'urgent']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:190'],
            'message' => ['required', 'string', 'min:20', 'max:10000'],
            'department_id' => ['nullable', 'integer', 'exists:ticket_departments,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'priority' => ['nullable', Rule::in((array) setting_array('hosting.support.tickets.priority_levels', ['low', 'normal', 'high', 'urgent']))],
        ]);

        // Сервер должен принадлежать пользователю
        if (! empty($data['server_id'])) {
            abort_if(! Server::where('user_id', $request->user()->id)->where('id', $data['server_id'])->exists(), 403);
        }

        $ticket = $this->tickets->create($request->user(), $data);

        return redirect()->route('panel.tickets.show', $ticket)
            ->with('success', __('support.messages.created', ['id' => $ticket->id]));
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $ticket->load(['messages' => fn ($q) => $q->visible($request->user()->isStaff())->oldest(), 'server', 'department', 'assignee']);

        $this->tickets->markRead($ticket, $request->user());

        return view('panel.tickets.show', [
            'ticket' => $ticket,
            'isStaff' => $request->user()->isStaff(),
            'canReply' => $request->user()->can('reply', $ticket),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'is_internal_note' => ['nullable', 'boolean'],
        ]);

        $isStaff = $request->user()->isStaff();

        $this->tickets->reply(
            $ticket,
            $request->user(),
            $data['body'],
            $isStaff && $request->boolean('is_internal_note'),
        );

        return back()->with('success', __('support.messages.replied'));
    }

    public function close(Ticket $ticket): RedirectResponse
    {
        $this->authorize('close', $ticket);

        $this->tickets->close($ticket, request()->user());

        return back()->with('success', __('support.messages.closed'));
    }

    public function reopen(Ticket $ticket): RedirectResponse
    {
        $this->authorize('close', $ticket);

        $this->tickets->reopen($ticket);

        return back()->with('success', __('support.messages.reopened'));
    }
}
