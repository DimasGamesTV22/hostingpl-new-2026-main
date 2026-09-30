<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\UserTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = UserTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        return view('panel.billing.transactions', [
            'transactions' => $query->paginate(30)->withQueryString(),
            'filters' => $request->only(['type', 'from', 'to']),
            'types' => UserTransaction::TYPES,
            'deposits' => Deposit::where('user_id', $user->id)->orderByDesc('created_at')->limit(10)->get(),
        ]);
    }

    public function export(Request $request): Response
    {
        $user = $request->user();

        $transactions = UserTransaction::where('user_id', $user->id)
            ->orderBy('created_at')
            ->limit(10000)
            ->get();

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Дата', 'Тип', 'Направление', 'Сумма', 'Баланс после', 'Описание', 'Источник'], ';');

        foreach ($transactions as $t) {
            fputcsv($handle, [
                $t->created_at?->format('Y-m-d H:i:s'),
                $t->type,
                $t->isCredit() ? 'приход' : 'расход',
                number_format((float) $t->amount, 2, ',', ''),
                number_format((float) $t->balance_after, 2, ',', ''),
                $t->title,
                $t->source,
            ], ';');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="gamedock-transactions-'.now()->format('Ymd').'.csv"',
        ]);
    }

    /**
     * Заявка на вывод средств (создаёт тикет в отдел поддержки).
     */
    public function withdraw(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.setting_float('hosting.billing.min_refund', 1)],
            'method' => ['required', 'string', 'max:64'],
            'details' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();

        if ((float) $user->balance < (float) $data['amount']) {
            return back()->with('error', __('billing.errors.insufficient_funds'));
        }

        $department = \App\Models\TicketDepartment::where('slug', 'billing')->first();

        app(\App\Services\Support\TicketService::class)->create($user, [
            'department_id' => $department?->id,
            'subject' => __('billing.withdraw.subject', ['amount' => money($data['amount'])]),
            'category' => 'withdraw',
            'priority' => 'high',
            'message' => "Способ: {$data['method']}\nРеквизиты: {$data['details']}\nСумма: ".money($data['amount']),
        ]);

        return back()->with('success', __('billing.messages.withdraw_requested'));
    }
}
