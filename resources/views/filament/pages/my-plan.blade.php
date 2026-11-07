@php
    use App\Enums\SubscriptionStatus;
    use App\Support\Billing\Money;

    $grid = 'display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr))';
    $muted = 'color:rgb(107 114 128);font-size:.875rem';
@endphp

<x-filament-panels::page>
    {{-- Current plans --}}
    <x-filament::section heading="Your plans" icon="heroicon-o-check-badge">
        @if ($subscriptions->isEmpty())
            <p style="{{ $muted }}">You don't have a plan yet — choose one below.</p>
        @else
            <div style="{{ $grid }}">
                @foreach ($subscriptions as $subscription)
                    @php($status = $subscription->displayStatus())
                    <div style="border:1px solid rgb(229 231 235 / .8);border-radius:.75rem;padding:1rem;display:flex;flex-direction:column;gap:.5rem">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                            <strong>{{ $subscription->plan->name }}</strong>
                            <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}</x-filament::badge>
                        </div>
                        <div style="{{ $muted }}">{{ $subscription->plan->priceLabel() }}</div>

                        <div style="{{ $muted }}">
                            @if ($status === SubscriptionStatus::Pending)
                                Waiting for payment confirmation.
                            @elseif ($status === SubscriptionStatus::Trialing && $subscription->trial_ends_at)
                                Trial ends {{ $subscription->trial_ends_at->toFormattedDateString() }}.
                            @elseif ($subscription->inGracePeriod())
                                Ended {{ $subscription->ends_at->toFormattedDateString() }} — renew to keep access.
                            @elseif ($status === SubscriptionStatus::Canceled && $subscription->ends_at?->isFuture())
                                Canceled — access until {{ $subscription->ends_at->toFormattedDateString() }}.
                            @elseif ($subscription->ends_at)
                                {{ $subscription->ends_at->isFuture() ? 'Renews / ends' : 'Ended' }} {{ $subscription->ends_at->toFormattedDateString() }}.
                            @elseif ($subscription->isActive())
                                No end date.
                            @endif
                        </div>

                        @if ($subscription->hasPendingPayment() && $status !== SubscriptionStatus::Pending)
                            <div style="{{ $muted }}">Renewal payment waiting for confirmation.</div>
                        @endif

                        <div style="display:flex;gap:.75rem;align-items:center;margin-top:auto;padding-top:.25rem">
                            @if ($subscription->canRenew() && $bankAvailable)
                                {{ ($this->renewAction)(['subscription' => $subscription->id]) }}
                            @endif
                            @if (in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true) && $subscription->isActive())
                                {{ ($this->cancelAction)(['subscription' => $subscription->id]) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if (count($usage))
            <div style="margin-top:1.5rem;display:flex;flex-direction:column;gap:.75rem">
                <strong>Usage</strong>
                @foreach ($usage as $meter)
                    @php($percent = $meter['limit'] ? min(100, round($meter['used'] / max(1, $meter['limit']) * 100)) : 0)
                    <div>
                        <div style="display:flex;justify-content:space-between;{{ $muted }}">
                            <span>{{ $meter['meter']->name }}</span>
                            <span>
                                {{ number_format($meter['used']) }}
                                / {{ $meter['limit'] === null ? 'unlimited' : number_format($meter['limit']) }} {{ str($meter['meter']->unit)->plural() }}
                                @if ($meter['limit'] !== null && $meter['used'] > $meter['limit'] && $meter['overage'])
                                    · {{ number_format($meter['used'] - $meter['limit']) }} extra × {{ Money::format($meter['unit_price']) }}
                                @endif
                            </span>
                        </div>
                        @if ($meter['limit'] !== null)
                            <div style="height:.5rem;border-radius:9999px;background:rgb(229 231 235 / .7);overflow:hidden;margin-top:.25rem">
                                <div style="height:100%;width:{{ $percent }}%;background:{{ $percent >= 100 ? 'rgb(220 38 38)' : 'rgb(245 158 11)' }}"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Available plans --}}
    <x-filament::section heading="Available plans" icon="heroicon-o-rectangle-group">
        @if ($plans->isEmpty())
            <p style="{{ $muted }}">No other plans available right now.</p>
        @else
            <div style="{{ $grid }}">
                @foreach ($plans as $plan)
                    <div style="border:1px solid {{ $plan->is_featured ? 'rgb(245 158 11)' : 'rgb(229 231 235 / .8)' }};border-radius:.75rem;padding:1rem;display:flex;flex-direction:column;gap:.5rem">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                            <strong>{{ $plan->name }}</strong>
                            @if ($plan->is_featured)
                                <x-filament::badge color="warning">Popular</x-filament::badge>
                            @endif
                        </div>
                        <div style="font-size:1.25rem;font-weight:600">{{ $plan->priceLabel() }}</div>
                        @if ($plan->trial_days && $billing->trialAvailable(auth()->user(), $plan))
                            <div style="{{ $muted }}">{{ $plan->trial_days }}-day free trial</div>
                        @endif
                        @if ($plan->description)
                            <div style="{{ $muted }}">{{ $plan->description }}</div>
                        @endif
                        @if ($plan->features)
                            <ul style="list-style:disc;padding-left:1.25rem;font-size:.875rem">
                                @foreach ($plan->features as $feature)
                                    <li>{{ $feature }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <div style="margin-top:auto;padding-top:.5rem">
                            @if ($billing->startsWithoutPayment(auth()->user(), $plan))
                                {{ ($this->startAction)(['plan' => $plan->id]) }}
                            @elseif ($bankAvailable)
                                {{ ($this->payAction)(['plan' => $plan->id]) }}
                            @else
                                <span style="{{ $muted }}">Payment options coming soon.</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Payment history --}}
    @if ($payments->isNotEmpty())
        <x-filament::section heading="Payments" icon="heroicon-o-banknotes" collapsible>
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:left;{{ $muted }}">
                            <th style="padding:.5rem">Reference</th>
                            <th style="padding:.5rem">Plan</th>
                            <th style="padding:.5rem">Amount</th>
                            <th style="padding:.5rem">Status</th>
                            <th style="padding:.5rem">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr style="border-top:1px solid rgb(229 231 235 / .6)">
                                <td style="padding:.5rem;font-family:monospace">{{ $payment->reference }}</td>
                                <td style="padding:.5rem">{{ $payment->plan?->name }} <span style="{{ $muted }}">· {{ $payment->kind->getLabel() }}</span></td>
                                <td style="padding:.5rem">{{ $payment->formattedAmount() }}</td>
                                <td style="padding:.5rem">
                                    <x-filament::badge :color="$payment->status->getColor()">{{ $payment->status->getLabel() }}</x-filament::badge>
                                    @if ($payment->review_note)
                                        <div style="{{ $muted }};margin-top:.25rem">{{ $payment->review_note }}</div>
                                    @endif
                                </td>
                                <td style="padding:.5rem">{{ $payment->created_at->toFormattedDateString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
